# Chesky UI/UX pass — 2026-06-26

Three parallel design-taste reviews (Chesky lens) across the app: iOS daily loop, iOS feature tabs,
web Blade views. Verdict: **the design system is genuinely premium** (MetricRing, count-ups, warm
evidence-based copy, disciplined tokens, exemplary Coach/Stack empty states). Two clear targets.

## ✅ FIXED — the run summary (the unanimous weakest screen, on both platforms)
All three reviewers independently flagged the new run summary as the outlier. Fixed in this pass:
- **Hero moment** — distance is now a huge number (iOS 48pt on the map; web `text-6xl font-display`),
  not one tile among nine equal ones.
- **Map as a poster** — distance + moving time + pace overlaid on the route with a legibility scrim
  (iOS + web), so the screenshot itself is the artifact.
- **Share** — the "11-star" move: iOS `ShareLink` + web `navigator.share`, with a proud caption
  ("Ran 8.41 km at 4:59/km — tracked on Titan 🏃"). Was completely missing.
- **Token cohesion** — iOS RunsView rewritten onto the design system: `Theme.Radius.card/chip`,
  `Theme.Palette.card`, reuses `Metric` / `GlassCard` / `PressCard` (was 6 ad-hoc radii + a one-off
  surface color + a re-implemented `Metric`). Web `runstat` now renders in `font-display nums`
  (Archivo) like every other big number (was Manrope — visibly a different app).
- **Calm color** — secondary stats demoted to plain text; color reserved for GAP (mint) + Effort
  (pink). Was a confetti of 5-7 hues.
- **Warm closing copy** — "Nice work. Sealed from your band — GPS-grade estimates." (was a cold
  database footnote).
- **Real bug fixed (iOS)** — RunDetail now distinguishes "no GPS route" from "load failed" and offers
  a tap-to-retry (was silently lying "No route" on a network failure).
- **Map crop (web)** — request a 3:2 static map + `object-cover` on a matching 3:2 box → no route
  clipping (was `aspect-[9/5] object-cover`, which cropped out-and-back routes).

Verified: iOS BUILD SUCCEEDED, web run-detail test green.

## ⏳ NOT YET DONE — the highest-leverage fix for the *existing* app
**Designed loading states across the daily loop.** A `Shimmer` component exists but is unused, so the
Daily tab flashes *false empty states* ("No sleep logged yet" / "No heart rate yet today") before data
loads — reads as data loss on the most frequent moment in the app (cold open). The Dashboard cold-opens
to a blank screen (entrance gated on the network call, not `.onAppear`). And there's **no error state**
anywhere in the daily loop — a failed refresh shows `—` forever, indistinguishable from "no data".
- Fix: a `loading / empty / loaded` state machine; `SkeletonCard` from the existing `Shimmer`; a small
  inline "Couldn't sync · Retry" row. Files: `DashboardView.swift`, `DailyView.swift` (Sleep ~80, Heart
  ~528, Cycle ~282), `RecoveryView.swift`.

## Other ranked findings (deferred — backlog)
- **iOS P1:** 5-segment `.segmented` Picker is a cram → custom pill switcher (`DailyView.swift:11`);
  `stat()` copy-pasted 4× though `Metric` exists; chevron-as-string `"›"` vs SF Symbol
  (`DashboardView.swift:188`); `RecoveryView`/`SleepView` skip `titanScreen` → no top glow (lose their
  signature color); read-only "How you feel" card is usually four `—`'s → make it tappable to log.
- **web P1:** `fitness/index` crams 5 features into one mega-card above its own VO₂max hero; best-effort
  PRs aren't badged (need a controller flag comparing history) → "🏆 New 5K best" pill; Chart.js views
  (sleep/recovery) have no skeleton.
- **iOS/web P2:** robotic empty-state microcopy in places (firmware instructions vs coach voice); coach
  error uses a raw ⚠️ emoji vs the SF-Symbol vocabulary; Stack mixes emoji ☀/🌙 with Unicode ☀/☾.

The single highest-leverage remaining fix (per all three reviewers' spirit): **make the daily loop look
alive while it loads, and never tell someone they slept zero hours before the fetch finishes.**
