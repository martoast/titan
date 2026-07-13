# MEAL CARD — make the logged-meal chat card a clean macro visual

**Status:** BUILD NOW · small · **Requested by:** Alex, 2026-07-13
**One line:** When you log a meal, the chat card should show just the **macros, beautifully** — not an
id or raw fields. The nice card already exists; this makes it more visual and hardens the fallback.

## Root cause of "it shows the ID and extra fields"
The `meal` card **is** routed to the clean `MealCard` (`CoachCards.swift`, registry line ~527), which
shows photo + name + kcal + P/C/F — **no id**. Alex is on an **older app build** that predates the
`meal` case, so the meal JSON falls through to **`GenericCard`** (`CoachView.swift`), which dumps
every raw key (`Meal_id`, `Photo_url`, `Eaten_at`, `Carbs_g`…) alphabetically. So the real fix for
*him* is an app update — but two things make it better regardless:

## 1. Make `MealCard` a proper macro visual (the ask)
Redesign the card body so **macros are the hero**:
- Keep: photo thumb + name + big `kcal` in the header. **Drop the `eaten_at` line** (clutter — he
  wants just the macros).
- Replace the three flat capsule chips with a **proportional macro bar**: one slim horizontal bar
  split by each macro's *caloric* share (protein g×4, carbs g×4, fat g×9), colored
  protein=pink / carbs=cyan / fat=amber (existing `Theme.Palette`). Under it, three readouts — a
  color dot + grams + label — evenly spaced. At-a-glance "this meal was mostly protein/fat," which a
  gram list can't show.
- Keep the Edit/Delete `CardActionsRow`.
- Reuse the design system (`Theme.Font`, `Theme.Palette`, `.coachCard()`); honor reduced-motion if
  anything animates. Handle a missing macro gracefully (0-width segment).

## 2. Harden the payload so even old builds aren't ugly
In `CoachTools::mealCard()`, **drop the bare `meal_id`** from the emitted card JSON — `MealCard`
doesn't read it (only the Edit/Delete action *prompts* embed the id inline), so removing the
top-level field means the `GenericCard` fallback stops printing `Meal_id`. Small, and it helps every
user still on an older build.

## Acceptance
- [ ] Logging a meal shows a card whose focus is a proportional P/C/F macro bar + gram readouts;
      no id, no eaten_at line.
- [ ] `mealCard()` payload no longer carries a top-level `meal_id` (actions still work — id is in the
      prompt text).
- [ ] Henry verifies the card payload on Alex's real meal; Alex confirms the visual after an app
      update.

## Note to Alex (not a code task)
The clean card is already in the build — to see it (instead of the generic id/field dump), the iOS
app needs to update to the latest build. This spec just makes that already-clean card *nicer*.
