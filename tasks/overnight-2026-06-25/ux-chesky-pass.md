# Titan — Design Review (Brian Chesky pass)

_2026-06-25 · reviewer: "Chesky" · scope: what the user sees and feels (iOS native + web). BLE/sync internals out of scope._

---

## 1. Overall impression + the one thing to fix first

Titan has genuinely good taste. The native app's design language — the near-black 0x07070A canvas, the glass cards, SF-Rounded numerals, the recovery color ramp, the pulsing living details — is Whoop/Oura-grade. And when the copy is on, it's _Chesky-on_: the onboarding "why we ask" subtitles ("So your scores, macros and bio-age are tuned to you"), the Stack shelf-scan ("Lay the bottles out, take one photo — I'll read every label I can"), the band-pairing reassurance ("two bands side by side never cross-connect"), the weight goal lines ("On track → Mar 5 · 12d early"). This is a team that knows how to turn data entry into care.

The problem is not taste. **The warmth, the confirmation rigor, and the loading/error states live almost entirely on the _happy path_ — and only on a few screens.** Every unhappy path is a void: failed loads flash empty states, failed saves fail silently, destructive actions on _medical data_ fire with no confirmation, and the first-run experience for a brand-new human has dead-ends that would embarrass the product in front of its first thousand users. Meanwhile the web surface is quietly running **two different design systems** at once, one of which is stock light-theme Laravel Breeze.

