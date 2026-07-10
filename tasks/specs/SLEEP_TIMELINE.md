# SLEEP TIMELINE — the night as a story you can see

**Spec for the dev agent · from Alex + Henry · 2026-07-10 · companion to SLEEP_LAB.md**
**Status: BUILD WITH / RIGHT AFTER SLEEP LAB PHASE 1.**

## 1 · The problem

We compute a beautiful 30-second-resolution hypnogram every night (last night: 643 epochs) and then
show the user... four numbers. Deep 45m, REM 91m, light 181m, awake 5m. That's a receipt, not a story.

The timeline chart we DO have (`HypnogramChart`) is buried as a mini-chart on the *recovery* screen.
The morning summary card, the Sleep detail screen, and the web sleep page — the three places a person
actually looks at their night — show phase totals only. You can't answer the most natural questions:
*When did I hit deep sleep? What woke me at 3am? Why does my REM all land at the end?*

Whoop's sleep screen is a timeline first and totals second. Ours should be too — and ours can be more
honest, because we render what we actually measured (NODATA holes included) instead of painting over it.

## 2 · The design

### 2.1 The timeline (one component, everywhere)
A horizontal time-axis ribbon from bedtime → wake, real clock times on the axis (user's timezone,
obviously — we've earned that scar):

- **Stage bands** as a stepped ribbon across four lanes (Awake / REM / Light / Deep, top to bottom —
  depth reads intuitively as depth). Distinct, accessible colors from ONE shared mapping (see 2.4).
- **Wake interruptions** visible as spikes to the top lane — the 3am wake-up is a *thing you can see*,
  not a minute folded into "awake 19m".
- **NODATA holes** rendered as honest gaps (hatched/faint), never smoothed over, never painted as sleep.
  The band-off hour looks like a band-off hour. This is a product differentiator: we show the truth.
- **Anchors**: bedtime and wake markers; the confirmed "I'm awake" tap gets a subtle marker so the user
  sees the moment they ended the night.

### 2.2 Interaction (iOS)
- **Scrub/tap**: touch anywhere → a tooltip pins to that moment: "3:12 AM · Deep sleep". Phase 2 adds
  per-epoch HR to the tooltip ("HR 52") once the API exposes it (§3).
- **Summary card**: gets a *mini* timeline (non-interactive, ~48pt tall) under the headline numbers —
  the shape of the night at a glance. Tapping the card opens the Sleep detail.
- **Sleep detail screen**: the full interactive timeline is the HERO — top of screen, full width —
  with the stage totals BELOW it as supporting cast. Swap the current hierarchy.

### 2.3 States (ties into the progressive summary)
- **Computing**: the timeline renders as a shimmering skeleton ribbon between the known bed/wake
  anchors — "we're writing your story" — and fills in on finalize. No fake data, no empty box.
- **Duration-only nights** (thin coverage, honest fallback): show the span bar with a plain label
  ("stages unavailable — low signal this night") instead of an empty chart. Honesty over blank space.
- **Naps**: same component, shorter axis.

### 2.4 One stage vocabulary — consolidate NOW
The reviews found three divergent stage→color/lane switches (`HypnogramChart`, `SleepSummaryView
.stageStyle`, web). Build ONE `SleepStage` mapping (enum + colors + lane + label, incl. `nodata`) in
TitanCore + one shared blade/JS partial for the web, and delete the copies. A new stage value added
server-side must fail loudly in exactly one place per platform, not mis-render in three.

### 2.5 Web parity
`sleep/index.blade.php` gets the same timeline (SVG or canvas partial, same shared mapping) for the
selected night, above the stage table. The web is where users go to *study* their patterns — give them
the same story.

## 3 · Data contract
Everything Phase 1 needs already ships: `hypnogram_30s` (ordered 30s epochs from bedtime) + `bedtime` +
`slept_at` + `coverage` via SleepDetail / `/api/me/sleep`. Epoch N's clock time = bedtime + N×30s — no
API change required for the timeline itself.
**Phase 2 (small API addition):** per-epoch HR series alongside the hypnogram (the stager already has
`hr_e` internally; expose a downsampled `hr_5min` array in metrics) for the tooltip + an HR overlay line.

## 4 · Acceptance (LAB-enforced)
Add to SLEEP LAB's morning-story assertions: for every scenario, the timeline payload reconstructs the
scripted night — stage bands within tolerance, wake bouts visible at scripted times, NODATA holes exactly
where the script put a charge gap/BLE drop, computing→final skeleton transition on the progressive path,
duration-only fallback label on the garbage-motion path. Screens: summary card mini-timeline, Sleep
detail hero, web parity, all three reading the shared mapping — verified by one snapshot per scenario.

*The four totals tell someone THAT they slept. The timeline tells them ABOUT their night. Ship the story.*
