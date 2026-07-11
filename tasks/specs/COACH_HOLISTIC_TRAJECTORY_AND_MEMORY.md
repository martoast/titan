# COACH v2 — a coach that walks in knowing you: trajectory, living memory, real widgets

**Spec for the dev agent · from Alex + Henry · 2026-07-11**
**Status: BUILD — phased. This is the coach overhaul.**

## The core problem (Alex's words)

> "The coach doesn't have a wholistic view of the user… I need it to be a top-tier health/nutrition/
> longevity expert that has all the user's metrics and can pull them up… and the long-term memory wiki
> should always be filling with the important info so it has complete context… and I want it always to
> have a view of how my trajectory is going — the trends in sleep, nutrition, training."

The coach is already capable — 75 tools, a memory book, a Brain wiki with embeddings, conversation
summarization that extracts facts. The gap is not capability; it's **awareness and reflex**. Today the
coach opens every conversation nearly blind and must consciously fetch everything.

## Ground truth (what's actually there, so we build on it — not rebuild)

- **Opening context is thin.** `CoachService::systemPrompt()` (`app/Services/Coach/CoachService.php:497`)
  injects only: goal, sex, age, height, tone, physique digest, cycle (female), a ~700-char memory
  digest, pinned wiki *titles*, and today's *calories + protein only*. **No trends. No sleep/recovery/
  HRV/strain/training/weight/nutrition trajectory.** The model must call a tool to see any of it — so
  unless it decides to, it's flying blind.
- **The trajectory math already exists** — `app/Support/TrendsOverview.php` computes a multi-week series
  + averages for recovery, sleep_performance, strain, HRV, RHR, sleep_h. `WeightTrend`, `RecoveryMetrics`,
  `SleepCoach::baselineFor` exist too. We mostly need to SURFACE this, not invent it.
- **Memory auto-fills only at conversation compaction.** `consolidateKnowledge()`
  (`CoachService.php:443`) extracts durable facts→memory + wiki pages, but only when a conversation
  compacts (~every 28 turns) and only from CHAT TEXT — never from the user's DATA (patterns, milestones,
  biomarker shifts). So the wiki stays sparse between long chats.
