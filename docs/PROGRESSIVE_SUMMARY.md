# Design: the two-tier "provisional → final" sleep summary (Whoop parity)

> **Status: PROPOSAL / design doc — not yet built.** This describes a target architecture, not current
> behavior. Read [SEAL_ARCHITECTURE.md](SEAL_ARCHITECTURE.md) first; this builds directly on it and must not
> violate its invariants (I1–I8).
>
> **Goal:** the instant you end sleep on the band, you see a summary — duration, bed/wake, and a first read of
> stages — with the rich numbers (REM, deep, wake events, efficiency, quality) settling within seconds
> instead of after a wait. Exactly the Whoop feel: a card that's *there immediately* and then *sharpens*.

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

Split the single terminal write into a **provisional** write and a **final** write against the same
`SleepLog` row (same `(profile, slept_at, is_nap)` key — no new rows, so I2/I7 hold).

```
end sleep (confirmed [bed,wake])
      │
      ▼
 ┌─────────────────────────────┐   instant (no defer, no staging)
 │  PROVISIONAL write          │   duration/bed/wake from the envelope +
 │  stage_status = provisional │   a first-pass hypnogram from whatever epochs
 └─────────────────────────────┘   are ALREADY processed (may be partial)
      │  push "sleep ready (finalizing)" → app shows the card now
      ▼
 process any remaining windows (parallel) ─┐
      │                                     │  ProcessWindowJob backlog drains
      ▼                                     │
 ┌─────────────────────────────┐   seconds later
 │  FINAL write (refine in      │   full-span stage_night: real REM/deep/wake,
 │  place) stage_status = final │   holes folded, coverage, quality
 └─────────────────────────────┘
      │  push "sleep finalized" → app refreshes card in place;
      ▼  readiness/strain/coach now run off the FINAL row
 downstream (ReactToSleepConfirmed / readiness / strain target)
```

### 3.2 Data-model changes (`sleep_logs`)

Add:

| Column | Type | Meaning |
|---|---|---|
| `stage_status` | enum(`provisional`,`final`) default `final` | is this the fast first read or the settled one? |
| `coverage` | decimal(4,3) null | fraction of the span actually sampled (already computed by `stage_night`; surface it so the app can caveat) |
| `finalized_at` | timestamp null | when the final pass wrote (null while provisional) |

`stage_status` defaults to `final` so every existing path and reader keeps working unchanged; only the new
provisional write sets `provisional`. Readers that must not show half-baked numbers can filter
`where('stage_status','final')` where appropriate (e.g. long-term baselines); the "last night" card
deliberately shows the provisional row *with a finalizing badge*.

### 3.3 Where provisional stages come from

- **Duration / bed / wake / time-in-bed:** free from the confirmed envelope — always instant, always correct.
- **Stages (provisional):** run `stage_night` over **only the epochs already processed at end-of-sleep**
  (skip the 2-minute defer). Whatever's staged, stage; the rest are holes. This yields a real-but-partial
  hypnogram. `coverage` tells the app how partial.
- If *nothing* is processed yet (pure bulk-sync, windows still landing), the provisional row is
  **duration-only** (exactly today's honest fallback) with `stage_status = provisional` — the card still
  appears instantly, stages fill in on finalize.

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
  `coverage`, and `finalized_at` to the payload so the card can render a **"finalizing…"** badge when
  provisional and a subtle refresh when it flips to final.
- **Notify, don't poll blind:** push a lightweight signal on both writes (provisional → "sleep ready",
  final → "sleep updated"). The app already has the poll/reconcile-on-reopen pattern from the coach work
  (`titan-coach-background`); reuse it so a suspended phone still catches the finalize.
- **Coach summary fires once, on FINAL** (keep `sleepRowWritten` gating from I6/I4) — never narrate a
  provisional night, or the coach will "correct itself." Recovery/strain-target read the **final** row.

---

## 4. How this respects the seal invariants

| Invariant | How the design holds it |
|---|---|
| I1 no concatenation | provisional & final both stage sparse across the true span; provisional just has more holes |
| I2 gap-based / I7 nap≠night | same `(profile, slept_at, is_nap)` key for both writes — one row, refined |
| I4 confirmed authority | only the confirmed path writes provisional; auto path unchanged |
| I5 failure classes | a transient failure during finalize leaves the **provisional** row in place (no data loss) and retries — strictly better than today |
| I6 no clobber / no chimera | final overwrites its own provisional row; `STAGE_COLUMNS` null-on-write already prevents chimera |
| I8 completed-only | provisional sleep is still a completed night (user ended it); unaffected |

---

## 5. Rollout (incremental, each phase shippable)

1. **Phase 1 — provisional-at-end (biggest win, lowest risk).** Add `stage_status`/`coverage`/`finalized_at`.
   In `sealConfirmedSession`: write the provisional row **before** the defer loop (envelope + already-processed
   epochs), then keep the existing defer→stage as the **finalize** that refines in place. Surface the badge in
   the app. This alone removes the ~2-minute wait for the common case.
2. **Phase 2 — notify + downstream cascade.** Push on provisional and final; move readiness/strain-target to
   trigger off the finalize event.
3. **Phase 3 — rolling live staging.** Incremental hypnogram in `ProcessWindowJob` on the always-on path, so
   the provisional card is already rich at wake.
4. **Phase 4 — fold in S-3.** Let a still-provisional/recently-final night accept late bulk-synced windows and
   re-finalize (closes the store-and-forward data loss).
5. **(Later) workouts.** The identical pattern applies to `SealActivityJob` — instant provisional run/lift
   from the confirmed envelope, refine with route/splits/HR when windows drain. Out of scope here; note it.

---

## 6. Open questions / tradeoffs

- **How much can provisional numbers move?** Set a norm (e.g. show provisional stages only above a coverage
  floor, else duration-only-provisional) so the settle is small and never embarrassing. Whoop tolerates this;
  we should pick the coverage threshold deliberately (`MIN_COVERAGE = 0.30` is the current honest floor).
- **Do we badge "finalizing" or hide stages until final?** Recommend badge — a present-but-settling card beats
  a blank one (that's the whole point).
- **Battery/data cost of live streaming** is the real gate on Phase 3 — it only pays off once always-on BLE is
  solid. Phases 1–2 do **not** depend on it and deliver the instant card regardless.
- **Provisional rows in long-term stats:** decide per-reader whether baselines exclude `provisional`
  (probably yes for regularity/trends, no for "last night").

---

*Cross-refs: [SEAL_ARCHITECTURE.md](SEAL_ARCHITECTURE.md) (invariants), the S-3 open item in
`tasks/reviews/2026-07-09-review-sleep-seal-and-workout.md`, and the latency/parallelism work already shipped
(sleep-staging-latency). When Phase 1 ships, promote its "what's computed when" table into the architecture
doc.*
