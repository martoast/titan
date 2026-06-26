# The Stack — supplements & medications module

> Status: **BUILT** (v1, 2026-06-25). Research verified via deep-research workflow (106 agents,
> 23 confirmed claims) → designed → Brian Chesky design review → implemented (Laravel + Blade +
> native iOS) → Brian Chesky UI polish pass applied. Backend: 13 tests passing. iOS: BUILD
> SUCCEEDED. Web: all views compile. §8 is the governing design; §9 below records what shipped.

---

## 1. What we're building & why

Today Titan tracks what you **eat**, how you **train**, **sleep**, and your **HRV/recovery**.
It does **not** track what you **take** — supplements and prescription/OTC meds. That's a hole in
the "forensic profile." Once the coach knows your full intake (dose + timing), it can:

1. **Cross-reference interactions** — drug↔drug, drug↔supplement, supplement↔supplement.
2. **Correlate intake against biosignals** — "your HRV runs ~6ms higher on magnesium nights."

This is the **open wedge**: no incumbent unifies meds + supplements **and** correlates them
against objective wearable data.

### Competitive landscape (verified teardown)

| App | Owns | Gap for us |
|---|---|---|
| **Medisafe** | Med reminders, refills, 4-tier interaction warnings, caregiver share. Going **paid Jan 2026**. | Meds-first (supps secondary), no biosignals, no coach |
| **Apple Medications** (free, built-in) | Logs meds **and** supps, pill-bottle scan, US-only interaction alerts | No correlation, no coach. **This is the "free + already on the phone" bar we must clear** |
| **MyTherapy** (free) | Confirm-from-notification logging (taken/skipped) + per-dose notes | No supps depth, no biosignals |
| **SuppTrack** (~$15/yr) | Barcode→exact variant (189k products), flexible per-supp schedules | **No meds, no outcome correlation** |
| **Supplements AI – Stack Tracker** | Supp↔supp synergy/interference flags, correlation engine | Correlates vs **subjective** self-report, not wearable; supps-only; not a real coach |
| **WHOOP Journal** | 300+ behaviors → Behavior Insights (stat-correlates to Recovery; needs ≥5 yes/≥5 no in 90d) | **Binary** logging (no dose/timing), no interaction checks |

**Takeaway:** every competitor leaves exactly one of {meds+supps unified, dose/timing precision,
objective-biosignal correlation, conversational coach}. Titan can be the only one with all four.

---

## 2. Data source decision (free/open path — no licensing)

Verified, fully free/open stack for v1 (no Medi-Span / First Databank / DrugBank license):

- **Supplement catalog + ingredient identity** → **NIH DSLD API** (`api.ods.od.nih.gov/dsld/v9/`).
  CC0 public domain, works **no-auth** at 1,000 req/hr, free key → 10,000/hr. Search, label-by-id,
  brand/ingredient endpoints. US labels only.
- **Drug normalization** → **RxNorm REST API** (free, NLM) → resolve names to RxCUIs.
  ⚠️ NLM's old RxNav **drug-drug interaction** API was **discontinued (~Jan 2024)** — we do NOT
  rely on it for interactions, only for name→RxCUI normalization.
- **Interactions** → **DDInter 2.0** (open drug-drug data) keyed by RxCUI, with **openFDA Drug
  Label API** text as fallback. The open-source **PillChecker** repo proves this pipeline
  (MIT). ⚠️ Treat it as a **reference architecture to re-implement + validate**, not a turnkey dep.

**v1 stance:** ship interactions as a **disclaimered "informational" tier** (literature/label
info, severity shown, source cited). Licensed clinical DB is a later upgrade if we need higher
coverage/accuracy.

---

## 3. Regulatory guardrails (US, table-stakes — built into the design)

Verified against FDA primary guidance + 6 regulatory law firms:

- **Enforcement-discretion safe harbor exists** for software that returns whether a supplement/drug
  pair has **reported interactions in the literature** + a summary. Stay inside this example.
- **2026 General Wellness guidance** keeps us out of device regulation IF we: stay
  informational, make **no disease-specific claims**, never label outputs
  "abnormal/pathological/diagnostic," give **no individualized treatment recommendations**, and
  **route users to a clinician/pharmacist**.
