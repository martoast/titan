# Review (ROOT CAUSE) — validWindowFraction counts the band's NORMAL duty-cycle windows as invalid

**Date:** 2026-07-14 · **Reviewer:** Henry (peeled through 3 layers on Alex's real nights)
**Supersedes:** the two prior sleep reviews (stageSplitImplausible + stages_low_confidence gating).
Those fixes are correct but INERT because of this deeper bug.

## The real cause
`SealNightJob::validWindowFraction` (line ~1343) counts a ppg_raw window as valid only when
`result_refs['skipped']` is empty:
```php
$valid = $ppg->filter(fn ($w) => empty(((array) $w->result_refs)['skipped']))->count();
```
But the band **duty-cycles** (short bursts to save battery — per CLAUDE.md/SEAL_ARCHITECTURE), so most
windows come back tagged **`short_window_aggregate_only`** — a NORMAL mode (the band DID read the
pulse; the window was just too short for full standalone HRV). That tag lands in `skipped`, so those
windows are wrongly counted as invalid.

**Verified on real nights:**
- 07-14 (flagged): 108 windows = **71 short_window_aggregate_only + 37 invalid_signal** → validFraction **0**
- 07-12 (NOT flagged): validFraction **0 too**

So validFraction ≈ 0 for EVERY Alex night → `signalStrong` (needs validFraction ≥ 0.5) is ALWAYS
false → the stage-skew gate I added (a4df9f5/30a3bcd) NEVER applies → every light-heavy night
(dominant stage > 70%) is flagged "signal was thin." That's the "always an estimate" Alex sees.

## Fix
`validWindowFraction` must count `short_window_aggregate_only` as **valid** (the pulse WAS read) — only
genuinely unreadable windows (`invalid_signal`, and truly absent) are invalid:
```php
$valid = $ppg->filter(function (DeviceIngestion $w) {
    $skip = ((array) $w->result_refs)['skipped'] ?? null;
    return $skip === null || $skip === 'short_window_aggregate_only';   // duty-cycle burst = still a read
})->count();
```
Re-check the exact tag string in ProcessWindowJob. After this:
- 07-14: valid = 71/108 ≈ **0.66** ≥ 0.5 → with cov 0.99, **signalStrong TRUE** → stage-skew gate
  applies → **low_confidence FALSE**, story drops "signal was thin." ✓
- 07-13 (phone-died, real HR gap): windows mostly `invalid_signal` → validFraction low → signalStrong
  false → **stays flagged.** ✓

## Applying to Alex's EXISTING nights
A `--night` re-seal is a NO-OP here — all 108 windows are already `sealed` (invisible to reseal;
07-14's row updated_at never changed). To fix his historical rows use `sleep:recover-stages`
(re-processes raw windows + reseals) or an explicit unseal; otherwise the fix takes effect on nights
going forward. Henry will re-verify 07-14 via recover-stages once the fix lands.

## Repro
07-14: coverage 0.991 but validFraction 0 because 71/108 windows are `short_window_aggregate_only`
(duty-cycle), counted as invalid. 07-12 (a good, unflagged night) also has validFraction 0 — proof
the metric is measuring the duty-cycle, not signal quality.
