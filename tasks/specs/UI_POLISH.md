# UI POLISH — kill the weak spots in the native app

**Status:** BUILD NOW · Workstream 3 of DEPTH_SPRINT · **Requested by:** Alex, 2026-07-12
**One line:** The app is already Whoop/Oura-grade — this fixes the handful of surfaces that drag it
down: a stock-looking Trends tab, stock iOS controls on the first-run flow, and a lift-detail screen
that's a lower-fidelity twin of the premium summary. Three targeted jobs, built in sequence.

**Ground rule for all three:** route everything through the existing design system — `Theme.swift`
(palette, `Grad`, type scale, `Motion`, `Haptic`) and `Design/Components.swift` (`GlassCard`,
`MetricRing`, `StatRing`, `TrendChart`, `StatTile`, `PillSwitch`, `SleepTimeline`…). No new stock
iOS controls. A fixed surface should look like it was always part of the app.

---

## Job 1 — Trends tab overhaul (highest visible win: it's a whole top-level tab)

**File:** `ios/Titan/Sources/Features/TrendsView.swift`. Today it reads as default Swift Charts next
to the crafted `SleepTimeline`/`HrGraph`.

- **Replace the raw segmented picker** (`~:13-18`, `.pickerStyle(.segmented)`) with the app's own
  **`PillSwitch`** (`CommunityView.swift:906` — explicitly built as "the premium replacement for
  .segmented Picker," used everywhere else). Week / Month / (add Year if data supports).
- **Rebuild the charts** (`~:22-59`, bare `BarMark`/`LineMark` with `.chartXAxis(.hidden)`):
  - Real **date axis** + light **gridlines/baseline** (match `TrendChart` / the strain area chart).
  - **Scrub/selection** — drag to read a point's date + value (the app already does this in
    `SleepTimeline` and `HrGraph`; reuse the pattern).
  - **Draw the period average ON the chart** (a baseline rule), not just as a header number.
  - Use `Grad`/accent fills + an emphasized endpoint, like the rest of the app's charts.
- Each metric (weight, HRV, RHR, sleep, steps, VO₂max) reads as a first-class, branded chart.

**Acceptance:** Trends uses `PillSwitch`; every chart has a date axis, gridlines, a drawn average,
and scrub selection; visually indistinguishable in fidelity from the sleep/heart charts.

## Job 2 — Stock-controls sweep (fixes the first impression + several screens at once)

Stock iOS steppers and compact date pickers are the most off-system controls in the app, and they're
on the **highest-stakes flow (onboarding)**. Build two reusable themed controls, then replace.

- **New `TitanStepper`** (themed +/− control, SF-Rounded number, `Haptic` tick, accent) and
  **`TitanDateField`** (themed date/time control on the app's dark-canvas + `GlassCard` look) in
  `Design/Components.swift`.
- **Replace across:**
  - `OnboardingFlow.swift` — `stepperRow` native `Stepper` (`~:221`, `~:283`) → `TitanStepper`;
    `.datePickerStyle(.compact)` (`~:145`) → `TitanDateField`. (First run — highest priority.)
  - `EditProfileView` — raw `DatePicker`/`Toggle`/`Stepper` in GlassCards (`~:248-285`) → themed
    equivalents (add a `TitanToggle` if the native toggle looks off on the dark canvas).
  - `LogWorkoutSheet.swift` — "WHEN" `.datePickerStyle(.compact)` (`~:87-91`) + duration `Stepper`
    (`~:57`) → `TitanDateField` + `TitanStepper`.
  - Audit `Onboarding.swift` `OBOptionList`/`OBChips` for any remaining stock controls.
- Any lingering `.segmented` Pickers anywhere → `PillSwitch`.

**Acceptance:** no native `Stepper` / `.datePickerStyle(.compact)` / `.segmented` Picker remains in
Onboarding, Edit Profile, or the log sheets; the new controls are reused (not one-offs) and carry
haptics + the app's type/palette.

## Job 3 — Workout-detail unification (kills a two-fidelity inconsistency)

**Files:** `ios/Titan/Sources/Features/LiftDetailView.swift` (weak) vs `WorkoutSummaryView.swift`
(premium). Opening the **same lift** from history vs the end-of-workout sheet gives two different
screens.

- **Extract the premium lift treatment** from `WorkoutSummaryView` (luminous hero at
  `WorkoutSummaryView.swift:127`, `StatTile` grid, verdict, `GlassCard`) into a **shared component**
  both paths render, so history and the summary sheet are identical fidelity.
- **Fix the hand-rolled shell in `LiftDetailView`:** its own flat `card()` (`~:141`) → `GlassCard`;
  the plain dumbbell+number hero (`~:54`) → the shared luminous hero (ring/gradient/glow).
- **Bug: metric grid has no color** — `Text(item.1)` with no `foregroundStyle` (`~:73`); give it the
  themed metric styling.
- **Sets show reps-only** ("12 · 10 · 8", `~:132`) — show **weight × reps + volume** per set (the
  data the lift path already has), matching what the summary shows.

**Acceptance:** the same lift opened from history and from the workout sheet render the same premium
component; sets show weight+volume; no hand-rolled flat card or uncolored metric remains in
`LiftDetailView`.

---

## Build order & verification

**Job 1 (Trends) → Job 2 (stock controls) → Job 3 (workout detail).** Ship each as its own increment.
Henry field-tests each as it lands — most of this is SwiftUI/visual (compiles in Xcode, not runtime-
testable on the server), so verification is: confirm the code routes through the design-system
components (no stock controls / no hand-rolled cards remain), the contracts are sound, and — where a
screen is data-driven (Trends charts, lift sets) — the data feeding it is correct on Alex's real
account.

## Out of scope (noted, low priority)
StackView emoji glyphs (`☀ ◐ ☾`, `StackView.swift:844-862`) and Community `BadgeWall` emoji medals
(`CommunityView.swift:602-620`) — small, and Community is feature-flagged off. Batch them into a
later cleanup, not this workstream. The Recovery "How you feel" block (`RecoveryView.swift:63-87`)
being an un-inputtable stub is a *feature* gap (add a mood input), not pure polish — track separately.
