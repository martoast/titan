# Design: the two-tier "provisional → final" sleep summary (Whoop parity)

> **Status: Phases 1–2 BUILT & shipped (server + iOS); Phases 3–5 are proposals.** The row states are
> `computing` (loading placeholder) and `final` (settled) — an earlier draft of this doc called them
> "provisional"/"finalizing"; the shipped column is `stage_status ∈ {computing, final}`. The mobile payload
> exposes `stage_status` + `finalized_at` (via `SleepDetail::forProfile` and the nights list). Below describes
> behavior. Read [SEAL_ARCHITECTURE.md](SEAL_ARCHITECTURE.md) first; this builds directly on it and must not
> violate its invariants (I1–I8).
>
> **Goal:** the calculation runs in the natural gap between ending sleep on the watch and opening the app, so
> by the time you look, the full stats are there. If you open *before* it finishes, you get a real **loading
> state** (not a blank screen) that fills in *in place* when ready. Either way the night is **saved to the
> server**, so if you never open the summary you still see it later in your sleep history. Exactly the Whoop
> feel: it's just *ready*, and when it isn't yet, it's visibly *working*.

## 0. The user's actual flow (what we're optimizing for)

> "I end sleep on the watch, then ~2–5 minutes later I grab my phone and check. Do the calculation in that
> interval. If I open sooner, show a nice loading state so I can see it's working, then the full stats when
> they come in — right there if I'm watching, or already saved to my sleep stats if I've closed it."

Three cases to serve:

| When you open the app | What you should see |
|---|---|
| **After the gap** (the common case, ~2–5 min later) | the **full** stats, already done — no wait |
| **Before it finishes** (you opened fast) | a **loading** card (duration/bed/wake already shown, "calculating stages…") that **fills in place** when the finalize lands |
| **Never / you closed it** | nothing to do — the final row is **persisted**, waiting in your sleep history |

**Good news: the "compute in the gap" half already exists.** The moment the watch's wake marker is ingested,
`DeviceIngestionService` dispatches the confirmed `SealNightJob` immediately (`::dispatch(..., confirmed:true,
bed, wake)->afterCommit()`) — so the calculation already starts when you tap "end" (assuming the phone is
connected to sync the marker). What's missing is only the **front half of the UX**: a row exists *only after*
staging finishes, so opening early shows nothing instead of a loading state. This design adds that.

---

## 1. What "Whoop parity" means here

Whoop's actual behaviour, which we're matching:

1. **Sleep is available the moment you wake** — never a blank "come back later" screen. If it's still
   crunching, the card is present with a **"calculating…"** treatment, not absent.
2. **Numbers can settle.** Whoop shows a value and refines it as processing completes / late data syncs. Users
   accept a small settle because the card appeared instantly.
3. **Recovery cascades from sleep.** Sleep finalizing is what unblocks recovery/readiness — so the sleep
   finalize must *notify* downstream (readiness, strain target, coach) rather than them polling blind.
4. **It works whether or not the phone was present overnight** — live-streamed or morning-bulk-synced, you
   still get the instant card.

Non-goals: we are **not** trying to make the whole-night ML staging run in zero time, and we are **not**
promising the *provisional* stages are as accurate as the *final* pass. We're decoupling "show me a summary
now" from "give me the perfect stages."

---

## 2. Why there's a wait today

From `SealNightJob::sealConfirmedSession`:

- The confirmed marker gives us `[bed, wake]` instantly — so **duration/bed/wake are already free**.
- But the seal then **defers** (up to `MAX_STAGING_DEFERS = 24` × `STAGING_DEFER_S = 5s` ≈ **2 minutes**)
  waiting for the raw windows to finish `ProcessWindowJob`, *then* runs `stageScoped` → `stageSparse`
  (`staging.py::stage_night`, the joblib hypnogram model) over the whole span, *then* writes the row and
  fires `ReactToSleepConfirmed`.
- So the row (and the coach summary) appears **only after** the last window is processed and the whole-night
  staging finishes. That defer-then-stage block is the wait.

