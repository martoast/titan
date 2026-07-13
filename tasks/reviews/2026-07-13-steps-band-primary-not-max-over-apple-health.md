# Fix — steps must be BAND-primary, Apple Health fallback (not MAX across both)

**Date:** 2026-07-13 · **Reporter:** Henry (Alex flagged over-counted steps; verified on real data)
**Severity:** data-correctness on a core daily metric.

## The problem

`DailyActivity::mergeDaily` (`app/Models/DailyActivity.php`) merges every cumulative field
(`steps, mvpa_min, active_kcal, floors, distance_km`) with **`max()`** across all sources into the
one `(profile_id, date)` row:

```php
$row->{$k} = max($row->{$k} ?? 0, $fields[$k]);
```

Two sources write steps into that row:
- **The band** — `DeviceIngestionService` (`source='bangle'`, `updated_via='device:summary'`).
- **Apple Health** — `HealthIngestService` / `AppleHealthImporter` (`source='apple_health'`).

`max()` doesn't sum, but it **lets Apple Health override the band whenever the phone's count is
higher** — which is exactly what Alex sees as "over-counting": on a day he wears the band AND carries
his phone, both count the same walk independently, and MAX surfaces whichever is larger (usually the
phone), overriding his trusted device. Real data (profile 1):

```
07-12  6881  source=bangle         ← band day
07-11  5373  source=apple_health
07-10  7800  source=apple_health
```

## The rule Alex wants

> "Take the watch [band] as the primary source; if there is no watch, use the Apple Health steps."

**Band-primary, Apple-Health fallback — per day. Never max across the two, never sum.**

## The fix (in `mergeDaily`, keep it the single merge point)

Make the cumulative merge source-aware. Treat the **band** (`updated_via === 'device:summary'`, or
`source` = the band connection source) as authoritative; `apple_health` is fallback only.

Per cumulative field, given the incoming source and the row's *existing* owner
(read `$row->updated_via` / `$row->source` BEFORE overwriting meta):

- **Incoming = band:** authoritative. `$row->{k} = bandOwned ? max($row->{k}, val) : val` (take over
  from a health value; grow monotonically across the day's band re-syncs). Mark the row band-owned.
- **Incoming = apple_health:** apply **only if the day is not band-owned** (no band steps yet). If
  band already owns the day, **skip** — keep the band's number. Otherwise `max` within health.
- **Source label:** don't let an apple_health write downgrade a band-owned row's `source`.

**Edge — band 0:** a band write of `0` (early morning, no steps yet) must NOT lock out the health
fallback. Only let the band *own* a field once it reports a **non-zero** value that day; until then,
health may fill. (Prevents "band synced 0 then went offline → stuck at 0 while the phone counted
2,000.")

Apply the same band-primary policy to the other cumulative fields (`active_kcal`, `distance_km`,
`floors`, `mvpa_min`) for consistency — same double-source, same reasoning.

## Not needed
No read-side change — the dashboard/Trends read `DailyActivity.steps` directly, so fixing the stored
value fixes every surface. Historical rows keep their already-merged values (can't retroactively know
which source was right); the fix is forward-looking. Note in a comment that this supersedes the old
"max can't double-count" rationale.

## Verify (Henry, on real data)
After the fix: on a day with both band + health writes, the stored steps == the band's count (not the
larger phone count); a health-only day still shows health; a band-0-then-health day shows health, not
0. I'll replay merges on profile 1's real days to confirm.