- **Design consequences (load-bearing, not optional):**
  - Persistent **"Informational, not medical advice — check with your pharmacist or clinician"**
    on every interaction/insight surface.
  - Interaction copy = "reported in the literature / on the label," never "you should stop X."
  - Coach correlations framed as **observations** ("nights you take magnesium your HRV is higher"),
    never prescriptions ("take magnesium to fix your HRV").
  - **Legal review before launch** (one regulatory sub-claim was a 2-1 split — genuine ambiguity).

---

## 4. The model (two concepts)

1. **Stack item** = a thing you take, with a schedule. The persistent protocol.
   `stack_items`: profile_id, name, kind (supplement|medication|other), brand, dose_amount,
   dose_unit, form, dsld_id?, rxcui?, schedule(json: times, days, with_food), active, started_on,
   ended_on, photo_path, notes.
2. **Intake event** = an actual taken/skipped/extra dose. The dated log that feeds adherence +
   correlation. `intake_events`: profile_id, stack_item_id?, name, dose_amount, dose_unit,
   taken_at, status (taken|skipped|extra), source (manual|notification|coach|photo), notes.
3. (Derived/cached) **interaction_flags**: profile_id, item_a, item_b, severity, summary, source,
   checked_at.

Mirrors the existing `meals` / `meal_items` + `Macros::today()` patterns exactly (profile-scoped,
`source` enum, support-class builder for the coach context).

---

## 5. The screens

### Screen A — "Stack" section on the **Daily** page (the everyday surface)
A peer card next to Fuel. The ONLY thing here is today's doses as a calm checklist.

```
┌─ Your Stack ───────────────── 3 of 6 today ─┐
│                                              │
│  ☀  Morning                                  │
│   ✓  Vitamin D3      5000 IU                  │
│   ✓  Creatine        5 g                      │
│   ◯  Omega-3         2 caps      [ take ]     │
│                                              │
│  ☾  Evening                                  │
│   ◯  Magnesium       400 mg      [ take ]     │
│   ◯  Lisinopril      10 mg  ℞    [ take ]     │
│                                              │
│  ⓘ 2 things worth knowing about your stack → │
│                                              │
│  [ + log something ]      [ ◎ snap a bottle ]│
└──────────────────────────────────────────────┘
```
- Grouped by time-of-day, not a flat list. Taken rows dim/collapse.
- One tap = logged (creates an `intake_event`, status=taken). Long-press = skipped + note.
- `℞` glyph quietly marks medications. No alarming colors anywhere.
- The "things worth knowing" chip is **informational blue**, never red.

### Screen B — Add / quick-log (mirrors Fuel's snap/text/manual)
```
┌─ Add to your stack ─────────────────────────┐
│  ◎ Snap the bottle    ▦ Scan barcode         │
│  ⌕ Search ___________________________        │
│     vitamin d3                               │
│     • Vitamin D3 5000 IU — NOW Foods   [add] │
│     • Vitamin D3 2000 IU — Nature Made [add] │
│  ─────────────────────────────────────────  │
│  Dose  [ 5000 ] [ IU ▾ ]   Form [ softgel ▾]│
│  When  ☀ Morning  ☐ Midday  ☐ Eve  ☐ Night  │
│  Repeat  ● Daily  ○ Specific days  ○ As needed│
│  With food?  ○ yes ● no    Type ● Supp ○ Med │
│                                   [ Save ]   │
└──────────────────────────────────────────────┘
```
- **Snap the bottle** reuses `ScanService` vision → prefills name/dose/brand from the label.
- **Search** hits DSLD (supps) / RxNorm (meds). Picking a result stores `dsld_id`/`rxcui` so
  interaction checks work later.
- Coach-native shortcut: tell the coach *"add creatine 5g every morning"* → same record via a
  `log_supplement` / `add_stack_item` tool.

