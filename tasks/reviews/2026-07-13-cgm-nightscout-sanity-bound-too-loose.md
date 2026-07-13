# Review — Nightscout ingest VERIFIED, one fix: sanity bound too loose · proceed with curve

**Date:** 2026-07-13 · **Reviewer:** Henry (field-test on sample Nightscout entries)
**On:** 29f44cb (CGM_INTEGRATION P1 — Nightscout provider + glucose:sync + connection)
**Verdict:** ✅ Provider/sync/settings work. ❌ One fix: the glucose sanity bound admits impossible
CGM values.

## Verified on real Nightscout formats
`NightscoutProvider::mapEntry`:
- sgv→mg_dl; `date` (ms-epoch) preferred over `dateString` fallback; trend normalized
  (Flat→flat, FortyFiveDown→falling_slow); device + raw carried. ✓
- Rejects sgv=0 and non-sgv rows (calibration/mbg → null). ✓
- Token never logged; HTTP isolated from the pure mapper; `glucose:sync` no-ops cleanly with 0
  connected profiles ("0 profiles, 0 readings"). ✓

## Fix — the sanity bound is too loose (admits impossible readings)
`mapEntry` drops only `mg <= 0 || mg > 600`. But a CGM physically measures **~40–400 mg/dL** (Dexcom/
Libre report "LOW"/"HIGH" outside that). So **401–600 and 1–39 pass through** — I fed it sgv=600 and
it was accepted. A single spurious high (calibration glitch) then skews time-in-range, average, and
variability.
- **Fix:** bound to the real CGM range. Either **reject** outside [40, 400], or better **clamp** to
  [40, 400] (matching how the devices themselves report a genuine LOW/HIGH), keeping the raw value in
  `raw`. Clamp preserves real extreme physiology while killing impossible junk. (Store a flag if you
  want to distinguish clamped readings.)

## Proceed — rest of P1
- Glucose **day-curve UI** (reuse the chart components; shade the TIR band; honest gaps) + the summary
  metrics surfaced.
- **Connect UX** (Nightscout URL+token / HealthKit) + a `glucose_status` card/tool showing connection
  + last-reading freshness.
Then P2 (meal overlay + per-meal response). Henry re-verifies the bound fix, then field-tests the
curve on a Nightscout test feed / seeded readings until Alex connects a real CGM.
