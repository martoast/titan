# COACH CARDS v2 — the in-chat widgets, richer, interactive, and more of them

**Status:** BUILD NOW · Workstream 2 of DEPTH_SPRINT · **Requested by:** Alex, 2026-07-12
**One line:** The coach already drops native cards into chat — make them *better* (a shared visual
language + honesty + motion), make the key ones *interactive* (act right from the card), and add new
card types for the features we shipped this session. This is the moat: Whoop has no conversational
card system at all.

---

## How cards work today (build on this — don't reinvent)

- The coach emits a card as **minified JSON in a ` ```titan-card ` fence** in its streamed reply.
  Two sources: (a) a tool returns a `card` payload + a `_show` instruction (e.g. `macros_today`,
  `device_status`, `weekly_review`); (b) the coach authors one from scratch (e.g. `sparkline` from
  `show_trend` points).
- **`TitanCardView`** (`ios/Titan/Sources/Features/CoachView.swift`) is the ONE registry mapping a
  card `type` → its SwiftUI struct in **`CoachCards.swift`**. `CoachSegment.parse` splits the stream
  on the fences; an unclosed fence mid-stream is suppressed until it completes.
- **Existing catalog (~25):** readiness, strain, stress, sleep, sleepdebt, sleepplan, macros,
  hydration, fasting, device, pairing, spot, workout, program, autoreg, sparkline, bioage, fitness,
  longevity, weighttrend, markers, stats/stat, review, physique, memory, stack, protocol, generic.
- **Limitation:** cards are **read-only displays.** You can see your macros but must then *type* to
  log; see your stack but must type "took my creatine"; see the stress card but can't start the
  breathing exercise from it. That's the biggest lever — see Thrust A.

## Thrust A — make the existing cards exceptional

### A1. One shared visual language
All cards already share `CoachCardChrome` + `CardBar`; tighten it into a real system: consistent
header (icon + title + a right-aligned status pill using the existing flag→color map), consistent
metric typography (tabular numerals), consistent spacing, and the **honesty treatment** carried over
from the timeline — an estimate/low-confidence card visibly reads as an estimate (a subtle "~" /
"estimate" chip), never a confident number. Motion: a card animates in on stream-complete (respect
`prefers-reduced-motion`); rings/bars fill once, smoothly.

### A2. Make the key cards INTERACTIVE (the headline of this workstream)
Add an **`actions` affordance** to the card schema: a card may carry one or more actions, each a
`{label, tool | prompt}` the tap invokes — either calling a coach tool directly or sending a canned
follow-up turn. The registry renders them as tap targets with haptic feedback. Priority cards to
make actionable:

| Card | Tap action |
|------|-----------|
| `stack` | check off a supplement → marks it taken (no typing) |
| `macros` | "＋ Log food" → opens the log flow / a quick-add; a logged meal updates the card in place |
| `stress` | "Start breathing" → launches the physiological-sigh exercise |
| `sleep` / night story | "See full night" → opens the interactive timeline (workstream 1) |
| `program` | "Start today's session" → start_workout; "Swap" → alternatives |
| `sleepplan` | "Set bedtime reminder" → schedules the wind-down nudge |
| `device` | "Sync now" / "Pair" when stale/unpaired |
| `sleepdebt` | "Plan an earlier night" → hands the payback target to the planner |

Actions must be **safe + reversible** (a tap that logs shows a confirmation/undo), and every action
has a typed-text equivalent (the card is a shortcut, never the only path).

### A3. Cards stay live where it matters
A card the user just acted on updates in place (log food → the macros card re-renders with the new
totals), rather than leaving a stale card above a new one. Use the existing message/streaming model;
re-emit or patch the card on the tool result.

## Thrust B — new card types (cover what we shipped + the gaps)

1. **`nightstory`** — the "story of your night" (workstream 1) as a card: the mini hypnogram + the
   narrative + the one takeaway + a "See full night" action. This is what "how did I sleep?" should
   render, not a bare `sleep` stat card.
2. **`sleepweek`** — the Sleep Week view in chat: 7-night row + week score + streak + the weekly tip.
   For "how's my sleep this week?" Taps a night → nightstory.
3. **`lesson`** — the Coach v3 educational stance as a first-class card: a titled mechanism
   explanation ("Why bedtime consistency drives deep sleep") with a compact diagram/analogy and a
   "save to wiki" action. Turns teaching moments into something memorable, not a wall of text.
4. **`meal`** — a single logged meal (photo thumb + name + macro breakdown + "edit"/"delete"),
   distinct from the day-total `macros` card. Confirms a log beautifully and stays editable.
5. **`compare`** — two metrics side by side over time ("deep sleep vs nights you trained late"), the
   cross-domain connections the coach already reasons about, made visual. Richer than `sparkline`.
6. **`streak`** — workout + sleep consistency streaks (the SleepWeek streak + WorkoutStreak) in one
   card, celebrating the behavior that actually moves the needle.
7. **`biopanel`** — a bloodwork/biomarker panel (grouped markers with in/out-of-range chips + trend
   arrows), elevating today's flat `markers` card. For "how's my bloodwork."

Each new type: a tool (or existing tool) returns the `card` payload + `_show`, a struct in
`CoachCards.swift`, and one registry line in `TitanCardView`. Document each in `ToolDocs`/the system
prompt so the coach knows when to reach for it.

## Design bar

- **A card answers the question at a glance; the action closes the loop.** See it *and* do it,
  without leaving the chat. That's what makes the coach feel like an operator, not a readout.
- **Honesty travels.** Estimate/low-confidence cards look like estimates — same rule as the timeline
  and the debt ledger. Never a confident-looking number on thin data.
- **One product.** Every card shares chrome, palette (TitanCore), and voice. A new card should look
  like it was always there.
- **The model emits data, the app owns the pixels.** Keep authoring the card as small JSON (type +
  fields + optional actions); all layout/polish lives in the Swift struct, so a card can't render
  ugly because the model phrased it oddly.

## Build order

**1 → the interactivity spine (A2 schema + actions on `stack`, `macros`, `stress`)** — the biggest
felt upgrade, and it establishes the `actions` pattern every other card reuses.
**2 → the shared visual language (A1) + live-update (A3).**
**3 → new cards, highest-value first: `nightstory`, `sleepweek`, `lesson`, then `meal`/`compare`/
`streak`/`biopanel`.**

## Acceptance

- [ ] Card schema supports `actions[]`; taps invoke a tool or a canned turn, with haptics, a
      confirmation/undo on anything that writes, and a typed-text equivalent for each.
- [ ] `stack`, `macros`, `stress`, `sleep`, `program` are interactive per the table; acting on one
      updates it in place.
- [ ] Shared chrome + honesty treatment (estimate chip) applied across the catalog; reduced-motion
      honored.
- [ ] New cards `nightstory` + `sleepweek` + `lesson` render and are wired to their tools; "how did
      I sleep?" → `nightstory`, "how's my week?" → `sleepweek`.
- [ ] Field check on Alex's real account: ask the coach the driving questions, confirm the right
      card renders, an estimate night shows the estimate chip, and tapping "Start breathing" / "Log
      food" / a stack item actually does it. (Henry verifies on real data.)