### Screen C — My Stack (full protocol)
```
┌─ My Stack ─────────────── ⓘ what's worth knowing →┐
│  SUPPLEMENTS                                       │
│   Vitamin D3   5000 IU · ☀ daily    ▁▃▅▇▇▅ 92%    │
│   Creatine     5 g     · ☀ daily    ▇▇▇▇▇▇ 100%   │
│   Magnesium    400 mg  · ☾ daily    ▅▇▃▇▅▇ 78%    │
│  MEDICATIONS                                       │
│   Lisinopril   10 mg ℞ · ☾ daily    ▇▇▇▇▇▇ 100%   │
│   each row → edit · pause · stop                   │
└────────────────────────────────────────────────────┘
```
- Adherence sparkline per item (last ~14d). Pause/stop instead of delete (preserves history).

### Screen D — "What's worth knowing" (the forensic / interaction view — the differentiator)
```
┌─ Worth knowing about your stack ─────────────┐
│  Informational, not medical advice. Check     │
│  with your pharmacist or clinician.        ⓘ │
│                                              │
│  INTERACTIONS REPORTED IN THE LITERATURE     │
│  ● Magnesium + Lisinopril      moderate      │
│    May enhance blood-pressure lowering.       │
│    Source: openFDA label · DDInter           │
│  ● Calcium + Iron              timing         │
│    Compete for absorption — space them apart. │
│                                              │
│  TIMING SUGGESTIONS                           │
│  • Magnesium at night may aid sleep/HRV       │
│  • Take iron away from your morning calcium    │
│                                              │
│  [ Ask the coach about my stack ]            │
└──────────────────────────────────────────────┘
```
- Severity tiers (info / timing / moderate / major), plain language, **always cited**, disclaimer pinned.

### Screen E — Coach correlation (surfaced in chat / insight feed, not a page)
> *"Quick pattern: across the last 3 weeks, your HRV averages ~6ms higher on nights you logged
> magnesium vs nights you didn't. Worth keeping an eye on — not medical advice."*

- Dose+timing aware, uses the existing correlation/insight engine. Observation framing only.
- Needs data density before firing (WHOOP uses ≥5 yes / ≥5 no in 90d — adopt a similar gate).

---

## 6. v1 scope (must-have vs later)

**MUST (v1):**
- Unified log for supplements **and** meds (the gap).
- Fast add: DSLD/RxNorm search + barcode + **snap-the-bottle** (reuse ScanService).
- Dose + timing capture; flexible schedules (times/day, weekdays, with-food).
- Today checklist on Daily page; one-tap taken / long-press skipped + note.
- Interaction checking (drug↔drug, drug↔supp, supp↔supp) via free stack, informational tier, cited.
- Persistent "not medical advice" + consult-clinician routing.
- Coach tools: `add_stack_item`, `log_intake`, `recent_intake`, `check_interactions`; stack folded
  into the daily profile the coach reads.

**LATER (nice-to-have):**
- Refill / low-supply reminders.
- Adherence **streaks — opt-in, default OFF** (research: users resent forced gamification).
- Caregiver/share report; food/alcohol cautions; licensed clinical interaction DB upgrade.

---

## 7. Open questions (carry into build)
- Real-world coverage/accuracy of the free DDInter+openFDA pipeline vs licensed DBs — good enough
  for a disclaimered v1, or only a thin tier?
- Min data density before correlation insights are trustworthy (WHOOP-style threshold)?
- Exact UI copy that keeps the interaction feature inside FDA enforcement discretion (needs counsel).
- **Naming:** RESOLVED in §8 → surface is **"What you take"** (coach may say "stack" casually).

---

## 8. Post-review revision (Brian Chesky pass) — GOVERNING DESIGN

> This section supersedes §5–§6 wherever they conflict. The review's verdict: §1–§4 (research,
> data, regulatory, model) are strong; the **screens were a database with a form on top.** Cut to
> the moment.

### North star: build a *witness*, not a tracker
Competitors all charge a **tax** (log the thing) for a **chore** (a checklist). Titan already knows
how you slept, how your heart behaved, how you trained. So the product is: **you do almost nothing,
and a few weeks later the app tells you something true about your own body you couldn't have known.**
Logging is just the cost of admission — drive it toward zero. Build everything backwards from one
sentence:

> *"Quiet pattern — your HRV runs ~6ms higher on the nights you take magnesium. Not advice, just
> something your own data is showing."*

