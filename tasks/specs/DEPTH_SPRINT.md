# DEPTH SPRINT — from "a self-hosted Whoop" to the better product

**Status:** BUILD NOW · **Requested by:** Alex, 2026-07-12
**One line:** We've reached Whoop software parity. Stop adding features; make the surfaces that matter
*exceptional* and lean into the moat Whoop can't copy — the reasoning coach, the honest depth, the
all-in-one OS. This is a craft sprint, not a feature sprint.

---

## The thesis

Parity is done (see titan-vs-whoop). More Whoop-clone features won't win — **depth will.** Three
surfaces carry the product's feel every day; make each of them the best in class:

1. **Sleep timeline** — the thing Alex fought hardest for. Already a strong Canvas hypnogram; elevate
   it to *the story of your night*. → `SLEEP_TIMELINE_ELEVATION.md` (workstream 1, detailed)
2. **Coach cards** — the widgets the coach drops into chat (readiness ring, sleep debt, macros…).
   Already a ~25-type catalog, but read-only and missing the newest features. Make them richer,
   **interactive** (act from the chat), and cover more. This is the moat surface — Whoop has no
   conversational card system at all. → `COACH_CARDS.md` (workstream 2)
   *(Home-screen/lock-screen WidgetKit is a separate greenfield option for later — not this sprint.)*
3. **Coach** — already sophisticated (streaming, native cards, trajectory + situational lenses,
   memory). Elevate from "a great chatbot you open" to "a coach who reaches out." → `COACH_DEPTH.md`
   (later workstream)
4. **Native UI polish** — the app is Whoop/Oura-grade, but a few surfaces drag it down: a
   stock-looking Trends tab, stock iOS controls on the first-run flow, and a lift-detail screen
   that's a lower-fidelity twin of the premium summary. → `UI_POLISH.md` (workstream 3)
5. **Meal-logging revision** — the #1 daily feature is great at the novel paths (photo, barcode,
   re-log) but missing the boring high-frequency ones (manual quick-add, "what's left," any day but
   today). Close the gap to MacroFactor/MFP parity. → `MEAL_LOGGING_REVISION.md` (workstream 4)

## The design bar (all three)

- **Jobs/Chesky, not dashboard-maximalism.** One glance answers the question; depth is *revealed*, not
  dumped. Motion and haptics serve meaning, never decoration.
- **The honesty moat is visible.** Estimates look like estimates; gaps look like gaps. This is already
  the timeline's signature (hatched coverage holes) — carry it into widgets and coach.
- **Teach, don't just show** (Coach v3 stance). Every surface can explain *why*, briefly, when it helps.
- **It's one product.** Shared components, shared palette, shared voice across timeline, widgets, coach.

## Build order (recommended)

**1 → Timeline elevation** (first: data's ready, it's the highest craft-per-effort win, it's the
hero feature). **2 → Widgets** (biggest structural gain in daily presence; greenfield infra). **3 →
Coach depth** (the moat's core; proactive outreach + polish). Henry field-tests each on Alex's real
data before the next starts — the same loop that's been working all session.

Each workstream ships as its own spec so the dev agent builds one buildable unit at a time and Henry
verifies between them. This file is the index + the why; the per-workstream specs hold the how.