Two structural reasons it's batched (see SEAL_ARCHITECTURE I1/I4): staging wants whole-night context, and
holes/coverage/true-duration need the final `wake`. The fix isn't to remove the batch pass — it's to **write a
useful row before it and refine in place after it.**

---

## 3. The design

### 3.1 Two writes, not one

Split the single terminal write into a **computing** write (instant) and a **final** write, against the same
`SleepLog` row (same `(profile, slept_at, is_nap)` key — no new rows, so I2/I7 hold). The first write exists
purely so the app has something to show — the loading card — the moment you open it.

```
end sleep (confirmed [bed,wake] marker ingested)
      │  ← SealNightJob already dispatches here today (DeviceIngestionService)
      ▼
 ┌─────────────────────────────┐   instant — NO defer, NO staging
 │  COMPUTING write            │   duration + bed + wake from the envelope (all
 │  stage_status = computing   │   free & correct); stage columns left NULL.
 └─────────────────────────────┘   Optional: a first-pass hypnogram from epochs
      │                             already processed (§3.3) — nice, not required.
      │  the row now EXISTS → open the app early and you get the loading card
      ▼
 process remaining windows (parallel), then stage the full span ─┐
      │                                                            │  runs in your 2–5 min gap
      ▼                                                            │
 ┌─────────────────────────────┐   when the gap is up (or you open, whichever)
 │  FINAL write (refine in      │   full-span stage_night: real REM/deep/wake,
 │  place) stage_status = final │   holes folded, coverage, quality
 └─────────────────────────────┘
      │  push "sleep finalized" → card fills in place if you're watching;
      ▼  else it's just saved. readiness/strain/coach run off the FINAL row.
 downstream (ReactToSleepConfirmed / readiness / strain target)
```

The **computing** write is the whole point of this design: it turns "open early → blank screen" into "open
early → a card that shows your duration and says it's calculating." It is cheap (no staging), so it can happen
the instant the marker lands.

### 3.2 Data-model changes (`sleep_logs`)

Add:

| Column | Type | Meaning |
|---|---|---|
| `stage_status` | enum(`computing`,`final`) default `final` | is this the loading placeholder or the settled read? |
| `coverage` | decimal(4,3) null | fraction of the span actually sampled (already computed by `stage_night`; surface it so the app can caveat a low-coverage night) |
| `finalized_at` | timestamp null | when the final pass wrote (null while computing) |

`stage_status` defaults to `final` so every existing path and reader keeps working unchanged; only the new
computing write sets `computing`. Readers that must not show a half-written row filter
`where('stage_status','final')` where appropriate (long-term baselines, regularity, trends); the "last night"
card deliberately shows the `computing` row so it can render the **loading** state.

### 3.3 The computing row, and (optionally) partial stages

- **Duration / bed / wake / time-in-bed:** free from the confirmed envelope — written instantly on the
  computing row, always correct. This is enough to render a real loading card ("7h 32m — calculating stages…").
- **Stages while computing (OPTIONAL, a later nicety):** if we want the loading card to show *approximate*
  stages instead of just a spinner, run `stage_night` over **only the epochs already processed** and write a
  partial hypnogram; `coverage` says how partial. Not required for the core experience — a clean spinner over
  the known duration is perfectly good, and avoids showing numbers that then move. Defer this to Phase 3.
- If nothing is processed yet (pure bulk-sync), the computing row is simply **duration-only** — the card still
  appears instantly, stages arrive on finalize.

### 3.4 The live path (phone present, BLE up) — rolling staging

This is where we get true Whoop-like "already done by morning." Extend `ProcessWindowJob` so that as each
burst is processed it also updates a **rolling provisional hypnogram** for the in-progress night
(incremental `stage_night` over the epochs so far, written to the provisional row). Then end-of-sleep is just:
finalize. Notes:

- Rolling staging is **provisional by definition** — it lacks future context, so REM especially will shift on
  the final whole-night pass. That's fine and expected (§1.2).
