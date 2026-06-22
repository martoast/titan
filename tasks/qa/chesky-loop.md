# /chesky-loop — recursive "use it like a user" UX critique loop

A reusable goal for an agent: become a design-obsessed user (Brian-Chesky style),
**actually use** Titan on a mobile viewport, critique the friction, fix it, and
repeat until every page is "Chesky-approved." Run it with the gstack `browse` skill
at a mobile viewport (`browse viewport 390x844`) and dev-login (`/dev/login?to=…`).

## The standard per-page critique (apply to every page)

For each screen, ask the five questions and act:

1. **What is this screen's ONE job?** If it's doing 3+ jobs, cut or defer the rest
   to where it already lives (e.g. trends → /progress, deep logging → a disclosure).
2. **Does the most important thing lead?** The hero/primary action should be first
   and obvious. Demote setup/secondary cards.
3. **What's overwhelming?** Long manual-entry forms, dense stat grids, duplicate
   sections. Defer forms behind a collapsed "＋ …" disclosure (Alpine `x-collapse`).
4. **What's redundant?** Nav-duplicate footers, two cards linking to the same place,
   the same metric shown twice. Remove.
5. **What's confusing or alarming?** Cryptic errors, native `alert()`/validation
   popups, nagging red copy, clipped inputs, jargon. Replace with calm, inline,
   human feedback.

## The dogfood loop (the important part)

Do a **full day of logging** as a real user and fix what hurts:

- [ ] Log a **workout** (`/workouts/create`): search an exercise, add sets, save.
- [ ] Log a **meal** (`/meals` → Describe it): type a meal, confirm macros, save.
- [ ] Log **sleep** (`/sleep` → Log sleep manually): hours/quality, save.
- [ ] Log **recovery** (`/recovery` → Log recovery manually): save.
- [ ] Log **body weight** (`/body` → Add a measurement): save.
- [ ] Chat with the **coach** (`/coach`): tap a starter, read the reply.

For each: screenshot before, perform the action, screenshot after, note every snag
(blocked save, wrong-place error, surprise, extra tap), then fix the snag and re-run
the flow to confirm it's smooth. Add a regression test where it makes sense.

## Definition of done (don't stop until all true)

- Every page reviewed against the five questions and streamlined.
- Every core logging flow performed end-to-end with zero friction.
- No nav duplication, no `alert()`/native-validation popups, no clipped inputs.
- Units consistent everywhere for the user's metric/imperial setting.
- Full test suite green; new flows guarded by tests.

## Running ledger

### ✅ Done (this campaign)
- **Dashboard** → focused "Today" screen; trends/photos/quick-links → /progress.
- **Meals** → kitchen collapsed; logging leads. Describe-a-meal flow is excellent.
- **Recovery / Sleep / Body / Bloodwork / Brain** → manual-entry forms deferred
  behind disclosures; data/insight leads.
- **Fitness** → steps input no longer clipped.
- **Workout create** (dogfooded) → name pre-filled (was blank-required → native
  popup); typed-but-unpicked exercise now auto-resolves / inline error (was alert()).
- **Nav** → one grouped registry (Daily/Body/Progress/Library) across drawer + More.

- **Units pass** (mostly done): `App\Support\Units` is the one place for kg↔lb /
  cm↔in. Applied to the **Workout logger** (label + suggestions + store), **Workout
  index + show** (weights + volume), and the **Body page** (stats, chart, form labels,
  history + store). Dashboard/Progress already converted earlier. Covered by
  UnitsTest, BodyUnitsTest, WorkoutUnitsTest. Stored data stays metric.

- **Live/voice workout units** ✅: `/workouts/live` now works entirely in the user's
  display units. Client state holds display weight; conversion happens at the
  controller boundary (hydration, add-set store, voice snapshot, spoken text). Voice
  bare numbers follow the user's units (imperial → pounds), while explicit
  "kilos"/"pounds" are honored as-is. Covered by LiveWorkoutUnitsTest.
- **Meal-card tone** ✅: overdue is now a warm amber nudge with supportive copy, no
  giant red "overdue" clock (de-escalated on Dashboard + Meals).

- **Low-density pages** ✅ reviewed: Duo weight now in viewer's units (was kg); Foods
  count labeled "N× logged" (was an ambiguous dim number); foods/research/notifications/
  connect/devices confirmed clean. Empty states across the app are genuinely strong
  (educational, encouraging) — verified on a fresh no-data user.
- **PWA install experience** ✅: real PNG icons (192/512/maskable/180 — iOS ignored the
  old SVG apple-touch-icon, so the home-screen icon was blank); a dismissible
  "Add to Home Screen" hint (iOS) + custom Install button (Android); manifest app
  shortcuts (Coach / Log a meal / Start a workout); SW precache bumped to v2.
- **Regression sweep** ✅: 21 pages × {fresh user, imperial data user} = 0 JS errors;
  full suite 364 green.

### ⏳ Open (next iterations / ideas for further polish)
- Connect (API) page: curl/JSON code blocks scroll horizontally on mobile — could wrap
  or add a copy button. Low priority (power-user/dev page).
- Accessibility deep pass: audit tap-target sizes (≥44px), contrast ratios, and
  aria-labels on icon-only buttons across the app.
- Coach system-prompt facts still emit "Height: X cm" regardless of units (coach
  context string, not UI) — low priority.
- Consider Lighthouse PWA/perf audit on a deployed HTTPS instance (install prompt +
  SW need HTTPS to fully validate).