That sentence — coach-delivered, unprompted, specific to **your** body — is the single shareable
moment. It's also (the beautiful part) **both** the legally-required framing (observation, not advice)
**and** the emotionally-best framing. The constraint is a gift.

### From 5 screens → 3 surfaces + the coach

#### Surface 1 — "What you take" card on the **Daily** page (the heartbeat)
```
┌─ What you take ───────────────────────┐
│  ☀ Morning                            │
│   ✓  Vitamin D3   5000 IU             │
│   ✓  Creatine     5 g                 │
│   ◯  Omega-3      2 caps     [ take ] │
│                                       │
│   Morning stack — almost there        │
│                                  [ + ]│
└────────────────────────────────────────┘
```
- **Kill the "3 of 6" scoreboard** — a fraction counter on a medical checklist breeds guilt the day
  you miss your lisinopril, and it contradicts our own "no forced gamification" stance. Progress is
  carried by the checkmarks, not a score.
- **Collapse evening until afternoon.** At 7am, half-asleep, I see my morning three with big tap
  targets — nothing else.
- **One `+` affordance**, not two equal-weight CTAs ("log something" / "snap a bottle").
- **Calm closure, not confetti.** When morning's done: a quiet "Morning stack — done ☀." The feeling
  we sell is *"I'm taking care of myself,"* not *"I scored points"* (like making your bed).
- **Occasional personalized whisper** (not every time): tapping magnesium at night → *"nice — this is
  the one your HRV likes."* Turns a chore into a relationship.

#### Surface 2 — Add (TWO first-class paths, one clean entry)
The `+` opens a sheet with exactly two choices — no wall of mechanisms:
```
┌─ Add to what you take ───────────────┐
│   ⌕  Add one  ___________________     │  ← type or tap 📷 to snap one bottle
│   ────────────────────────────────   │
│   🗂  Add my whole shelf  →           │  ← batch photo, coach reconciles
└────────────────────────────────────────┘
```

**Path 1 — Add one (the everyday path; what the user does most).** Fast and *progressive* — fields
appear as you go, never all at once:
1. Type a name (DSLD/RxNorm search) **or** tap 📷 to snap a single bottle (ScanService prefills
   name/dose/brand).
2. Pick the result → dose + unit are pre-filled from the label; you just confirm.
3. One row of timing chips: `☀ ☐ ☾ · daily ▾ · with food ☐`. That's it. Save.
   - Picking a search/scan result stores `dsld_id`/`rxcui` so interaction checks work later.
   - Type toggle (supp/med) is auto-inferred from the catalog hit; only shown if ambiguous.

**Path 2 — Add my whole shelf (the magic on-ramp; first run / big changes).** Lay the bottles out →
**one photo** → vision pulls every label it can → the **coach reconciles the rest conversationally**:
*"I got five of these. The white bottle — turn it so I can read it? And when do you take each?"*
**Whole regimen in ~20 seconds, zero typing.** Reuses vision + coach tools we already own.

**Coach-native (always available, both paths):** *"add creatine 5g every morning"* in chat → same
record via the `add_stack_item` tool.

**Kept but off the front door:** barcode (power-user exact-variant precision, lives inside "Add one"
behind the 📷 as a "scan barcode" option) and the full manual dose/schedule form (the editor you land
in from a row in Surface 3, or when the catalog has no match). Neither clutters the entry sheet.

#### Surface 3 — "What you take" full view (**merged** old Screen C + D)
The list **and** what the app knows *about* the list, in one place (splitting them buried the
insight on a page nobody visits).
```
┌─ What you take ──────────────────────────────┐
│  Vitamin D3   5000 IU · ☀ daily              │
│  Creatine     5 g     · ☀ daily              │
│  Magnesium    400 mg  · ☾ daily              │
│  Lisinopril   10 mg ℞ · ☾ daily              │
│  ───────────────────────────────────────────│
│  Worth knowing                               │
│  ● Magnesium + Lisinopril — may enhance BP    │
│    lowering. Reported on the label.           │
│  ● Space calcium & iron apart (absorption).   │
│  Informational, not medical advice — check    │
│  with your pharmacist or clinician.        ⓘ │
└────────────────────────────────────────────────┘
```
- Adherence sparklines **folded in** (tap a row), not the headline.
- `edit · pause · stop` on **swipe / row-detail**, not three always-visible verbs per row.
- Safety flags live here **calm, cited, disclaimer pinned** — but they also get pushed once (below).

