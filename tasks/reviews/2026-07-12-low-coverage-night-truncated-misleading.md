# BUG: a low-signal night seals as a misleading truncated duration, not an honest partial

**Reviewer:** Henry (server) · from Tester B's (profile 6) first night · 2026-07-12
**Real-world case a DIY band WILL hit often (poor sensor contact) — and the seal handles it badly.**

## What happened

Tester B wore the band all night (T10 motion: 929 continuous samples, 02h–09h — band on-wrist the whole time),
but the **PPG never held contact** — of 170 ppg_raw windows, **146 `short_window_aggregate_only` + 24
`invalid_signal` = ZERO fully-valid windows.** So ~60% of the night is NODATA.

Her **actual data span: 23:08 → 10:01** (~11h, she reports ~8h asleep). But the seal produced:
```
bed 01:11 · wake 02:58 · duration 251m (4h 11m) · coverage 0.397 · REM 0% · status FINAL
```
On her phone this reads as a confident **"you slept 4h 11m, 1:00–3:40."** That's wrong and misleading —
she slept ~8h; the band only *measured* a clean ~4h. The seal **truncated the night to its cleanest
cluster and presented it as the whole, confident night** — worse than saying nothing.

## Two defects

1. **Span truncation.** Data spans 23:08→10:01 but bed/wake sealed to 01:11–02:58. The gap-clustering
   (SESSION_GAP) almost certainly split the night at the big NODATA gaps and sealed only one fragment as
   "the night," discarding the rest — so the displayed span/duration is a fraction of the real night.
2. **No honesty gate on coverage.** A 40%-coverage night finalizes with a hard duration + `status=final`
   and no caveat. The existing `stages_low_confidence` flag (d85f377) covers implausible STAGE splits, but
   not low COVERAGE / a truncated span. So the number is stated as fact.

## Fix asks

1. **Coverage-confidence gate.** When a night's coverage is low (e.g. `< 0.5`), do NOT present a confident
   duration. Either flag it (`low_confidence` / a `partial` status) so the app/coach caveat it —
   *"We could only confirm ~4h of your night — sensor contact was low. Check the band fit."* — or seal it
   duration-only with an honest "partial night" label. Extend the d85f377 flag to coverage + span, not
   just stage plausibility.
2. **Don't truncate the span.** When clusters are separated by NODATA gaps but clearly belong to ONE night
   (the whole thing is within a plausible sleep window and there's no long AWAKE run between them), the
   seal should span bed→wake across the WHOLE night with the gaps rendered as NODATA holes — not seal one
   fragment as the entire night. The timeline already renders NODATA honestly; the SPAN should reflect the
   real bed→wake, not the cleanest sub-window.
3. **Actionable coach nudge.** On a low-coverage night, the coach should proactively suggest a fit check
   (this is the #1 cause and it's fixable) rather than the user seeing a mysteriously short night. (For
   Tester B I did this manually in Spanish; it should be automatic.)

## Why it matters
A DIY optical band loses skin contact FAR more than a commercial one — loose fit, wrist position, tattoos,
cold. This will be the single most common "why is my sleep weird" report. The honest failure mode
(*"low signal — fix your fit"*) builds trust; the current one (*"you slept 4h"* when they slept 8) destroys
it. The whole app's ethos is honesty over fabrication — the seal should hold that line on coverage too.

*Note: this is separate from the fit issue itself (that's on the user). This is about the app telling the
truth when the signal is poor, instead of confidently truncating.*

— Henry
