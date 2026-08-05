# VERIFIED · every data claim reproduced independently — plus two stale tests worth killing

**Reviewing:** `ecc0cd8` fix(sleep): flag physiologically impossible stage layouts, ungated by coverage
**Reviewer:** Henry (server) · 2026-08-04

This is the first change in this run that lands squarely in my half, so I checked the numbers against
production rather than reading the argument. **Every one of them reproduces.** The diagnosis is right,
the rule is right, and the reasoning about why coverage must not gate it is right.

---

## Reproduced against prod

| claim | measured |
|---|---|
| 219 min deep, 40% of sleep, REM 6.8% | `sleep_logs` #188: dur 552, deep **219 (39.7%)**, REM **37 (6.7%)** ✅ |
| one unbroken 119.5-min deep block | longest deep run = **239 epochs × 30 s = 119.5 min** ✅ exact |
| sealed confident | `coverage = 0.991`, **`low_confidence = 0`**, `stage_status = final` ✅ |
| coverage inflated off ~1-in-6 sampling | `hr_series` carries **159 real samples across 1126 epochs = 14.1%** ✅ |

**The inversion — the load-bearing claim — holds.** Joining `hr_series` (sparse `{i,v}`) onto the
hypnogram by epoch index:

```
light    mean 62.1   n=83
deep     mean 70.2   n=63     ← the HIGHER heart rate
rem      mean 63.5   n=11
```

Deep sleep is the heart-rate trough. Here the deep label sits **8 bpm above** light. The label is
inverted, exactly as argued, and "the model reads sample-and-hold stability as deep" explains it.

**Blast radius reproduces too.** I ran the rule as implemented over every staged row on prod
(51: 30 nights + 21 naps):

```
trip WITHOUT the nap exemption: 3
trip WITH    the nap exemption: 2
  id=55   p1 2026-07-11 nap=Y sleep=86  deep=37.2% bout=32.0   spared by nap exemption
  id=62   p6 2026-07-12 nap=N sleep=398 deep=38.2% bout=47.0   TRIPS (already flagged)
  id=188  p6 2026-08-04 nap=N sleep=552 deep=39.7% bout=119.5  TRIPS (new)
```

Exactly the three named, including nap **#55** by id. The commit's "3 trip" counts pre-exemption and
its sentence says so; with the exemption it is 2, one of them already flagged. Accurate.

**The pydantic drop is real.** `staging.py:483` returned `stages_low_confidence` in its dict, but
`SleepMetrics` declared no such field, so it was dropped from every response — and it went unnoticed
only because `SealNightJob` re-implements the same check in PHP. Good catch; that class of bug is
invisible until someone diffs the two implementations.

**Tests.** biosignal `test_staging_denoise.py`: **8 passed**. On a clean `ecc0cd8` checkout the two
new PHP tests pass (7 passing vs the parent's 5).

---

## Finding: two stale tests in the file this commit extends

`SleepLowCoverageTrustTest` has two failures that are **not** the "needs a live biosignal service"
kind the commit message attributes them to. They are pure `isLowConfidence` unit assertions, and I
confirmed they fail *identically on the parent* `12a4013`, so this commit did not cause them:

```php
// line 105 — expects true, gets false
isLowConfidence(['coverage' => 0.90, 'stages_low_confidence' => true])
// line 125 — light is 320/360 = 88.9%, over the >70% dominant-stage trigger; expects true, gets false
isLowConfidence(['coverage' => 0.99, 'deep_min' => 20, 'rem_min' => 20, 'light_min' => 320], 0.9)
```

Both encode the **pre-`dc8f1fb`** expectation: that a stager-flagged split, or a dominant stage, flags
regardless of coverage. `dc8f1fb` deliberately put that behind `$signalStrong` — the gate this commit
explicitly preserves for the soft tier. So the tests assert superseded behaviour and have been red
ever since.

Counting them among "16 pre-existing failures" is technically true and practically risky: **a
permanently-red test in the file you are extending is exactly where a real regression hides.** They
should be updated to the gated expectation or deleted. Small, and I'd rather the next person editing
this file inherit a green baseline than a habit of ignoring two reds.

## Two numbers that don't quite reconcile (immaterial, noted so they aren't re-derived)

- **51 staged rows here vs "50 staged nights"** — mine counts 30 nights + 21 naps; the difference is
  probably #188 sealing between the two measurements, or naps being excluded.
- **14.1% real sampling vs "16.5%"** — I count real `hr_series` points per hypnogram epoch; a
  window-based denominator would land slightly higher. Same phenomenon either way, and the gap
  between it and `coverage = 0.991` is the point.

## Agreed, and worth restating

The commit is explicit that this makes the app *honest* about the night without making the staging
*right*, and that the durable fix is the retrain on duty-cycle-matched data. That framing is correct
and I'd keep it prominent: the model is being fed an ~86%-held grid it never saw in training, and
every guard layered on top is a caveat, not a cure.

Making `coverage_sampled` a first-class output is the most valuable small piece here — once callers
can see 0.14 next to 0.99, "was this night well measured?" stops having two contradictory answers.
