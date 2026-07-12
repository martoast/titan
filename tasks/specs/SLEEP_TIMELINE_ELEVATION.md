# SLEEP TIMELINE ELEVATION — from a great hypnogram to the story of your night

**Status:** BUILD NOW · Workstream 1 of the DEPTH_SPRINT · **Requested by:** Alex, 2026-07-12
**One line:** The timeline is already the best-drawn surface in the app. Add the three things that
turn a beautiful chart into an experience: a **narrative**, **deeper interaction**, and a **proper
home** — and pay down the one duplication.

---

## What exists today (don't rebuild — elevate)

- **`SleepTimeline`** (View) in `ios/Titan/Sources/Design/Components.swift` (~354–723): a hand-drawn
  **Canvas** stepped 4-lane ribbon (Awake/REM/Light/Deep, depth reads downward), rounded stage blocks,
  **diagonally-hatched `nodata` holes** (the honesty signature — never painted as sleep), a faint HR
  peaks trace + a per-epoch restlessness strip (both sparse/gap-aware), a **clock-time axis** in the
  user's tz, and a **`ScrubGesture`** tooltip ("3:12 AM · Deep · HR 52 · restless"). Three states:
  `computing` shimmer skeleton, full ribbon, thin-coverage capsule. `mini` vs full hero variants.
- **Data** (`SleepResponse.Detail`, `Models.swift` ~184–225): `hypnogram[]` (per-30s codes),
  `epoch_sec`, `hr_series`/`motion_series` (sparse `{i,v}`), `stages[]` (key/label/min/pct),
  `efficiency_pct`, `restorative_min`, `respiratory_rate`, `consistency_pct`, `performance_pct`,
  `need_h`, `debt_h`, `low_confidence`. **Everything a richer timeline needs is already on the wire.**
- **Presented in 3 places:** the post-wake summary sheet (`SleepSummaryView`, mini), the full
  interactive detail (`SleepView` in `RecoveryView.swift`), and the week overview (`SleepWeekView`,
  bar row). Stage colors/lanes centralized in `TitanCore/SleepStage.swift`.
- **Ceiling today:** scrub-only (no tap-to-select a segment, no zoom, no nap expand); the stacked
  stage-breakdown is **duplicated** in `SleepSummaryView.stagesCard` and `SleepView`; axis fixed at 4
  ticks; and the full timeline is **buried behind a NavigationLink** off the Today tab.

## The three moves

### 1. The narrative — "the story of your night" (the headline new thing)

A 2–4 sentence, specific, human read of the night, shown above the hypnogram. Not stats — a story:

> "You were asleep within **9 minutes** and dropped straight into deep — most of your deep sleep
> came in the **first 3 hours**, which is exactly when the body wants it. You had **one brief wake
> at 3:12 AM** and cycled through REM **4 times**, the last long one right before you woke. The one
> soft spot: your deep sleep tailed off early."

**Build it server-side** in `App\Support\SleepDetail` as a `story` field (string + a few structured
bits), so web and the coach share it — one intelligence, three surfaces. Derive from the hypnogram +
metrics already computed:
- **Sleep onset latency** (epochs from bed to first non-wake) — "asleep within N min."
- **Deep-sleep distribution** — front-loaded (good) vs even vs back-loaded. Teach the *why* briefly
  (Coach v3 stance): deep sleep is when the body repairs, and it's naturally front-loaded.
- **Awakenings** — count + clock times of wake runs > ~5 min (short ones are normal, don't alarm).
- **REM cycles** — count of REM runs; note if the last one was long (normal near wake).
- **The one soft spot OR the one win** — a single honest takeaway, never a list.
- **Honesty:** if `low_confidence`, the story leads with "This is an estimate — signal was thin
  overnight" and stays qualitative. Never narrate a hatched-hole stretch as if measured.

Keep it to ONE takeaway of substance; this is the tip philosophy from Sleep Week, applied per-night.
Expose it to the coach too (so "how'd I sleep?" in chat returns the same story).

### 2. Deeper interaction (elevate the hero variant only; leave `mini` calm)

- **Tap-to-select a segment.** Tapping a stage block selects it and shows its span + duration
  ("Deep · 1:04–1:38 · 34 min"). Today only a scrub tooltip exists; a discrete tap/hold to *pin* a
  segment is the natural next gesture. Haptic tick on selection.
- **Pinch / double-tap to zoom** into a window of the night, with the axis re-ticking dynamically
  (kills the fixed-4-tick limitation). Deep sleep and wake events are where you want to look closely.
- **Ultradian cycle markers.** Faint verticals at the ~90-min cycle boundaries (derivable from the
  REM onsets) so the architecture of the night is legible — "4 complete cycles" becomes visible, not
  just stated. This is a Whoop-beating touch; Whoop shows stages but not cycle structure.
- **Restorative emphasis.** Let deep+REM (the `restorative_min`) read as the "good" sleep — a subtle
  brightness/label so the eye lands on what recovery actually came from.
- Respect `prefers-reduced-motion`; keep the `mini` summary-sheet variant non-interactive and serene.

### 3. A proper home + one de-duplication

- **Surface it.** The full interactive timeline is buried behind a NavigationLink off the Today tab.
  Give sleep a first-class path — promote the timeline as the hero of the sleep detail screen, and
  make sure the Sleep Week view (workstream-adjacent) taps straight into this per-night timeline
  (SLEEP_WEEK already wants every night tappable — wire it to THIS view, not a lesser one).
- **Unify the stage breakdown.** Extract the stacked stage %/minutes bar (duplicated in
  `SleepSummaryView.stagesCard` and `SleepView`) into one shared component in `Design/Components.swift`
  next to `SleepTimeline`. One source of truth for the breakdown, matching the timeline's palette.

## Design principles

- **The hypnogram is the hero; the story frames it; stats support it.** Order on screen: story →
  timeline → breakdown → metrics. Not a wall of numbers with a chart bolted on.
- **Keep the honesty signature.** Hatched holes and the estimate framing are the moat — never
  smooth them away to look prettier. A gap you can see is worth more than a pretty lie.
- **Depth on demand.** The night reads in one glance (story + ribbon); zoom/tap reveal more only if
  you want it. Don't crowd the first look.

## Acceptance

- [ ] `SleepDetail` returns a `story` (per-night narrative + structured bits: onset_min,
      deep_distribution, awakenings[], rem_cycles, takeaway); honesty-aware when `low_confidence`.
- [ ] The sleep detail screen shows story → hypnogram → unified breakdown → metrics, in that order.
- [ ] Hero timeline supports tap-to-select a segment (with haptic) and pinch/double-tap zoom with a
      dynamic axis; cycle markers + restorative emphasis rendered; `mini` unchanged; reduced-motion
      honored.
- [ ] Stage-breakdown bar is a single shared component (SleepSummaryView + SleepView both use it).
- [ ] Sleep Week's per-night tap opens THIS interactive timeline.
- [ ] The coach can return the same `story` for "how did I sleep?"
- [ ] Field check on Alex's real night: the story matches the hypnogram (onset, deep distribution,
      wake times, REM cycle count all correct), and a low_confidence night reads as an honest
      estimate. (Henry verifies on real data before workstream 2 starts.)