- Gate it on the always-on-BLE direction (`titan-always-on-ble`): only worth running when data is actually
  arriving live. If the night is bulk-synced, skip rolling and go straight to §3.1's provisional-at-end.

### 3.5 The bulk-sync path (phone away overnight) — and S-3

If the phone was across the room, hours of windows arrive at wake in one dump. We **cannot** compute what we
don't have — but the two-tier design still wins:

1. Write the **provisional row instantly** from the envelope (duration/bed/wake) — card appears.
2. Process the backlog **in parallel** (already tunable — `UVICORN_WORKERS`/`QUEUE_WORKERS`, see
   `titan-sleep-staging-latency`).
3. **Finalize** when the backlog drains.

This also composes with the open **S-3** bug (late windows landing after a seal): a provisional row that is
still `provisional`/`finalizing` is the natural place to *accept* late windows and re-finalize, instead of
S-3's current "sealed and excluded." **Building progressive summary makes S-3 easier, not harder** — the
provisional state is exactly the "not sealed shut yet" window S-3 needs.

### 3.6 App / UX contract

- **Fetch:** `MobileSleepController::show` already returns `detail` + `assess`. Add `stage_status`,
  `coverage`, and `finalized_at` to the payload.
- **The loading state (the core of this request):** when the latest night is `computing`, the Sleep card
  renders a **loading** treatment — show the known duration + bed/wake, and a "calculating your stages…"
  progress state where the stage breakdown will go. Ideally the progress reflects reality (e.g. windows
  processed vs total, or coverage climbing) rather than an indeterminate spinner, so it visibly *works*.
- **Fills in place:** when the row flips to `final`, the same card swaps the loading block for the real
  REM/deep/light/awake/quality — no navigation, no reload. If the app is closed, nothing to do: the final
  row is saved and shows normally next time.
- **Notify, don't poll blind:** push a lightweight signal on finalize ("sleep updated"). The app already has
  the poll/reconcile-on-reopen pattern from the coach work (`titan-coach-background`); reuse it so a suspended
  phone still catches the finalize, and so a card left open on the loading state updates itself.
- **Coach summary fires once, on FINAL** (keep `sleepRowWritten` gating from I6/I4) — never narrate a
  `computing` night. Recovery/strain-target read the **final** row.

---

## 4. How this respects the seal invariants

| Invariant | How the design holds it |
|---|---|
| I1 no concatenation | the final pass stages sparse across the true span exactly as today; the computing row carries no fabricated stages |
| I2 gap-based / I7 nap≠night | same `(profile, slept_at, is_nap)` key for both writes — one row, refined |
| I4 confirmed authority | only the confirmed path writes the computing row; auto path unchanged |
| I5 failure classes | a transient failure during finalize leaves the **computing** row in place (no data loss) and retries — strictly better than today's "no row until it works" |
| I6 no clobber / no chimera | final overwrites its own computing row; `STAGE_COLUMNS` null-on-write already prevents chimera |
| I8 completed-only | a computing night is still a completed night (user ended it); unaffected |

---

## 5. Rollout (incremental, each phase shippable)

