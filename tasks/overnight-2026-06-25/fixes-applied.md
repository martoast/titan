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

---

## 🐛 Bug-hunt fixes (all committed on the branch, each tested)

Full findings in `bug-hunt-report.md`. I fixed every HIGH plus the high-confidence MED/LOW issues
that were clearly correct and testable. Each commit is self-contained.

### Backend — security & correctness (PHP suite green throughout)
| # | Fix | Where | Verified |
|---|-----|-------|----------|
| 1/2 (HIGH) | **Read-scoped tokens could write.** `auth.any` now requires the `write` ability for any non-safe HTTP method — a read-only token (handed to an external agent) gets 403 on every write; session owners bypass; the MCP `auth.token` surface is unchanged (gates per-tool). | `AuthenticateSessionOrToken.php` | +3 tests (`MobileApiAccessTest`) |
| 5 (HIGH) | **Carbon 3 merged all workouts into one session.** Signed `diffInMinutes` made the 20-min split never fire → corrupted type/TRIMP/VO₂max. Took the magnitude; audited the rest. | `SealActivityJob.php` | +1 test (`ActivitySealTest`) |
| 6+13 (HIGH/MED) | **Confirmed morning sleep summary never fired across midnight.** Night was keyed by bedtime vs the window_end grouping; and the quiescence gate skipped the just-woke night. Keyed by wake date + confirmed seals bypass quiescence (also stops the cron racing it). | `DeviceIngestionService.php`, `SealNightJob.php` | +2 tests (`SleepSummaryTest`) |
| 21 (LOW) | **Raw tool-exception text leaked into the chat** (SQL/paths). Log server-side, return a generic string. | `AiService.php`, `CoachTools.php` | 147 coach tests green |

### Stack module — my own freshly-built code (StackTest green)
| # | Fix | Where |
|---|-----|-------|
| 14/9 (MED) | openFDA interaction lookups now **cached per drug-pair (7d, success-only) + capped** per refresh — no more seconds of serial network in the save path. | `InteractionChecker.php` |
| 15 (MED) | Interaction flags computed first, then **swapped in inside a transaction** — a slow/failed lookup can't leave a stack with zero warnings. | `InteractionChecker.php` |
| 16 (MED) | DSLD/RxNorm: a transient timeout **no longer caches "empty" for 24h** — only successful responses are cached. | `SupplementCatalog.php` |
| 22/33 (LOW) | **Adherence N+1 killed** — batched `takenCounts` (one grouped query) + shared profile relation, on web + mobile. | `StackItem.php`, both controllers, blade | +2 tests |

### iOS BandSync — data integrity (the band streams tonight; BUILD SUCCEEDED, TitanCore 25 green)
| # | Fix | Where |
|---|-----|-------|
| 3 (HIGH) | **Sync-drain deadlock** dropping a corrupt row (re-entrant NSLock) → unlocked `deleteRow` helper; also recover a corrupt DB on open. | `SqliteWindowStore.swift` |
| 4 (HIGH) | **Duplicate uploads on retry** — `batch_uid` was a fresh ULID per attempt; now derived from window content so the server dedups. | `IngestClient.swift` |
| 12 (MED) | **Dropped windows on a failed write** — bounded in-memory fallback retries persistence + logging. | `SyncQueue.swift` |
| 32 (LOW) | **HR-trend fragmentation** from out-of-order T5 — monotonic bucket guard. | `Windowing.swift` (+test) |
| 31 (LOW) | **Corrupt first frame after reconnect** — clear the byte accumulator on disconnect + cap it. | `FrameRouter.swift` |

### Biosignal — input hardening (uncaught 500s)
| # | Fix | Where |
|---|-----|-------|
| 18 (LOW) | `accel_fs`/`fs` `gt=0, le=1000` — `fs=0` no longer ZeroDivisions to a 500. | gym/activity/function routers |
| 19 (LOW) | RunCapture **equal-length** validator (hr vs speed) → 422 not broadcast-500. | `fitness.py` |
| 17 (LOW) | NaN/Inf scalar inputs rejected with a clean 422 (a route guard — not `allow_inf_nan`, which would echo NaN into the error body and re-crash). | `fitness.py` |
| 7 (MED) | Sleep staging **clamps epochs to ~48h** — a tiny payload with a huge span can't allocate millions of epochs (DoS). | `staging.py` |

_+5 tests (`test_input_validation.py`); full biosignal regression suite re-run._

### Firmware
| # | Fix | Where |
|---|-----|-------|
| 10 (MED) | **Bridge dropped a batch forever on any POST failure** — re-queue un-sent samples (bounded) so a network blip / config typo doesn't lose live PPG. | `bridge.html` |

### Deferred → proposals (need a decision, schema change, or can't be safely verified blind)
- **#8** indirect prompt injection (web/vision → write tools) — needs a trust-boundary policy + confirm-before-write. Design proposal.
- **#25** stored `device_token_hash` *is* the HMAC signing key — a DB read forges any device. Needs key-rotation / encrypt-at-rest + re-pair.
- **#11** bridge ingests only T1 (drops live T4/T5/T7/T8/T9) — design call (parse them, or have firmware also flash them when connected).
- **#23** `food_facts` shared-cache uniqueness lost to MySQL NULL semantics — needs a partial/sentinel unique index (schema).
- **#26/27/28/29** gzip-bomb bound, device GET rate-limit, login timing-enum, signature method/path binding — security hardening, mostly quick but some touch infra.
- **#20** biosignal auth fails open on empty token — intentional for local/tests; fail-closed-in-prod needs an env flag (would change the test harness).
- **#24** firmware ring-buffer byte accounting resets on reboot — real, but can't verify on-watch blind; needs device testing.
- **#30** iOS upload queue unbounded / no real backoff — worth a follow-up (drop poison head after N attempts + actual backoff).
