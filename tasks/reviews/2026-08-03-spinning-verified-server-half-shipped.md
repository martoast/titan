# VERIFIED · and the server half is shipped with it — SPINNING was unreachable from the coach

**Reviewing:** `7bd3036` fix(firmware): a gym spin bike is not a road ride — map it to SPORT_TYPE_SPINNING
**Reviewer:** Henry (server) · 2026-08-03

The mapping is right and the GPS discriminator is a genuinely clean choice. But the firmware branch
it adds could not be reached from the coach at all — the server was aliasing every word that means
"spin bike" into road cycling. That half was mine, so it ships in this commit rather than as a
request.

---

## Verified

`SPORT_TYPE_SPINNING = 0x12` (动感单车) and `SPORT_TYPE_RIDE_BIKE = 0x02` (骑行) both confirmed in
`algo.h` — two distinct vendor models, and we only ever asked for the road one. `0x12` is 18 decimal,
matching the commit's stated `dbg().hrmSportMode` expectation.

**The GPS discriminator is the right instrument.** It uses data already carried rather than inventing
a setting, and it is honest: an outdoor ride wants a route, a gym bike does not. `primed` is
guaranteed non-null on that branch (`workoutKind()` only returns `"cycle"` when `primed.type` is a
bike variant), so `primed && primed.gps` cannot fault.

**The complement to `0bf129a` is real.** On a spin bike the wrist sits still on the bars for the
whole session — the exact still-but-working posture that latched `hrmAlgoModeFor` into normal mode on
the 2026-08-03 ride. Asking for the model built for that posture alongside the `hrLockFresh` fix is
coherent, not redundant.

Being explicit that SPINNING-vs-FREE_TRAINING is **unknown** rather than claimed is the right
posture — the algorithm is a proprietary blob and neither of us can measure it from here.

---

## The gap: nothing could request it (fixed here)

`ActivityPriming` aliased the indoor words straight into road cycling, and `cycle` ships `gps: true`:

```php
'spin' => 'cycle', 'spinning' => 'cycle', 'peloton' => 'cycle',
'cycle' => [... 'gps' => true],
```

So *"starting a spin session"* → `cycle` → `gps: true` → firmware takes the `primed.gps` branch →
**RIDE_BIKE**. The words that most clearly mean "spin bike" were precisely the ones that guaranteed
the road model, and the new SPINNING branch was dead code from the coach's side. It also powered the
GPS receiver indoors for a fix it would never get.

**Shipped in this commit (server side, tested):**

- New `spin` profile — `gps: false`, `accel_hz: 6.25` (same as cycling; the wrist is static on the
  bars either way).
- Indoor aliases re-pointed to `spin`: `spin bike`, `stationary bike`, `exercise bike`, `indoor
  bike`, `indoor cycling`, `assault bike`, `spinning`, `peloton`, `stationary`.
- `start_activity`'s tool description now tells the coach that a gym/stationary bike is `spin`, not
  `cycle`, and why — otherwise the model has no way to know the distinction matters.

**A trap I fell into while writing it, now pinned by a test.** `normalize()` falls back to a
`str_contains` scan in **insertion order**, and every indoor phrase contains `"bike"` or `"cycling"`.
My first version listed the outdoor aliases first, so `"spin bike"` matched `bike` and resolved to
road cycling — reintroducing the exact bug I was fixing. The specific forms must precede the generic
ones, and `SpinBikePrimingTest` now asserts all twelve phrasings.

**A bare `"bike"` deliberately stays ROAD.** The wrong guess is asymmetric: a road model on a spin
bike costs some HR accuracy, while spin on a real ride costs the route entirely. Alex saying *"when I
say bike I'm really riding the stationary bikes"* is a fact about him, not about the word — so the
fix is that "spin bike"/"stationary bike" resolve correctly, not that "bike" changes meaning. If he
wants a bare "bike" to mean spin for his profile specifically, that is a per-profile default and a
different change; say so and I'll add it.

**One existing assertion changed**, deliberately: `CoachActivityPrimingTest` asserted
`normalize('Peloton spin') === 'cycle'`. That pinned the behaviour being fixed. It now expects
`'spin'`, with a comment saying why and pointing at the new test.

Full suite: 742 passing, failure set identical to the HEAD baseline (all environment-dependent).

---

## What this does and does not change for Alex

| how he starts it | primed type | `dbg().hrmSportMode` |
|---|---|---|
| GYM face on the watch | `strength` | 25 (FREE_TRAINING) — **unchanged** |
| tells the coach "spin bike" | `spin` | **18 (SPINNING)** — new, previously impossible |
| tells the coach "bike ride" | `cycle` | 2 (RIDE_BIKE) |

So his habitual workflow — starting from the watch — still yields FREE_TRAINING. That is a reasonable
generic and the commit says so. **The watch still cannot declare an activity**, which is the same
limitation I flagged as P1 last round; `7bd3036` narrows what it costs (a spin session is no longer
mis-modelled as a road ride) without removing it. A long-press activity picker on the GYM face would
close it for spin, row and boxing at once, and is worth more than any further per-sport mapping.

## Status

Five firmware changes stacked, **none validated on-device**. Unchanged acceptance signal: on the
ride, `ppgRecoveries` stays 0 and HR tracks effort. If Alex wants the spin model on that ride, he
should start it by telling the coach "spin bike" rather than pressing GYM — that now works, and it
also seals the session as `spin` instead of a twenty-first strength workout.
