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

### ⏳ Open (next iterations)
- **Units pass**: weight still shows **kg** for imperial users on the Workout logger
  ("Weight (kg)"), Body page (stats/chart/labels), and circumferences in cm. Needs a
  careful conversion on input + display + totals, with tests. Highest-priority detail.
- **Meal-card tone**: the giant red "Xh overdue" on Dashboard + Meals reads as nagging;
  decide whether to soften (smaller/warmer) — product/tone call.
- Re-dogfood **recovery** and **body** logging end-to-end after the units pass.
- Remaining low-density pages (foods, research, devices, connect, duo, notifications,
  profile) — quick five-question review.
