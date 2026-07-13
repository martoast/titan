# MEAL LOGGING REVISION — nail the daily 90%

**Status:** BUILD NOW · Workstream 4 of DEPTH_SPRINT · **Requested by:** Alex, 2026-07-12
**One line:** Meal/macro logging is Titan's most-used daily feature. It's excellent at the *novel*
paths (AI photo, barcode, one-tap re-log) and missing the *boring, high-frequency* ones that
dominate real use (manual quick-add, "what's left," seeing any day but today). This closes that gap
to MacroFactor/MFP parity — built in tiers, verified each step.

**Don't touch what's great:** the photo-scan resolve chain (usuals → branded → web → vision), barcode
via Open Food Facts, and the frequency-ranked "Your meals" re-log shelf are best-in-class — build
around them, don't disturb them.

---

## TIER 1 — the daily basics + correctness sweep (build first; highest impact/effort)

### 1.1 Native manual quick-add (the #1 gap)
*Today:* there's **no way to log a non-photographed, non-remembered meal in the Fuel tab** — you must
switch to coach chat. `POST /meals` (`MobileNutritionController::store`) exists; `AppModel` has no
`addMeal` call.
*Build:* a prominent "＋" on the Fuel tab → a quick sheet: **name + kcal, optional P/C/F, time**
(defaults now). Wire `AppModel.addMeal` → `POST /meals`. Bonus: a free-text field ("2 eggs and
toast") that routes through the **same estimate path the coach uses** (so it fills P/C/F). Logging a
snack should take ~5 seconds without leaving the tab.

### 1.2 Show "remaining," not just totals
*Today:* the macro card rings show `value/target` (`MacroRing`, `FuelView.swift:249`); footer is just
meal count. No "what's left," no over-budget signal.
*Build:* add explicit **remaining** figures ("1,240 kcal · 63 g protein left") and an **over-budget
state** (color/red) to the `Macros::today()` payload and the card. Users think in what's left — this
is cheap and the highest-frequency glance in the app.

### 1.3 Correctness sweep (small, but real)
- **`/meals/confirm` and `/meals/store` skip `Macros::reconcile()`** that `log_meal` now runs — so
  photo/manual meals can be macro-inconsistent while chat meals can't. Run reconcile in both paths
  (one source of truth for meal creation).
- **Barcode confirm logs `source:'photo'`** (`MobileNutritionController::confirm`) — mislabels
  barcode meals. Stamp the real source.
- **Two target resolvers:** `Macros::today()` uses `TargetSettings::resolve`; `forHome()`/the mobile
  card use `goalTargets()` — the "screens disagree" bug the code comments try to prevent. Unify on
  one resolver.
- **`EditMealSheet` can't change a meal's time** (`eaten_at`) — only the coach can move a meal in
  time. Add a time/date field to the edit sheet.
- Fix `Meal::SOURCES` const to include the sources actually written (`coach`/`memory`/`barcode`).

---

## TIER 2 — see any day, not just today

### 2.1 Nutrition history (scroll to any day)
*Today:* every native surface is **today-only**; the only history is the chat `recent_meals` tool.
*Build:* make the Fuel day view **date-scrollable** — reuse the day-totals + meals-list logic with a
`date` param (server: `today()` already takes a day; add a `/meals?date=` read). A simple date pager
at the top of the tab.

### 2.2 Weekly macro trend
*Today:* `TrendsView` has **zero nutrition content** (confirmed by grep).
*Build:* add a nutrition section to Trends (or the Fuel tab): 7/30-day calories + P/C/F adherence vs
target, drawn with the **`TrendMetricChart`** component from the Trends overhaul (reuse, don't
re-roll). "Did I hit protein this week" at a glance.

### 2.3 Copy / re-log a previous day
*Build:* from a past day, a **"log this day again"** / copy action (re-logs that day's meals to today,
reusing the meal-memory re-log path). Common for people with repeating diets.

---

## TIER 3 — depth & accuracy (MacroFactor/Cronometer parity)

### 3.1 Native food search over the existing caches
*Today:* discovery is photo/barcode/coach only — the canonical tracker action (search a food, pick,
set grams) is impossible in-app.
*Build:* a `/nutrition/search` endpoint over **`FoodFact` + `BrandedFood`** (+ Open Food Facts text
search) → results list → **gram-based portion** → log. The data + services already exist; this is
mostly an endpoint + a search UI.

### 3.2 Per-item editing + gram portions in the scan confirm
*Today:* the mobile scan/confirm path **never creates `MealItem`s**; the confirm sheet scales the
whole plate by a single ½-step multiplier (`ScanResultSheet:386`). AI plates run 10–25% off (the
schema's own comment) and you can't fix the one wrong ingredient.
*Build:* return the vision `items[]` into the draft; let the user tweak individual lines + enter
grams; recompute totals via the existing `recalcFromItems()`. This makes the "killer" photo flow
trustworthy.

### 3.3 Fiber (then sugar / sodium / sat-fat)
*Today:* **P/C/F/kcal only.** No fiber — table-stakes for MacroFactor/Cronometer and a real health
signal.
*Build:* add `fiber_g` to `meals`/`meal_items`, the vision + estimate prompts, and the card (as a
secondary stat, not part of the reconcile energy math). Structure it so sugar/sodium/sat-fat can
follow the same pattern later.

### 3.4 Multi-item meal templates ("my usual breakfast")
*Today:* `MealTemplate` remembers **single dishes only**.
*Build:* let a user save a **set of items** as one named template (eggs + oats + coffee) and re-log
the combo in one tap. Builds on the meal-memory infra.

### 3.5 Meal-type grouping (breakfast / lunch / dinner / snack)
*Today:* the day is a **flat time-ordered list** (`mealsList:136`).
*Build:* add a nullable `meal_type` column (inferred from time on create, user-overridable) and
section the day list. Also unlocks "add to breakfast" and cleaner templates.

### 3.6 Flag estimated macro splits
*Today:* `Macros::reconcile()` can fabricate a 30/40/30 split when only calories are known
(`Macros.php:70`) — good for honest totals, but a serious tracker shouldn't invent macros
invisibly.
*Build:* mark AI-derived/estimated splits on the meal card (an "estimated" chip, reusing the honesty
treatment from the coach cards) and nudge to confirm. Ties into the app-wide honesty moat.

---

## Design principles
- **The boring path must be the fast path.** Manual add, "what's left," and yesterday are what a
  daily user touches most — they should be the smoothest things in the feature, not afterthoughts.
- **One creation path.** Every way a meal is born (chat, photo, barcode, manual, re-log) runs the
  same reconcile + source-stamping, so no surface can produce a meal another can't.
- **Reuse the design system.** `TrendMetricChart`, `MacroRing`, the meal card, the honesty chip,
  `PillSwitch` — the components exist; this is assembly, not new invention.
- **Honesty travels** (as everywhere): estimated splits look estimated.

## Build order & verification
**Tier 1 → Tier 2 → Tier 3**, each item its own increment. Henry field-tests each on Alex's real
account (he logs meals daily — 9 real meals on file, so this is the one feature with rich live data):
quick-add creates a reconciled meal, "remaining" math is correct vs targets, history shows past days,
search returns real foods, fiber persists. The correctness sweep (1.3) is verified by re-checking the
confirm/store/barcode paths on real logs.

## Acceptance (top-line)
- [ ] A meal can be logged manually in the Fuel tab in seconds, no chat switch; it's reconciled.
- [ ] The macro card shows remaining + an over-budget state.
- [ ] confirm/store/barcode all run reconcile + stamp the correct source; one target resolver.
- [ ] Any past day is viewable; a 7-day macro trend renders; copy-a-day works.
- [ ] In-app food search → gram portion → log; scan confirm allows per-item + gram edits.
- [ ] Fiber tracked end to end; multi-item templates; meal-type sections; estimated-split chip.
