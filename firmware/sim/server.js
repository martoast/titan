'use strict';

// A faithful JS mirror of the SERVER-SIDE sleep seal — the piece the phone model (phone.js) hands
// off to once a confirmed wake marker (T9) + the night's PPG windows are delivered/uploaded. It
// mirrors, decision-for-decision:
//   • DeviceIngestionService::triggerSleepSummary  (a confirmed marker → dispatch a scoped seal)
//   • SealNightJob::sealSleepFromPpg / sealSleep    (stage the session → one sleep_logs row)
//   • SealNightJob phantom guard                    (never write an all-awake degenerate "night")
// KEEP IN SYNC with those files. `buggy:true` reproduces the pre-fix server so a scenario proves the
// sim catches the real regression (the lost nap + the "Awake 100% / 8h15m" phantom).
//
// The band delivers two things the server seals from:
//   • a confirmed marker  { bedtime, wake }  (epoch seconds — the user's real session bounds)
//   • ppg_raw windows      { startSec, endSec, asleep }  (30-s burst windows; asleep=low-motion epochs)
// The server's job: turn the CONFIRMED SESSION into exactly one sleep_logs row scoped to the marker.

const MIN_SLEEP_MIN = 20;    // SealNightJob::MIN_SLEEP_MIN — below this a tap isn't a nap (don't seal)
const NAP_MAX_MIN = 240;     // < 4 h ⇒ a nap (its own row, keyed by session_start, never clobbers a night)

class Server {
  constructor(opts = {}) {
    this.buggy = !!opts.buggy;   // pre-fix: group by calendar DATE, vacuum every same-day window, no guaranteed write
    this.windows = [];           // uploaded ppg_raw windows: { startSec, endSec, asleep }
    this.sleepLogs = [];         // sealed rows: { sleptAt, sessionStart, isNap, durationMin, asleepMin, awakeMin, allAwake }
    this.workoutWindows = [];    // uploaded kind=workout windows: { startSec, endSec, kind, sealedId }
    this.activitySessions = [];  // sealed rows: { startedAt, endedAt, durationMin, activityType, source }
    this.log = [];
  }

  // A ppg_raw window landed (ProcessWindowJob upsert). asleep = the epochs staged as sleep, not wake.
  ingestWindow(w) { this.windows.push(w); }

  // A kind=workout accel/HR window landed (the T6/T5 stream, live or backlog-flushed). No `kind` = an
  // auto workout the classifier must label; a set `kind` = the watch's chosen type on that window.
  ingestWorkoutWindow(w) { this.workoutWindows.push({ ...w, sealedId: null }); }

  // Local calendar date (UTC in the sim) for a given epoch-second — how the buggy path groups a "night".
  _dateOf(sec) { return Math.floor(sec / 86400); }

  // A confirmed wake marker arrived (T9, confirmed=1). Seal THIS session → one sleep_logs row.
  sealConfirmed(marker) {
    const durMin = Math.round((marker.wake - marker.bedtime) / 60);

    if (this.buggy) {
      // PRE-FIX: ignore the marker's [bedtime, wake]; group by the wake's calendar DATE and vacuum
      // EVERY unsealed window on that date (incl. unrelated day bursts). No guaranteed write: if the
      // scoped set stages all-awake we still write it (→ the "Awake 100% / 8h15m" phantom); if there
      // are no windows we write nothing (→ the nap silently vanishes).
      const day = this._dateOf(marker.wake);
      const scoped = this.windows.filter((w) => this._dateOf(w.endSec) === day);
      if (scoped.length === 0) { this.log.push('buggy: no windows → nothing sealed'); return null; }
      const span = Math.max(...scoped.map((w) => w.endSec)) - Math.min(...scoped.map((w) => w.startSec));
      const asleepMin = Math.round(scoped.filter((w) => w.asleep).reduce((a, w) => a + (w.endSec - w.startSec) / 60, 0));
      const inBedMin = Math.round(span / 60);
      const row = { sleptAt: day, sessionStart: null, isNap: false,
        durationMin: asleepMin, asleepMin, awakeMin: Math.max(0, inBedMin - asleepMin),
        allAwake: asleepMin === 0 };
      this.sleepLogs.push(row);
      this.log.push(`buggy sealed span=${inBedMin}m asleep=${asleepMin}m allAwake=${row.allAwake}`);
      return row;
    }

    // THE FIX
    // 1) Minimum-duration gate on the READ/seal side (not a pre-persist filter): a sub-20-min tap is
    //    not a nap. A legit 20-90 min nap sails through.
    if (durMin < MIN_SLEEP_MIN) { this.log.push(`too short (${durMin}m < ${MIN_SLEEP_MIN}m) → not sealed`); return null; }

    // 2) Scope staging to the marker's OWN [bedtime, wake] — never the whole calendar date. Unrelated
    //    day bursts (a mid-morning HR burst, yesterday's tail) can't leak into the nap.
    const scoped = this.windows.filter((w) => w.endSec > marker.bedtime && w.startSec < marker.wake);
    const asleepMin = Math.round(scoped.filter((w) => w.asleep).reduce((a, w) => a + (w.endSec - w.startSec) / 60, 0));

    // 3) Guaranteed write: a confirmed session ALWAYS produces a sleep_logs row, its duration taken
    //    from the marker itself — even if the PPG windows were thin/late/never uploaded. When staging
    //    is degenerate (nothing scored asleep) we write duration-only (stages null) rather than an
    //    all-awake block — the phantom guard. Sane, honest, and it surfaces on the Sleep page.
    const staged = asleepMin > 0;
    const isNap = durMin < NAP_MAX_MIN;
    const row = {
      sleptAt: this._dateOf(marker.wake),
      sessionStart: isNap ? marker.bedtime : null,   // naps keyed by session_start → never clobber the night
      isNap,
      durationMin: durMin,
      asleepMin: staged ? asleepMin : null,
      awakeMin: staged ? Math.max(0, durMin - asleepMin) : null,
      allAwake: false,                                // never present a 100%-awake session
    };
    this.sleepLogs.push(row);
    this.log.push(`sealed ${isNap ? 'NAP' : 'night'} dur=${durMin}m asleep=${row.asleepMin} staged=${staged}`);
    return row;
  }

