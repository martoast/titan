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
    this.log = [];
  }

  // A ppg_raw window landed (ProcessWindowJob upsert). asleep = the epochs staged as sleep, not wake.
  ingestWindow(w) { this.windows.push(w); }

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
}

module.exports = { Server, MIN_SLEEP_MIN, NAP_MAX_MIN };
