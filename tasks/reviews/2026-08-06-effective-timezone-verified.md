# VERIFIED · the coin flip is real and I reproduced it — plus a correction to my own first analysis

**Reviewing:** `9bd83ad` fix(sleep): resolve the sleep card's timezone deterministically, from the profile
**Reviewer:** Henry (server) · 2026-08-06

The bug is real, the fix is right, and the safety claim holds. I tried to test the "no backfill
needed" conclusion independently, picked an invalid anchor, and found nothing — recorded below so
nobody repeats the attempt thinking it was left undone.

---

## Verified against prod

**The mixed connection rows are exactly as described.**

| profile | declared `settings['timezone']` | connection rows | old → new |
|---|---|---|---|
| 1 Alex | `America/Tijuana` | **15 × Mexico_City, 1 × Tijuana** | Tijuana → Tijuana · same |
| 2 Tester C | `America/Mexico_City` | *(none)* | Mexico_City → Mexico_City · same |
| 3/4/5 | *(none)* | Mexico_City only | Mexico_City → Mexico_City · same |
| 6 Tester B | `America/Tijuana` | **2 × Mexico_City, 1 × Tijuana** | Tijuana → Tijuana · same |

All six resolve identically before and after. The claim holds, and no stored night moves.

**The nondeterminism is not theoretical — it is demonstrable.** The old unordered query returns
`America/Tijuana` for Alex *despite 15 of his 16 rows carrying Mexico_City*. So it is not
"first row" or "majority" — the planner genuinely surfaces the minority row here. Flip that and a
sealed bedtime moves an hour with no data change. That is a real latent bug, and worth fixing on its
own even though it happens to be landing on the correct value today.

**Tester B is in the same position** (mixed rows, declared Tijuana), which the commit doesn't mention. She
is protected by the same declared-wins rule, so nothing more is needed — noting it only so the fix
isn't remembered as Alex-specific.

**The root cause framing is correct and worth keeping.** `DeviceIngestionController:178` stamps every
bangle row with `config('app.timezone')` as a *default*, not a device reading — the band sends no
timezone. So the connection table was never a trustworthy source, and preferring the user's declared
value is the right ordering, not just a tiebreak.

**Tests:** `ProfileEffectiveTimezoneTest` 5/5 pass. Full suite clean — failure set identical to the
HEAD baseline (all environment-dependent). Ignoring an unparseable declared value rather than
throwing is the right call given `SealNightJob:875` formats with it directly.

## A correction to my own analysis

I tried to check whether any already-sealed night had been written under the wrong zone — i.e.
whether "no backfill needed" was really safe. My method: compare each night's `bedtime` (rendered in
the resolved zone) against its `session_start` (stored app-tz), expecting a ~−60 min offset on
Tijuana-rendered rows.

Every row came back 0 to +3 min, and I briefly took that as evidence of a historical discontinuity.
**It isn't.** `session_start` is populated for **naps** and NULL for **nights** — it is the nap key
(`SEAL_ARCHITECTURE`: "naps key on `session_start`; nights key on `slept_at`"). My entire sample was
daytime naps, where `bedtime` and `session_start` come from the same seal in the same rendering and
agree by construction regardless of timezone. The measurement was vacuous.

So: **I have no evidence for or against a historical wrong hour.** `session_start` cannot serve as an
independent anchor. A real check would have to re-derive a night's bedtime from its raw
`window_start` / `motion_samples` — which is exactly what the commit did by hand for log 190, and
which I did not repeat.

That leaves the commit's own caveat as the operative statement, and it is correctly worded: *"This
removes the coin flip, it does not correct an existing wrong hour."* Whether such an hour exists in
Alex's or Tester B's stored nights is **open**, not verified either way.

## The one thing I'd want next

If a historical wrong hour matters, the check is mechanical: for a handful of nights across the
period, re-derive bedtime from the night's own raw windows the way the commit did for log 190, and
compare against the stored value. A mismatch on older nights but not newer ones would mean the coin
flipped at some point and a backfill is warranted. Cheap, and it converts "open" into a yes/no.

Not urgent — the displayed hour has presumably looked right to Alex all along, and this change stops
it from silently moving. Filing it rather than doing it, because it needs a decision about whether
anyone cares about historical bedtimes to the hour.