- **iOS renders ONE card natively.** The coach emits ` ```titan-card ` JSON blocks and many tools return
  ready-made cards, but `CoachView.swift`'s `TitanCardView` (`:483`) only has a first-class `macros`
  widget — **every other type (`readiness`, `sparkline`, `stats`, `sleep`, `strain`, `physique`,
  `bioage`, `fitness`, `weight`, `cycle`, `markers`…) falls through to a generic key/value list**
  (`GenericCard`, `:558`). The prompt promises rich widgets the app doesn't draw.
- **Proactive coach is 100% templated.** Every `ReactTo*` job posts hand-written copy — none reason with
  the LLM over the user's trajectory.

## The build — four phases, each shippable

### Phase 1 — TRAJECTORY ALWAYS IN VIEW (Alex's #1; highest leverage, mostly wiring)
Inject a compact **trajectory digest** into every system prompt, so the coach opens each chat already
knowing the direction of the user's life — the way a real coach reviews your week before you sit down.

- New `CoachTrajectory::digest($profile)` (wrap `TrendsOverview` + `WeightTrend` + a nutrition-trend and
  training-load rollup). For each domain give **current vs baseline, direction, and the meaningful delta
  over 7d & 28d**, with data-confidence:
  - **Sleep**: avg duration & performance, trend (e.g. "7.1h, ↓18min/wk over 3 wks"), consistency, debt.
  - **Recovery/HRV**: HRV & RHR vs personal baseline, direction, today's readiness.
  - **Training**: weekly strain/volume load, sessions/wk, trend, and strain-vs-recovery balance.
  - **Nutrition**: avg calories & protein vs target, adherence %, the recurring gap (e.g. "protein hits
    target on rest days, short ~30g on training days").
  - **Body**: weight trend vs goal direction, rate.
- Keep it TIGHT (~aim ≤900 chars, budgeted like the memory digest) and **confidence-tagged** — never
  state a soft number flatly. Format as a scannable "Trajectory" block in the system prompt right after
  the memory digest.
- Instruct the coach (prompt) to **lead with trajectory awareness**: reference where a metric is heading,
  not just today's value; flag a concerning slide proactively; connect domains ("your deep sleep drops
  the nights after late training").
- Deeper drill-downs stay as tools (`show_trend`, `sleep_recovery_summary`, `weekly_review`) — the digest
  is the always-on situational awareness; tools are the zoom-in.

### Phase 2 — REAL WIDGETS (Alex's UIUX ask; biggest visible win)
Build first-class SwiftUI widgets in `CoachView.swift`'s `TitanCardView` for the card types the coach
ALREADY emits but that currently degrade to key/value lists. Priority order:
1. **`sleep`** — reuse the SLEEP_TIMELINE_V2 component (stage ribbon + movement + HR) as a compact card.
2. **`strain`** — the 0–21 gauge with the recovery-aware target zone.
3. **`readiness` / recovery ring** — HRV/RHR vs baseline, confidence-aware.
4. **`sparkline` / `trend`** — a real mini-chart (the coach's trend answers), not a number list.
5. **`stats`/`stat`** — a clean metric grid/tile.
6. **`macros`/nutrition** — extend the existing card to full macro rings + protein-gap.
7. Then `physique`, `bioage`, `fitness`, `markers`, `weight`, `cycle`.
- One shared card model + a registry so a new `type` renders a real widget or fails loud in ONE place
  (mirror the SleepStage consolidation lesson). Add new types a longevity coach wants: a **biomarker
  panel** card (flagged markers vs optimal ranges) and a **protocol/plan** card (a supplement or training
  protocol as a checklist).
- The coach should LEAD visual answers with a card + one sentence, not a paragraph of numbers.

### Phase 3 — LIVING KNOWLEDGE (Alex's "always filling" ask)
Make the wiki/memory grow from DATA, not just chat.
- **`KnowledgeEnricher` weekly job** (scheduler): synthesize the week's data into durable knowledge —
  patterns (`"deep sleep −22% on nights after 9pm+ workouts (n=5)"`), milestones (PRs, streaks, weight
  goals), biomarker changes, program adherence, what's working. Write/merge into Brain pages via the
  existing `KnowledgeIngestor` (dedup by slug, re-embed). Tag `source: auto-synthesis`.
- **Run `consolidateKnowledge()` more often** — not only at compaction. After a substantive session, and
  on a cadence, extract durable facts→memory book so nothing important waits 28 turns to be saved.
- **Confidence + provenance**: auto-facts carry a source + a first-seen date and are revisable; a later
  contradicting signal updates rather than duplicates (extend the `similar_text` dedup).
- Result: over weeks the coach accumulates a real, queryable model of the user — the "complete context"
  Alex wants — without depending on it remembering to call `remember`.

### Phase 4 — PROACTIVE INTELLIGENCE (the natural payoff)
Upgrade the highest-value `ReactTo*` moments from templated copy to **trajectory-grounded LLM reactions**
(reuse the Phase-1 digest as context). E.g. the morning readiness note reasons over the recovery+sleep
trend and last night; the meal reaction sees the nutrition trajectory, not just today's protein gap.
Keep them short, card-led, and rate-limited. This is what makes it feel like a coach who's paying
attention, not a rules engine. (Do this LAST — it depends on Phase 1's digest existing.)

## Sequencing & why
1 → 2 → 3 → 4. Phase 1 is the biggest perception jump for the least work (the trend math exists; wire it
in) and it unblocks Phase 4. Phase 2 is the biggest *visible* win and pairs naturally with the sleep
timeline already in flight. Phase 3 is the compounding long-game. Ship each independently.

## Acceptance
- **P1**: open a fresh conversation, ask "how am I doing?" — the coach references trajectory (directions +
  deltas across sleep/recovery/training/nutrition/weight) WITHOUT being asked to fetch, and confidence is
  honored. Verify the digest is present in the system prompt and stays within budget.
- **P2**: the coach's sleep/strain/readiness/trend answers render as real widgets on iOS, not key/value
  lists. One snapshot per card type; a new/unknown type fails loud in one place.
- **P3**: after a week of data, the Brain has auto-synthesized pattern/milestone pages (source-tagged) the
  user never asked for; a contradicting signal updates rather than duplicates.
- **P4**: a morning readiness reaction cites the trend, not just the night; reactions stay rate-limited.

## Notes / smaller finds (fix in passing)
- `temperature:0.5` set for the coach is silently dropped — `gpt-5.4-mini` is treated as a reasoning model
  (`AiService::isReasoningModel:28`), so temperature isn't applied. Decide intended behavior; don't set a
  param that's silently ignored.
- Semantic memory search is brute-force PHP cosine (`KnowledgeSearch`) — fine now, revisit if the wiki
  grows large.

*The coach already has the data and the tools. What it lacks is what every great coach has walking in the
door: your trajectory in their head, a memory of who you are that keeps growing, and the ability to show
you the picture instead of reciting numbers. That's this build.*