**The one thing to fix first:** the app contradicts its own values where it matters most. It will permanently delete a medication on a single tap with no confirmation (StackView L654, L215) — while it _does_ gate "Sign out" behind a confirmation dialog (ProfileView L101). A health OS that protects your session more carefully than your medication record is upside-down. Fix the destructive-action gap first (it's a few hours of work, the pattern already exists in ProfileView), then close the "loading ≠ empty" and "silent failure" gaps that recur on nearly every data screen.

A structural note that sits underneath everything: the native IA has a **"Today" tab and a "Daily" tab sitting next to each other** (TitanApp L47–48) — two near-synonyms with cryptic icons (`circle.hexagongrid` vs `square.stack.3d`). No user can predict which is which. That naming collision is the single highest-leverage clarity fix in the navigation.

---

## 2. Top 10 highest-leverage changes

| # | Change | File (anchor) | Why it matters | Sev | Effort |
|---|--------|---------------|----------------|-----|--------|
| 1 | **Gate destructive med deletes** behind a confirmation + soft-delete. Pattern already exists in ProfileView's Sign-out dialog. | `StackView.swift` L654-659, L215-217 | Irreversibly destroys a medication + adherence history on one tap. Contradicts the app's own "calm, dignified" thesis and is less protected than _signing out_. | P0 | S |
| 2 | **Fix the photo-only send dead-end.** `canSend` requires non-empty text, but staging a photo with no caption leaves Send greyed — directly under copy that says "Photo ready… or send." | `CoachView.swift` L272, L188-193 | You literally cannot send a photo-only message. Core coach flow, broken. One-line fix: `!sending && (pendingImage != nil ‖ !text.isEmpty)`. | P0 | S |
| 3 | **Give first-run a way in.** Native `LoginView` has only "Sign in" — no Create Account, no Forgot Password, no SSO — yet the footer promises "Free forever · no subscription." | `LoginView.swift` L44-60 | The entire first-launch journey assumes an account already exists. New users hit a wall. | P0 | M |
| 4 | **Stop swallowing the onboarding "Finish."** `_ = await model.completeOnboarding(...)` discards the result; a network blip after 13 steps strands the user with no error, no retry. | `OnboardingFlow.swift` L81 | The most important terminal action in the funnel fails invisibly. Login shows errors; the finish line doesn't. | P0 | M |
| 5 | **Kill or quarantine the Breeze light-theme web layout.** `app.blade.php` + `navigation.blade.php` are stock Laravel Breeze (light canvas, Figtree, generic nav). | `layouts/app.blade.php`, `layouts/navigation.blade.php` | Any route still rendering `<x-app-layout>` dumps the user into a totally different, light, generic app. Sharpest cross-surface break there is. | P0 | S–M |
| 6 | **The coach has amnesia.** `CoachViewModel.messages` starts empty with no history fetch — every app open shows the blank "Your coach" empty state, even for a daily user. | `CoachView.swift` L6-12 (no `.task` load) | A "coach" you've talked to for weeks greets you as a stranger every launch. Kills the relationship the product is built on. | P0/P1 | M |
| 7 | **Rename the twin tabs.** "Today" (Dashboard, recovery overview) and "Daily" (segmented pillars) are indistinguishable by name or icon. | `TitanApp.swift` L47-48 | Users can't form a mental model of where anything lives. Rename (e.g. "Today" + "Body"/"Vitals") and pick legible icons. | P1 | M |
| 8 | **Loading ≠ empty: wire up `Shimmer`.** The design system _ships_ a skeleton loader (`Components.swift` L166) that is used in **zero** screens. Every data screen jumps blank→empty→populated. | Dashboard, DailyView (Sleep/Heart), RecoveryView, FuelView, StackView, JournalSheet | Users with real data briefly see "No sleep logged yet" / "No heart rate yet" / a wall of "—". The app looks _broken_ on the most important screens. | P1 | M |
| 9 | **Two screens are dishonest/broken on day one.** WorkoutsView "Recent" is a hardcoded stub that _always_ says "No sessions yet"; RecoveryView renders a full wall of "—" with no empty/connect state. | `WorkoutsView.swift` L35-47; `RecoveryView.swift` L5-66 | Trained users are told they never trained; new users see the hero recovery screen as a broken grid of dashes. | P1 | M |
| 10 | **One reusable error/retry treatment.** Onboarding finish, dashboard refresh, every DailyView section, every Stack add/scan — all happy-path-only. Errors appear at login and almost nowhere else. | `DashboardView.swift` L161; `DailyView.swift` L107/542/169; `StackView.swift` L454, L344 | Silent failure is the single most common defect across the app. One shared error card + retry closes most of it. | P1 | M |

**Runners-up worth doing in the same sweep:** the adherence-% scoreboard contradiction (StackView L242-250); the dose-log having no success haptic while lesser actions do (StackView L72-101); band-jargon empty states that exclude Apple-Health-only users (DailyView L84, L532); and giving the web coach page the bottom tab bar + notifications it currently loses (`titan-layout` vs `chat-shell`).

---

## 3. Per-screen notes

### Native iOS

**App shell / IA (`TitanApp.swift`)**
- L47-48: "Today" vs "Daily" twin tabs (see #7). The comment in DailyView even admits the distinction is subtle ("Today stays the recovery overview; this is where you go deep") — if it needs a code comment to explain, the user can't feel it.
- **Discoverability:** Fuel, Stack ("What you take"), Heart, Sleep, Cycle all live _inside_ a 5-segment picker on the Daily tab; Body and Devices live inside sheets on the You tab. Two of the most-promoted features (the new Fuel module and "What you take") are 2–3 taps deep with no top-level entry. Worth a hard look at whether Stack and Fuel deserve more surface.
- Color overload to watch: `amber` simultaneously means recovery=medium, the Fuel segment, "active kcal," luteal phase, and "older bio-age"; `pink` means recovery=low, the entire Cycle brand, resting HR, and "high pregnancy chance." A low-recovery pink ring next to the pink cycle UI muddies the semantic ramp. Not urgent, but the palette is carrying too many meanings.

**LoginView** — P0 first-run dead-end (#3). Also: login failure renders raw `model.error` verbatim (L39-42) — a mistyped password may show "Request failed (422)"; map to human copy. No return-key field chaining (L31-37). `canSubmit` does no email-format validation (L70). "Your open-source recovery OS" (L27) is engineer-speak as the literal first words a human reads. Token nit: login fields use `bg2`, onboarding fields use `card` — same element, two fills.

**Onboarding / OnboardingFlow** — P0 silent finish (#4). No save-and-exit / persistence (in-memory `@StateObject`, OnboardingFlow L7) — interrupt the 13-step wizard and it's all gone. "All optional" steps still require a Continue tap each — add Skip. No range sanity on height/weight (L90) — `-5` passes and corrupts macros. **GREAT:** the "why we ask" subtitles, the conditional cycle step, the EditProfile debounced auto-save with live "Saving…/Changes save automatically" status (best no-Save-button UX in the app).

**DashboardView ("Today")** — Long, competing card stack: greeting → ring → LiveSteps → HealthConnect → For You → Log-your-day → **Bio Age (`num(68)`)** → Recovery → Sleep → Activity. The Bio-Age number is rendered _larger_ than anything except the ring and reads as a second hero — scoreboard clutter under a screen that should have one clear protagonist (the recovery ring). Collapse HealthConnectCard once connected; de-emphasize secondaries. No error state on refresh (L161); no skeleton (L190 shows "—"). Nits: hardcoded `"›"` glyph vs SF `chevron.right` elsewhere; "br/m" reads like a sensor readout (use "breaths/min"); sleep quality shown as bare `"\(q)"` here but `"\(q)%"` in DailyView. **GREAT:** the time-aware, name-personalized greeting; the "Still learning your baseline" provisional pill; the bio-age delta badge ("1.4 yrs younger," mint, down-arrow) — numbers-with-meaning done right.

**DailyView ("Daily")** — Empty states flash during load (Sleep L107, Heart L542) — content pops in after a beat of "No sleep logged yet." Band-jargon empties: "Use the band's Stopwatch face — double-click to log a night" (L84) is meaningless to anyone who hasn't memorized the firmware _and_ wrong for Apple-Health-only users who can still land here. 5-segment native segmented control risks truncation on small iPhones. HrGraph has no time axis/baseline despite the doc claiming "x = time of day." **GREAT:** the teaching footnotes ("Resting HR is your daily floor — it trends down as you get fitter"); the Flo-style cycle layout that correctly ranks the friendly prediction above the scary pregnancy-chance number; the responsibly-placed disclaimer; the pulsing heart symbol.

**CoachView** — P0 photo-send dead-end (#2); P0/P1 amnesia (#6). Errors are raw, `⚠️`-prefixed (the _only_ emoji in an otherwise all-SF-Symbol app), and unrecoverable — a failed turn is a dead bubble with no retry (L39, L66). Empty-state "Run all of Titan from here" (L149) is product-jargon. `FlowChips` is a vertical stack of 4 full-width buttons — reads as a menu, not light suggestion chips. Composer is littered with magic numbers (bubble radius `20`, paddings `8/11/12/7`, `38×38`) instead of tokens. **GREAT:** token-by-token streaming, typing dots, live tool pills, voice→transcribe-into-composer-without-auto-send (correct restraint), interactive keyboard dismiss. This composer is genuinely premium — it just needs its error/empty/history states to match.

**FuelView / BodyFuel** — The model journey for "log a meal": snap → "Reading your plate…/Estimating macros with AI" → "Logged" seal with the honest "Tap Adjust if it's off." Exemplary. Watch the failed-scan path (model owns it — verify the hero doesn't silently reset). `MacroRing.unit` is declared but never rendered (numbers without units on the ring center). Loading uses a plain spinner where `Shimmer` would shine. Water quick-add (the most satisfying micro-moment) has _no haptic_ and no undo (BodyFuel L133-144). Weight rate uses Unicode `▼/▲` piped through `SectionHeader`'s always-`textFaint` slot — so a fat-loss week and a gain week are _colorless_ in an otherwise aggressively color-semantic app. **GREAT:** hydration quick-adds as Glass/Bottle/Large (tangible, not a number pad); fasting presets with personality; weight goal lines with real meaning.

**BodyView** — **GREAT:** "Then → now" compare; the gallery hook "same lighting and pose makes the comparison honest. Your coach can render your dream physique from it" (an aspiration delight). PhotoViewer is fit-only with no pinch-zoom (people _will_ zoom a progress photo). Only `AddProgressSheet`'s save shows a busy state — standardize "Saving…" across all sheets.

**RecoveryView** — P1 day-one wall of "—" (#9): no empty/connect state, no skeleton; the most important screen reads as broken on day one. "How you feel" numbers (Energy/Mood/Stress/Soreness) render as a bare "7" with no `/10` and no color, and you can't _log_ a feeling from here. `updated_via` dumped raw ("band"/"healthkit") into a section header. **GREAT:** the confidence card ("N nights of baseline," hourglass/seal icons) tells you _why_ to trust the score — the right honest, non-scoreboard touch.

**WorkoutsView** — P1 perpetual-empty stub (#9): "Recent" is hardcoded and never binds to sessions, so trained users are told "No sessions yet" forever. "Band offline" is a dead end with no connect action. **GREAT:** live BPM with numeric-text transition + PulseDot; the coach-first guidance ("Or tell the coach — 'starting a run'").

**StackView ("What you take")** — P0 destructive deletes (#1). "Stop" is ambiguous next to "Pause" (one is reversible, one annihilates). Search has no no-results and no error state; there's no manual-add path if catalog + scan both miss; `runScan` fails silently. Loading flashes the empty CTA. The **adherence %** with down-coloring (L242-250) is a guilt meter on a medication record — it directly violates this file's _own stated_ "no scoreboard, calm, dignified" comment (L8). The core dose-log action has no success haptic while `save` and `addAll` do. Two glyph vocabularies (monochrome `☀◐☾` vs full-color `🌤🌙`). **GREAT — the high-water mark for voice:** "Worth knowing" instead of "Interactions"; "N things worth knowing"; "Untick anything I misread — you can fine-tune doses & timing later"; the pinned "Informational, not medical advice — check with your pharmacist or clinician." Clone this register everywhere.

**InsightsJournal** — No empty/loading/error state for the catalog (blank void on slow/failed load). Cross-file color drift: "bad" = pink here but amber in InsightCard. **GREAT:** "Tap what happened today. Titan learns what actually moves your recovery & sleep — then tells you" explains the payoff, not the chore.

**DevicesView** — "1,240 samples · 18 uploaded" (L60-66) is engineer telemetry on a consumer screen — relabel to something felt or hide it. BPM shown twice (hero + stat row). "Forget band & re-pair" is an underlined text-link footgun with no confirm. **GREAT:** the warm power-saving statuses ("Band's on its own, saving battery…"); the pairing copy that preempts BLE anxiety; the breathing Radar; and crucially — it _shows `model.error` at all_, which is the bar the other screens should meet.

**ProfileView** — **The cohesion reference for the whole app.** Sign-out gated by a confirmation dialog (the exact pattern Stack's deletes need); consistent GlassCard + PressCard + Label + chevron rows; real spinner on health sync; "Open-source · free forever / Your data is yours, always." Only nits: the Apple Health tile overloads "connect" and a silent 30-day re-sync into one ambiguous tap; the AFib tip reads like a settings manual.

### Web (Blade + Tailwind + Alpine)

- **Two design systems shipping at once** (#5). `titan-layout`/`chat-shell` are premium near-black; `app.blade.php`/`navigation.blade.php` are stock light Breeze. Confirm nothing user-facing renders the latter and delete it.
- **Coach page breaks the navigation model.** The bottom 5-tab bar and the notification bell live only in `titan-layout`; `chat-shell` (the coach) has neither — so on "the centre of the app" navigation silently becomes a hamburger drawer and notifications vanish. Also: the wordmark links to `/coach` in chat-shell but `/dashboard` in titan-layout — two different "home" destinations.
- **Accent + macro color divergence from iOS.** Web's brand is indigo→cyan everywhere (wordmark, buttons, blobs); iOS's signature is the mint/amber/pink recovery ramp. Macro colors are a free-for-all: meals uses protein=rose/carbs=amber/fat=sky, dashboard uses indigo→cyan, progress uses cyan/indigo/emerald — and iOS has yet another set. Pick one macro language and one accent identity across both surfaces.
- **`duo-stat` is pure scoreboard** — props `aWin`/`bWin`, comments about "the leader," winner colored vs loser. Gamifying your own body data is the opposite of the calm-witness tone. Reframe as "then → now" or "you vs your target" without win/lose coloring.
- **`meals/index` splits mid-page** — premium `rounded-2xl border-white/5` cards up top, older flat `rounded-xl bg-gray-900/50` below. Same screen, two eras. And Chart.js is pulled from a CDN at runtime (silently disappears offline in a PWA meant to work offline) while `progress` hand-rolls SVG sparklines — pick one charting language and bundle it.
- **Contrast:** `text-gray-600` is used for real readable content on the near-black canvas in multiple places (nav group labels, notification timestamps, stack tips) — under WCAG AA. Bump to gray-400/500.
- **`stack/index` is the web high-water mark** (closest to iOS tone) — but `window.location.reload()` after add breaks the SPA-smooth feel, and `addShelf()` fires N POSTs reporting success even if some fail.
- **Clinical/trackery copy to warm:** coach empty "I can see your **biomarkers**…Ask me anything" → "I can see how you're sleeping, eating, training and recovering. What's on your mind?"; meals "Snap a photo — AI logs your calories & macros" → "Snap a photo — I'll handle the macros."; meals/confirm "AI meal estimates run ~10–25% off" (planting doubt at the moment of trust) → "Give it a quick check — tweak anything that looks off before saving."; progress empty states that tell users the literal command to type ("type _log a progress photo_") → give them a button.
- **GREAT:** the coach chat's loading/feedback layer (tool status, bouncing dots, jump-to-latest, suggestion chips, warm stream-fail fallbacks, client-side image compression) is best-in-class and should be the reference; the dashboard's "How you are, and what's next," amber-not-red overdue logic, and Future Self panel are emotionally intelligent.

---

## 4. Delight / 11-star ideas

1. **Make the coach _remember out loud._** On open, instead of a cold "Your coach," greet with continuity: "Morning, Alex — you slept 7h12 and your recovery's green. Want to plan today?" Loading history (fixing #6) is the prerequisite; the payoff is the relationship.
2. **Celebrate the small wins the user already earns.** The dose-log tap, the water quick-add, a finished fast — give them a `Haptic.success` and a 200ms flourish. Right now lesser actions celebrate and the most-repeated ones are silent.
3. **A nightly "seal of the day."** When the night seals, a single calm card: "Your day is in. Recovery's looking strong — sleep well." One witness, not a scoreboard.
4. **Render the dream physique.** BodyView already teases "Your coach can render your dream physique" — actually shipping a tasteful AI projection from the user's own progress photos is a genuine 11-star, share-worthy moment.
5. **Bio-age as a story, not a number.** Instead of `68` rendered huge, animate "Your body is moving 1.4 years younger than the calendar" with the delta as the hero and the number as support.
6. **Skeletons that breathe.** Wiring `Shimmer` (#8) isn't just a bug-fix — a recovery ring that shimmers into focus on open feels _alive_ where a blank ring feels broken.
7. **"What changed" on the recovery screen.** When recovery moves, one line of cause from the correlation engine: "Down 8 — likely the late workout + 1 drink." That's the moat made felt.

---

## 5. Cross-surface consistency notes

- **Tone:** iOS and the _best_ web pages (stack, coach, dashboard) share the calm-witness voice. The gap is _within_ each surface, not between them: older web pages (meals, progress) and iOS's empty/error states haven't caught up to the standard the stack page and coach composer already set. The fix is propagation, not redesign — clone the high-water marks.
- **Navigation model diverges:** iOS = 4 bottom tabs; web = 5 bottom tabs _except_ the coach page (hamburger). Align the web coach page to the bottom bar.
- **Color language is fragmented across surfaces:** the recovery ramp (mint/amber/pink) is iOS's identity; the web leads with indigo→cyan; macros have ~4 different palettes between the two. Define one shared token set (recovery ramp + one macro language + one accent) and apply it to both.
- **"Home" is ambiguous on web** (logo → /coach vs /dashboard depending on shell). iOS is unambiguous (Coach is tab 1). Pick one web home.
- **Loading/empty/error completeness is the universal gap on both surfaces.** The components exist (iOS `Shimmer`; web's coach loaders, stack toasts); they're just not applied to the screens that need them. A single "every data view has a loading, empty, and error state" pass would lift the whole product a full star.

_— Chesky_
