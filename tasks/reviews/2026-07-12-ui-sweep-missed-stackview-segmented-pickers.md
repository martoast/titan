# Review — stock-controls sweep (job 2) missed two `.segmented` pickers in StackView

**Date:** 2026-07-12 · **Reviewer:** Henry · **On:** 44d2c11 (UI_POLISH job 2)
**Verdict:** ✅ Sweep is thorough where it ran — `TitanStepper`/`TitanDateField`/`TitanToggle` added;
stock Stepper/compact DatePicker gone from OnboardingFlow (incl. EditProfile), LogWorkoutSheet,
BodyView, DailyView. Two small gaps against the spec's "any `.segmented` **anywhere** → PillSwitch"
acceptance:

## Miss 1 (real) — two stock segmented pickers survive in StackView
`ios/Titan/Sources/Features/StackView.swift`:
- `:628` frequency picker — `Picker(selection: $draft.frequency)` Daily / Specific days / As needed,
  `.pickerStyle(.segmented)` (`:630`)
- `:648` kind picker — Supplement / Medication / Other, `.pickerStyle(.segmented)` (`:650`)

These are exactly the stock control job 2 set out to kill; StackView was just outside the named
files, so the agent didn't see them. Swap both to **`PillSwitch`** (already the app-wide replacement).

## Miss 2 (minor / optional) — two native Toggles in EditProfile
`OnboardingFlow.swift` EditProfile cycle section: `Toggle("Track my cycle")` / `Toggle("Log my last
period")` are still native (themed with `.tint`/font). A `TitanToggle` now exists — apply it for
consistency. Low priority: a tinted native toggle is the one stock control that reads fine on the
dark canvas, so this is a nice-to-have, not a blemish.

## Note
Nothing else outstanding on job 2 — the rest of the sweep is clean and the new controls are reused,
not one-offs. Just close the StackView pickers so "no stock segmented pickers" is actually true
app-wide, then job 3 (workout-detail unify) is next.
