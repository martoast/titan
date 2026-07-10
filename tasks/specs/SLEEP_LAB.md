# SLEEP LAB — the night simulator that makes lost nights impossible

**Spec for the dev agent · from Alex + Henry · 2026-07-10**
**Status: BUILD THIS NEXT.** The seal pipeline is finally honest; this is what keeps it that way forever.

---

## 1 · Why this exists (read this part like it matters, because it does)

Sleep is the product. Not a feature — *the* product. A person wore our band to bed, trusted us with
eight unconscious hours of their life, and woke up wanting one thing: **the true story of their night,
beautifully told, every single morning, without exception.**

We just spent five days and nine review passes discovering the ways we broke that promise: nights lost
to timezone math, nights eaten by replay markers, nights that spun forever behind a loading card, nights
scored 100% awake because gravity leaked into a motion sum. Every one of those bugs shipped because we
could only *reason* about the pipeline — we couldn't *rehearse* it.

Whoop doesn't out-engineer us. They out-*rehearse* us. Their pipeline has seen millions of nights.
Ours has seen a handful of Alex's. SLEEP LAB closes that gap synthetically: **a virtual band that can
live any night we can describe — and a harness that proves the whole system tells the truth about it.**

The bar, stated as product law:

> **THE FIVE PROMISES**
> 1. **Never lost.** A worn night ALWAYS produces a row. Failure parks, never destroys.
> 2. **Never fabricated.** We never present time we didn't measure as sleep, and never present garbage as stages.
> 3. **Never split or smeared.** One night is one row. A charge gap, a BLE drop, a slow sync — still one night.
> 4. **Never stale.** The morning story arrives on time, once, computed from THIS night — or honestly says "still working."
> 5. **Every surface agrees.** The card, the coach, readiness, trends — one night, one truth, everywhere.

Every scenario in this spec exists to defend a promise. Every future seal commit must pass the LAB
before it ships. When someone proposes a pipeline change, the answer to "is it safe?" stops being a
review debate and becomes: **run the LAB.**

## 2 · The architecture (three pieces, sharp edges)

```
NightScript (ground truth)  →  VirtualBand (renders truth into wire reality)  →  REAL ingest API
                                                                                       ↓
Assertions ← every layer: DB rows · seal decisions · readers · notifications · mobile payloads
```

### 2.1 NightScript — describe a night like a screenwriter
A declarative PHP object (or YAML) describing the TRUE night: bed/wake, sleep architecture as
stage blocks (`deep 40m → light 20m → rem 25m → ...`), wake bouts, and the *device story* layered on
top — duty-cycle cadence, charge gaps, BLE drops with buffered replay, marker timing (live / delayed /
replayed / absent), clock drift, timezone. The script IS the expected outcome: assertions derive from
it, never hand-written numbers.

### 2.2 VirtualBand — the firmware's method actor
Extends `BiosignalSimulator` (do NOT build a second simulator — the reviews bled for this). Renders a
NightScript into the exact wire format the real band produces: 30s duty-cycle `ppg_raw` windows with
IBI/accel signals *generated from the stage blocks* (deep = low motion + low HR + high HRV; REM = still
+ variable HR; wake = movement bursts), HMAC-signed batches to `/api/devices/ingest`, T9 markers,
store-and-forward timing (buffered windows arrive late with original timestamps, `created_at` skew and
all). Signal generation is calibrated (§3). If the firmware and the VirtualBand ever disagree about the
wire format, that's a P0 bug in one of them.

