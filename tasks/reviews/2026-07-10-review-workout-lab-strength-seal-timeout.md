# WORKOUT LAB — strength workouts are SILENTLY LOST: seal (~117s) exceeds the 60s queue timeout

**Reviewer:** Henry (server) · **Against:** b56764d · ran both scenarios cleanly (isolated in time).

## Headline

**steady-run: GREEN — all five promises.** P5 now passes (your evening-workout tz fix works — the
streak counts the day correctly), and the default `--ingest-url` is fixed (:8080).

**gym-lift: RED — the strength workout never seals.** 14 windows land, `explicit_end` fires, and **no
ActivitySession is ever created** (P1 "never lost" fails → everything downstream cascades). This is a real
production bug, not a LAB artifact.

## Root cause — proven empirically

The strength seal WORKS when it runs to completion, but it's too slow for the queue's timeout:

- Windows sit at `status=queued`, refs empty — never sealed.
- `failed_jobs` has **10 `SealActivityJob` `TimeoutExceededException`s** (latest from this run, 22:48/22:49).
- I ran the seal synchronously, scoped + forced, on the stuck windows:
  `SealActivityJob::dispatchSync(5, true, $startEp, $endEp, 'strength', true)` →
  **completed in 117.3s**, created **session #22 (strength, 40m, 14/14 windows sealed)**.
- The queue worker command has **no `--timeout`** → Laravel default **60s**
  (`docker-compose.prod.yml:50`: `queue:work redis --queue=biosignal,default --sleep=1 --tries=3 --max-time=3600`).
- `SealActivityJob` defines `$tries=2` but **no `$timeout`**, so it inherits the worker's 60s.

**117s seal on a 60s worker timeout ⇒ every strength seal times out, retries, times out again, and the
workout is dropped.** Runs seal in <60s so they're unaffected; strength always breaches it.

## The concurrency RED earlier was mine, not a bug

My first pass ran steady-run and gym-lift 3s apart on the same lab profile; the seal merged them and the
scorecard scored the run row (P2 "lift fabricated a route", type run vs strength). Ignore it. Isolated, the
strength session #22 sealed as **type=strength, route=none** — no fabrication. The strength path is
correct; it just can't finish in time.

## Fix asks

1. **PRIMARY — give the seal room to finish.** Add `public int $timeout = 300;` to `SealActivityJob`
   (job-level is cleanest; scopes to this job without loosening the whole worker). Equivalent alternative:
   `--timeout=300` on the worker command. Without this, **strength/gym workouts are lost in production.**
2. **SECONDARY — profile the 117s.** 40 min / 14 windows taking 117s is ~8s/window — very slow. Likely
   per-window biosignal gym/activity inference or an N² in session grouping. Even at a 300s timeout, a
   denser/longer gym session will re-breach it. Profile the strength branch of the biosignal `activity`
   path + `SealActivityJob`'s grouping.
3. **CHECK PROD — has this already eaten real workouts?** Alex's real strength sessions (#9–17) did seal,
   so they were likely under 60s (fewer windows) — but audit for any recent gym workout that's missing or
   parked in quarantine from a seal timeout. If the biosignal got slower over time, real gym days may have
   started silently vanishing.

## After the fix

Re-run `workout:lab --scenario=gym-lift` — with the seal completing on the queue, we get the first honest
strength scorecard: P4 "sets belong" (the WorkoutLab logs sets onto the sealed session — it can't until the
session exists), P2 fabrication check, P3 single-row. Expect those to pass once P1 holds.

— Henry
