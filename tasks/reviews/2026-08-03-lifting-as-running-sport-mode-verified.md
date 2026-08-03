# VERIFIED · proceed — with one gap: walk / hike / row / swim still model as RUNNING

**Reviewing:** `d70d462` fix(firmware): workout HR over-reads under rhythmic motion — stop modelling lifting as RUNNING
**Reviewer:** Henry (server) · 2026-08-03

The diagnosis is right, the vendor enum says what the commit says it says, and — the thing most
likely to have silently killed this fix — Espruino does **not** clamp the value, so `0x19` really
reaches the algorithm. Verified against upstream source, not the commit message.

---

## Verified

| claim | verified |
|---|---|
| `SPORT_TYPE_RUNNING = 0x01` — mode 1 is *running specifically*, not a "general sport" profile | ✅ `libs/misc/vc31_binary/algo.h:11` (跑步) — the old firmware comment calling it "the motion-tolerant general profile" was simply wrong |
| `SPORT_TYPE_FREE_TRAINING = 0x19` exists and is the gym model | ✅ `algo.h:35` (自由训练) |
| RUNNING is the vendor's documented fallback for unlisted sports | ✅ the enum's trailing comment: 未出现在上表中的运动形式均先以跑步输入 — *"sports not in the table above are input as running"* |
| `Bangle.dbg().hrmSportMode` reports the ACTUAL mode | ✅ `jswrap_bangle.c:5087`, whose own comment says *"different to getOptions().hrmSportMode which is what we're requesting"* — exactly the right verification instrument |

**The risk the commit didn't flag, and the one I'd have bet on breaking it:** whether Espruino
validates `hrmSportMode` to the 0–2 range the docs advertise. It doesn't. The option parses as a
plain `JSV_INTEGER` (2933) into `int8_t hrmSportMode` (801), and the use site is
`if (hrmSportMode>=0) hrmInfo.sportMode = hrmSportMode;` (1661–62) — **no upper bound anywhere**, and
25 fits an int8_t comfortably. So the fix will actually take effect rather than silently no-op.

**The physiology holds independently.** A sustained ~1.7 bpm/s rise for a minute, and a 54 bpm
one-minute recovery, are both outside human range — 1-minute HRR above ~40 bpm is not observed even
in elite athletes after maximal work, and this was *light* shadow boxing. Cadence, not pulse. The
observation that the confidence gate cannot catch this by construction is correct and worth keeping
in mind: conf reports certainty about the periodicity it locked onto, not about it being cardiac.

---

## Gap: four more activities still model as RUNNING

`hrmAlgoSportFor()` maps `cycle → BIKE`, `run → RUN`, `strength → FREE_TRAINING`, everything else →
`RUN`. But `workoutKind()` can return **`walk`, `hike`, `row`, `swim`** (they pass straight through
from `primed.type` at lines 5–6 of that function), and all four land on the fallback — even though
the vendor enum has dedicated modes for every one of them:

| kind | currently sent | available in algo.h |
|---|---|---|
| `walk` | RUNNING (0x01) | `SPORT_TYPE_WALKING = 0x09` (徒步) |
| `hike` | RUNNING (0x01) | `SPORT_TYPE_CLIMBING = 0x08` (爬山) |
| `row` | RUNNING (0x01) | `SPORT_TYPE_BOATING = 0x17` (划船) |
| `swim` | RUNNING (0x01) | `SPORT_TYPE_SWIMMING = 0x04` (游泳) |

**Rowing is the sharp one** — it is rhythmic arm movement at a fixed stroke rate, which is precisely
the failure class this commit just fixed, and it is currently pointed at the model that assumes wrist
cadence tracks heart rate. The fallback is right for genuinely unlisted activities; these four are
not unlisted, they are just unmapped.

Not a blocker — none of them is Alex's daily driver, and the lift/boxing fix stands on its own. But
it is a two-line extension of the map you just wrote, and it closes the same bug for four more cases.

## What I could NOT verify — and a loose thread

**The primary evidence is not in my database.** Today's `hr_samples` for profile 1: 237 rows,
09:36–17:20, **bpm range 45–110**, steepest rise 0.58 bpm/s, steepest fall −0.67 bpm/s. There is no
58→159 ramp anywhere, and nothing at or above 140.

The conclusion doesn't depend on it — the `algo.h` argument is self-supporting — so this is not a
challenge to the fix. But it leaves a thread worth pulling: if that 159 bpm at confidence 100
occurred during a streaming workout, it cleared the `publishConfWorkout = 60` gate by a wide margin
and should have been published and synced. Its absence from the server means either the capture was a
bench test outside a workout/streaming session (most likely — a `dbg()`/profiler reading rather than
the publish path), or HR from that session never reached the server, which would be a second
independent problem. Worth one glance at how that capture was taken.

## On the proposed cadence-harmonic rejection

Endorsed, and it is mine to build — it is server-side and I have the inputs: `accel_mag_cg` ships in
the same `ppg_raw` window as the waveform (confirmed on the 2026-08-03 window: 2929 accel samples
alongside 2929 PPG samples at 24 Hz), so biosignal can estimate the dominant motion frequency per
window and down-weight a bpm sitting on its 0.5×/1×/2× harmonic. That is the durable fix: it catches
this failure whatever sport mode is selected, and unlike the sport-mode map it does not need a
reflash to change.

I'd rather not start it before the ride, for one reason: **the same accel data will tell us whether
the harmonic filter would have fired on the real capture**, and right now I don't have a window
containing the bad reading to test against. If the shadow-boxing session can be re-run *while
streaming* so it lands as a `ppg_raw` window, I can develop the filter against a real positive case
instead of a synthetic one. That is worth more than starting early.

## Status

Firmware is now three fixes deep with **none of them validated on-device**: the wear-detect root
cause (`f2a9746`), the still-wrist gate (`0bf129a`), and this sport-mode map. They are independent
and all three land in the same reflash. The ride tests all three at once:

- HR tracks effort on a **ride** → wear-detect fix + still-wrist gate
- HR stays plausible during a **lift** → this fix (`Bangle.dbg().hrmSportMode` should read **25**)
- `ppgRecoveries` stays **0** → the trigger is gone rather than being caught

Band is 2v29 per the boot banner, so every option used here exists.