### 2.3 The harness — `php artisan sleep:lab`
```
sleep:lab                      # run the full scenario suite against this stack, report a scorecard
sleep:lab --scenario=charge-gap --tz=America/Mexico_City
sleep:lab --chaos              # inject service restarts/5xx/deploy-skew mid-scenario
sleep:lab --calibrate          # refresh generator params from real sealed nights
```
Runs on a dedicated lab profile (never a real user), drives real queue workers + the real biosignal
service, advances time via test-clock hooks where needed, and asserts at every layer. Output is a
scorecard: scenario × promise → PASS/FAIL with the exact divergence. CI runs the deterministic subset;
the full LAB runs on the server after every seal deploy (Henry's post-commit check becomes: run the LAB).

## 3 · Calibration — synthetic nights indistinguishable from Alex's
`sleep:lab --calibrate` extracts from real sealed nights (profile 1's recovered week to start): stage
proportions and cycle structure (real: deep 14–17%, REM 29–40%, light ~50%, eff 91–98%), per-stage
motion/HR/IBI distributions at the post-fix scales, duty-cycle timing, artifact rates. Store as a
versioned JSON the VirtualBand samples from. Acceptance: a calibrated synthetic night run through the
stager lands within tolerance of its scripted architecture — proving generator and stager speak the
same statistical language.

## 4 · The scenario library — every scar becomes a rehearsal
Each scenario names the promise it defends and asserts the FULL outcome (row shape, stages vs script,
notifications, every reader surface). All scenarios run in a non-UTC tz by default (`--tz` matrix:
Mexico_City, Tokyo, UTC) — every tz bug we shipped was invisible to a UTC suite.

**Golden path**
- `perfect-night` — clean duty-cycle night → stages ≈ script, coverage ≈ 1.0, quality sane, one row, one summary push. (P2, P5)

**The scars (regression armor)**
- `charge-gap-90m` — mid-night top-up → ONE night, NODATA hole, no phantom nap (P3)
- `store-and-forward` — offline night, morning bulk drain → drain-hold, then one complete night (P3)
- `marker-mid-drain` — wake marker inside the drain → deferred, partial night never seals (P3)
- `replayed-marker` / `partial-replay` — re-sent marker ± stray tail windows → no-op, stages preserved (P2)
- `honest-correction` — auto-sealed inflated night, then true marker with different span → corrected (P2)
- `evening-doze` — ≥4h doze after a real night, same date → night untouched, doze its own row (P3)
- `band-death` — dies 03:00, honest 07:00 marker → grace-capped, never a 15h night (P2)
- `forgot-to-mark` — marker 10h after band-off → capped, never fabricated light sleep (P2)
- `afternoon-nap` — nap-keyed, never "last night", never dated tomorrow, never in night baselines (P5)
- `garbage-motion` — off-scale epoch features → tripwire fires, phantom guard refuses, duration-only fallback, NEVER garbage stages (P2)
- `poison-payload` — deterministically unstageable data → caps → QUARANTINE → `reopen-quarantine` recovers (P1)

**Chaos mode (`--chaos`) — the promises under fire**
- biosignal restarting mid-seal (the every-deploy case) → transient, no attempt burned, night seals next pass (P1)
- sustained 4h outage overnight → nights delayed, ZERO destroyed (P1)
- deploy version-skew (old service, new app) → fail-closed, recoverable (P1)
- queue backlog: marker races ProcessWindowJobs → placeholder → finalize → exactly one summary (P4)

**Morning story (P4/P5, asserted in every scenario)**
Placeholder appears instantly on marker; finalize flips it; coach summary fires exactly once, computed
from THIS night; greeting holds while computing and releases on finalize; `/api/me/sleep` payload
matches the row; readiness/trends/coach tools all agree. The LAB asserts the story the USER experiences,
not just the rows.

## 5 · Tolerances — statistical honesty, binary invariants
Stage minutes vs script: ±8pts per stage %, duration ±10m, efficiency ±5pts (staging is statistical —
assert the envelope, not the epoch). Everything else is BINARY: row counts, keys, statuses, coverage
bounds, notification counts, reader agreement, quarantine reachability. A tolerance miss is a FAIL, not
a warning. The suite is green or the pipeline doesn't ship.

## 6 · Build plan
1. **Phase 1 — NightScript + VirtualBand render + `perfect-night` green** end-to-end through the real API (incl. calibration extractor).
2. **Phase 2 — the scars.** Every scenario above, tz matrix, scorecard output. Wire the deterministic subset into the test suite.
3. **Phase 3 — chaos mode + morning-story assertions** (notification/mobile-payload layer), and the post-deploy `sleep:lab` run documented in CLAUDE.md as a required gate for seal changes.

Non-negotiables: build ON `BiosignalSimulator`/`SimulateNight` (extend, don't duplicate); real ingest
API only (no DB fixtures); lab profile isolation; every scenario cites its promise; scorecard readable
by a human in ten seconds.

---
*"Quality is the best business plan." Every scenario here is a night someone will actually live. Make
the LAB so good that a lost night becomes a thing that happened to the old product.*
