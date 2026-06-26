# Fixes applied overnight — 2026-06-25

Branch `overnight/hardening-and-research`. Nothing pushed. Each fix is committed
separately and gated on a build/test. **The bug-hunt fixes append below as they land.**

## ✅ Applied + verified

### 1. Coach chat: photo no longer auto-sends (the bug you reported)
- **What:** picking a photo in the coach chat fired the request immediately, with no chance to add a caption. Now it stages a thumbnail preview (tap-to-remove) in the composer; photo + note send together on submit — matching the web coach and the meal-photo flow.
- **File:** `ios/Titan/Sources/Features/CoachView.swift`
- **Verified:** iOS BUILD SUCCEEDED.
- **Commit:** `fix(ios): stage coach-chat photo in composer instead of auto-sending`

### 2. Coach chat: photo-only send was dead-ended (regression caught by the UX pass)
- **What:** after #1, `canSend` still required text, so a photo with *no* caption left Send greyed out — right under copy that says "or send." Now `canSend` is true when a photo is staged. (This was a regression my own staging change introduced; the Chesky review caught it.)
- **File:** `ios/Titan/Sources/Features/CoachView.swift`
- **Verified:** iOS BUILD SUCCEEDED.

### 3. P0 — Confirm before deleting a medication/supplement
- **What:** both delete paths (the list context-menu "Stop" and the editor's "Stop taking this") permanently destroyed a med record + its logged history on a single tap — *less protected than Sign out*, which already has a confirm dialog. Both now route through a confirmation dialog that explains the record + history are removed and points to **Pause** as the reversible alternative.
- **File:** `ios/Titan/Sources/Features/StackView.swift`
- **Verified:** iOS BUILD SUCCEEDED.
- **Commit:** `fix(ios): allow photo-only coach send; confirm before deleting a med/supplement`

---

## 📋 UX review triage (full report: `ux-chesky-pass.md`)

The Chesky pass found that *taste isn't the problem — coverage is*: warmth, confirmation, and
loading/error states live only on the happy path. I fixed the two clear **safety bugs** above
immediately. The rest I'm leaving as **proposals for your call** (they involve naming, IA, new
flows, or a design-system unification I shouldn't decide solo). Ranked by leverage:

| # | Proposal | Sev | Effort | My recommendation |
|---|----------|-----|--------|-------------------|
| P0 | **Native Login has no Sign-up / Forgot-password** — first launch assumes an account exists | P0 | M | Build next; needs a tiny bit of backend. Blocks first-thousand-users. |
| P0 | **Onboarding "Finish" swallows failure** — a network blip after 13 steps strands the user silently (`OnboardingFlow.swift` L81 discards the result) | P0 | M | Quick + high value. Surface the error + a Retry. I can do this safely — flag if you want it tonight. |
| P0 | **Web ships two design systems** — stock light Breeze (`layouts/app.blade.php`) still lurks behind premium Titan pages | P0 | S–M | Need to confirm nothing user-facing renders `<x-app-layout>`, then delete. Investigating. |
| P0/P1 | **Coach has amnesia** — no history load, greets a daily user as a stranger every launch | P0/P1 | M | The relationship is the product. API exists (`/api/coach/{id}/messages`); needs a "latest conversation" concept. Recommend building. |
| P1 | **"Today" vs "Daily" twin tabs** — indistinguishable by name/icon | P1 | M | **Your call on naming.** Suggest "Today" + "Body" (or "Vitals"). One-line change once you pick. |
| P1 | **Loading ≠ empty** — `Shimmer` skeleton exists but is used on zero screens; data screens flash blank→empty→populated | P1 | M | Safe, mechanical, big polish win. Good candidate to batch. |
| P1 | **WorkoutsView "Recent" is a hardcoded empty stub** + RecoveryView is a day-one wall of "—" | P1 | M | WorkoutsView stub looks like a real bug (never binds sessions) — will confirm in the bug hunt. |
| P1 | **One shared error/retry card** — silent failure is the most common defect across the app | P1 | M | Foundational; pairs with the loading pass. |
| P2 | **Adherence-% guilt meter** on the med record contradicts the file's own "no scoreboard" comment | P2 | S | Soften coloring / reframe. Easy. |
| P2 | **Warm the clinical/trackery web copy** (coach "biomarkers", meals "AI logs your calories", meals/confirm "estimates run ~10–25% off") | P2 | S | Safe text edits; can do anytime you greenlight. |

**My one-thing-first agreement with the reviewer:** the destructive-delete gap was the right #1 — and it's now closed on iOS. The web stack delete + the onboarding silent-finish are the next two safety gaps worth closing.