  // A watch-CONFIRMED workout envelope arrived (TW: { start, end, kind, manual } — epoch seconds). Seal
  // THIS workout → one activity_sessions row. Mirrors SealActivityJob::sealConfirmedSession (fixed) and
  // the pre-fix window-timestamp-inferred seal (buggy).
  sealConfirmedWorkout(env) {
    const durMin = Math.round((env.end - env.start) / 60);

    if (this.buggy) {
      // PRE-FIX: the envelope didn't exist. The seal INFERRED the session from kind=workout window
      // timestamps + an `ended` flag. Failure modes it reproduces:
      //   • airplane / out-of-range: no windows reached the server → NOTHING sealed (workout lost).
      //   • thin/lost tail: the last minutes near the stop never became a full window, so the inferred
      //     end (and duration) is truncated — the "ended-tail window loss".
      //   • live+backlog split: windows on either side of the drop are grouped as SEPARATE sessions →
      //     two partial rows (double-count), not one clean workout.
      const scoped = this.workoutWindows.filter((w) => w.endSec > env.start - 1200 && w.startSec < env.end + 1200);
      if (scoped.length === 0) { this.log.push('buggy: no workout windows → nothing sealed (workout LOST)'); return null; }
      // Group by a 20-min inter-window gap (SESSION_GAP_MINUTES) — a mid-workout offline gap splits it.
      scoped.sort((a, b) => a.startSec - b.startSec);
      const groups = [];
      let cur = [scoped[0]];
      for (let i = 1; i < scoped.length; i++) {
        if (scoped[i].startSec - cur[cur.length - 1].endSec > 1200) { groups.push(cur); cur = [scoped[i]]; }
        else cur.push(scoped[i]);
      }
      groups.push(cur);
      let last = null;
      for (const g of groups) {
        const gs = Math.min(...g.map((w) => w.startSec)), ge = Math.max(...g.map((w) => w.endSec));
        const row = { startedAt: gs, endedAt: ge, durationMin: Math.round((ge - gs) / 60),
          activityType: g.find((w) => w.kind)?.kind || 'other', source: 'titan_band', confirmed: false };
        this.activitySessions.push(row);
        last = row;
      }
      this.log.push(`buggy sealed ${groups.length} session(s) from windows`);
      return last;
    }

    // THE FIX
    // 1) Minimum-duration gate on the seal side: a sub-60-s tap isn't a workout.
    if (env.end - env.start < 60) { this.log.push(`workout too short (${env.end - env.start}s) → not sealed`); return null; }

    // 2) Scope to workout windows overlapping the envelope's OWN [start, end] (± margin) — live OR
    //    backlog-flushed. Both sides of a mid-workout drop overlap it → they seal as ONE session, never
    //    split. An unrelated earlier session's windows never leak in.
    const scoped = this.workoutWindows.filter((w) => w.endSec > env.start - 300 && w.startSec < env.end + 300);

    // 3) Idempotent with a prior window-based seal: if these windows already produced a row, correct its
    //    bounds + kind rather than adding a duplicate. (Not exercised by the fixed path here, but models
    //    the guard.)
    const already = scoped.map((w) => w.sealedId).find((id) => id != null);
    if (already != null) {
      const row = this.activitySessions.find((r) => r.id === already);
      if (row) { row.endedAt = env.end; row.durationMin = durMin; if (env.kind) row.activityType = env.kind === 'lift' ? 'strength' : env.kind; }
      this.log.push('reconciled onto existing row (no duplicate)');
      return row;
    }

    // 4) GUARANTEED write: the workout ALWAYS produces one activity_sessions row, bounded by the
    //    envelope and labelled with the user's CHOSEN kind (run vs lift authoritative) — even if the
    //    accel windows were thin/late/never uploaded (airplane / out of range).
    const id = this.activitySessions.length + 1;
    const kind = env.kind ? (env.kind === 'lift' ? 'strength' : env.kind) : (scoped.find((w) => w.kind)?.kind || 'other');
    const row = { id, startedAt: env.start, endedAt: env.end, durationMin: durMin,
      activityType: kind, source: 'titan_band', confirmed: true };
    scoped.forEach((w) => { w.sealedId = id; });
    this.activitySessions.push(row);
    this.log.push(`sealed workout dur=${durMin}m kind=${kind} scoped=${scoped.length}`);
    return row;
  }
}

module.exports = { Server, MIN_SLEEP_MIN, NAP_MAX_MIN };