#### The coach (where the magic actually lives)
- **Interactions** = safety insurance. Pushed **once, gently, the moment they become true** (the
  instant you add lisinopril to a stack with magnesium the coach mentions it), then filed in
  Surface 3. Don't make anyone discover a safety flag by visiting a page.
- **Correlations** = the magic. **Only** via the coach, unprompted, **gated by data density**
  (WHOOP-style ≥5/≥5-in-90d). Never a dashboard — a dashboard invites staring at noise before the
  data's ready and reads as clinical/creepy. Coach-noticing reads as *caring*.
- **Trust rules:** specific numbers from *their* body ("~6ms over 3 weeks" beats "may improve HRV");
  tentative voice ("quiet pattern…"); observation never instruction; **earn it before you say it**
  (show nothing for 3 weeks rather than noise on day two — a wrong early correlation poisons trust
  forever).

### Naming — DECISION: **"What you take"**
- "The Stack" = fitness-insider flex; faintly undignified for the 58-year-old on a BP prescription
  (a med is necessity, not a flex). "Supplements & Meds" = joyless filing-cabinet label.
- **"What you take"** is plain, human, and covers a vitamin and a prescription with **equal
  dignity**. Name it for the person you might lose, not the one you've already won. The coach mirrors
  the user's own language ("your stack") casually. (Fallback if too plain: "Intake.")

### Killed from v1 (discipline)
- Standalone interaction screen (merged into Surface 3 + coach push).
- Barcode on the hero add screen (demoted to fallback).
- The "3 of 6" scoreboard (and don't let streaks sneak back in as a counter in disguise).
- The full manual schedule form as a primary path (fallback editor only).
- Two equal-weight CTAs on the Daily card (→ one `+`).

---

## 9. What shipped (v1)

**Backend (Laravel, 13 tests green):**
- Migrations: `stack_items`, `intake_events`, `interaction_flags`. Models + `Profile` relationships.
- `App\Support\Stack::today()` — the calm daily card (grouped by time-of-day, no scoreboard).
- `App\Services\Stack\SupplementCatalog` (DSLD + RxNorm + instant offline built-ins),
  `InteractionChecker` (seed pairs + openFDA fallback, informational), `StackScanService`
  (snap-one / snap-the-shelf vision).
- `Api\MobileStackController` (`/api/me/stack` family) + web `Stack\StackController` (`/stack`).
- Coach tools: `my_stack`, `add_stack_item`, `log_intake`, `recent_intake`, `check_interactions`
  (gated under a new `stack` trigger group). Tests: `tests/Feature/StackTest.php`.

**Web (Blade):** `resources/views/stack/index.blade.php` (today checklist → full protocol →
worth-knowing), two-path add, nav entry via `app/Support/Nav.php`.

**iOS (SwiftUI):** `Features/StackView.swift` (`StackSection` on `DailyView` + full `StackView` +
`AddToStackSheet`), `APIClient`/`AppModel`/`Models.swift` wired.

**Polish pass applied (both platforms):** "What you take" naming (no "stack" in chrome), major
interaction = warm amber (never red), no per-slot scoreboard, "{slot} — done ☀" closure beat,
zero-typing whole-shelf flow, first-person coach voice, warmer empty states, 44pt tap targets,
Type auto-inferred (+ "Other" parity), aligned reveal hours (evening 16 / night 20).

**Deferred (fast-follow, not v1):** the post-take personalized whisper ("this is the one your HRV
likes") — needs a coach/server message on the intake response; a one-off "quick log" front door
(API exists: `logQuickIntake`); a coach deep-link from the stack screen; correlation insights
(ride on accumulated intake data + the existing insight engine, gated by data density); licensed
clinical interaction DB upgrade. **Legal review of interaction copy before public launch** (§3).