1. **Phase 1 — the computing row + loading card (this request; biggest win, lowest risk). ✅ BUILT (server + iOS).**
   Added `stage_status`/`coverage`/`finalized_at` to `sleep_logs`. `sealConfirmedSession` writes a
   **duration-only computing row before** the defer loop (`writeComputingRow`, envelope only — no staging,
   `updated_via = biosignal:computing`, only on first entry, never downgrades a `final` row), and the existing
   defer→stage block is the **finalize** that refines the same row in place and sets `stage_status = final`
   (+ `finalized_at`, `coverage`). `MobileSleepController::show` returns `stage_status`/`coverage`/`bedtime`/
   `wake_time` per night plus a top-level `last_status`. Tested: `SleepDutyCycleSealTest` (computing→final
   progression, placeholder-during-defer) + updated `SleepSealStagingDeferTest` / transient test.
   **iOS (built):** `SleepResponse` decodes `last_status` + per-night `stage_status`/`coverage` with an
   `isComputing` helper. The two pollers (`fetchSealedSleep` live path, `checkForSyncedSleep` sync/open-early
   path) keep the existing loading card up until the night FINALIZES (`!isComputing`) instead of stopping on
   the placeholder's non-zero duration; the sync path pops the loading card the instant the night appears and
   refines it in place on finalize; `sleepDetail` (Daily/Recovery) only ever takes a FINAL night, so no
   half-empty cards. `SleepSummaryView` already renders the loading state (hero "SEALING", "Staging your
   night…", watch-marker fallback tiles). Xcode build: SUCCEEDED.
2. **Phase 2 — notify + downstream cascade. ✅ BUILT (server + iOS).** Recovery now reflects the FINAL night
   and cascades the moment sleep settles. Server: `Readiness::compute` reads a `stage_status = final` night
   only (never the incomplete `computing` placeholder — until tonight finalizes it uses the last complete
   night); `ReactToDeviceSync` (the "🌅 your recovery is in" greeting) defers while tonight is still
   `computing` (reusing its "a later trigger fires this" pattern, bounded to a fresh row); the confirmed-seal
   FINALIZE re-dispatches `ReactToDeviceSync` (once-per-day, so idempotent) so the greeting goes out with the
   complete night's readiness. iOS: both sleep pollers `await self.refresh()` on finalize, so the recovery +
   strain-target cards update in place the instant sleep settles. (`ReactToSleepConfirmed` already pushed the
   sleep summary on finalize — Phase 1.) Tests: `RecoveryCascadeTest` (readiness ignores computing; greeting
   waits for finalize then fires once). Strain-target follows for free (it derives its band from readiness).
3. **Phase 3 — partial stages while computing (optional nicety).** Stage the already-processed epochs onto the
   computing row so the loading card shows approximate stages instead of just a progress spinner. Only if we
   decide the "numbers that move a little" tradeoff is worth it.
4. **Phase 4 — rolling live staging.** Incremental hypnogram in `ProcessWindowJob` on the always-on-BLE path,
   so on a live-streamed night the finalize is near-instant at wake.
5. **Phase 5 — fold in S-3.** Let a still-computing / recently-final night accept late bulk-synced windows and
   re-finalize (closes the store-and-forward data loss).
6. **(Later) workouts.** The identical pattern applies to `SealActivityJob` — instant computing run/lift from
   the confirmed envelope, refine with route/splits/HR when windows drain. Out of scope here; note it.

---

## 6. Open questions / tradeoffs

- **Core flow shows no moving numbers.** Phase 1 shows a duration + a loading state, then the *final* stages —
  the stage numbers never change under the user, because they only appear once (on finalize). The "numbers can
  move a little" tradeoff only exists if we opt into partial stages (Phase 3); if we do, gate them on a
  coverage floor (`MIN_COVERAGE = 0.30`) so the settle is small.
- **Loading progress fidelity.** Prefer a real progress read (windows processed / coverage climbing) over an
  indeterminate spinner so it visibly *works* — needs `MobileSleepController::show` (or a light status
  endpoint) to expose processed-vs-total.
- **What if the phone never synced the marker?** Then compute can't start (nothing arrived) — the auto cron
  path eventually seals it. The computing row + loading state only exist once the marker/windows land; that's
  correct (we can't show a loading card for data we don't have).
- **`computing` rows in long-term stats:** baselines/regularity/trends should filter `stage_status = final`;
  only the "last night" card shows the `computing` row (to render the loader).
- **Battery/data cost of live streaming** gates Phase 4 only. Phases 1–2 — the whole of this request — do
  **not** depend on always-on BLE and deliver the instant/loading card regardless.

---

*Cross-refs: [SEAL_ARCHITECTURE.md](SEAL_ARCHITECTURE.md) (invariants), the S-3 open item in
`tasks/reviews/2026-07-09-review-sleep-seal-and-workout.md`, and the latency/parallelism work already shipped
(sleep-staging-latency). When Phase 1 ships, promote its "what's computed when" table into the architecture
doc.*
