# Review — sleep story ALWAYS says "signal was thin" — stage-split gate over-fires on good nights

**Date:** 2026-07-14 · **Reporter:** Henry (Alex flagged; verified on his real nights)
**Severity:** user-facing — his daily sleep narrative is wrongly stuck in "estimate" mode.

## The bug
The "story of your night" leads with **"Signal was thin overnight, so this is an estimate"** on nights
that were actually WELL measured. Verified on Alex:

| night | coverage | light% | REM% | low_confidence | correct? |
|------|---------|-------|------|----------------|----------|
| 07-14 | **0.991** | **76%** | 21% | **true** | ❌ WRONG — 99% coverage, a real light-heavy night |
| 07-13 | 0.839 | 91% | 3% | true | ✅ right — the phone-died recovered night (real HR gap) |
| 07-12 | 0.984 | — | — | false | ✅ |

## Root cause
`SealNightJob::isLowConfidence` (line ~1319) falls through to `stageSplitImplausible` (line ~1357),
which returns true when **`max(deep,rem,light)/sleep > 0.70`** (or REM < 5%). 07-14's **76% light**
trips it — **regardless of coverage.** But its intent (comment line 1354) is a *fallback for garbage
staging*; on a 99%-coverage night the stager had good data, so a light-heavy / low-deep split is a
REAL (if poor) night, not a signal artifact. Alex's nights run light-heavy, so this fires constantly →
"always an estimate."

Genuine signal problems are ALREADY caught independently, above `stageSplitImplausible`:
- coverage gate (`$cov < LOW_COVERAGE_CONFIDENCE 0.5`, line 1315),
- valid-window gate (`validFraction < MIN_VALID_WINDOW_FRAC 0.5`, line 1308),
- the stager's own `stages_low_confidence`, and `degenerateNight`.
So `stageSplitImplausible` is pure double-jeopardy for a well-measured skewed night.

## Fix
**Only apply `stageSplitImplausible` when the signal is NOT already strong.** Add a high-coverage
threshold (e.g. `HIGH_COVERAGE_CONFIDENCE = 0.85`) and gate it:
```php
$signalStrong = $cov >= self::HIGH_COVERAGE_CONFIDENCE
    && ($validFraction === null || $validFraction >= self::MIN_VALID_WINDOW_FRAC);
return (bool) ($metrics['stages_low_confidence'] ?? false)
    || $this->degenerateNight($metrics)
    || (! $signalStrong && $this->stageSplitImplausible($metrics));
```
Keep trusting the stager's own `stages_low_confidence` (a model assessment, not the crude heuristic)
and `degenerateNight` unconditionally. Verify 07-14 was flagged by the PHP heuristic and NOT by
`stages_low_confidence` — if the joblib stager itself is over-flagging light-heavy nights, that
threshold needs the same "trust high coverage" treatment.

## Secondary — the wording conflates two things
"**Signal** was thin overnight" is only accurate for a coverage/valid-window problem. A stage-split
flag (signal fine, stages uncertain) shouldn't claim the signal was thin. After the fix stageSplit
only fires on marginal-coverage nights so it's mostly moot, but consider: SleepStory's estimate lead
could key on the REASON (low coverage → "signal was thin"; else → "the stages are an estimate tonight").

## Verify (Henry, after the fix — re-seal the nights)
- 07-14 (cov 0.991) → **low_confidence FALSE**, story loses the "signal thin" lead.
- 07-13 (cov 0.839, HR gap) → **stays low_confidence TRUE** (caught by coverage/valid-window, not the
  stage split). I'll re-seal both and confirm.

## Repro
`SleepStory::forNight(<07-14>)` → text starts "Signal was thin overnight…"; the night is cov 0.991,
light 76% → flagged only because 76% > 70% in `stageSplitImplausible`.
