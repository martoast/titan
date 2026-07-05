'use strict';

// A deterministic virtual clock shared by the watch firmware AND the phone model, so time-based
// logic on both sides (firmware setInterval/getTime; phone's 4s sport-loss / 12s suppress-grace /
// 60s frame-liveness windows) advances together under our control. advance(ms) fires every timer
// due in that span, in chronological order, exactly as a real event loop would.
class VirtualClock {
  constructor(startMs = 1_751_600_000_000) { // a fixed 2025-ish epoch (deterministic)
    this.ms = startMs;
    this.timers = [];
    this.seq = 0;
  }
  nowMs() { return this.ms; }
  getTimeSec() { return this.ms / 1000; }         // firmware global getTime()
  setTimeSec(s) { this.ms = Math.round(s * 1000); } // firmware global setTime()

  setTimeout(fn, delay) {
    const id = ++this.seq;
    this.timers.push({ id, at: this.ms + Math.max(0, delay | 0), fn, interval: null });
    return id;
  }
  setInterval(fn, delay) {
    const id = ++this.seq;
    const d = Math.max(1, delay | 0);
    this.timers.push({ id, at: this.ms + d, fn, interval: d });
    return id;
  }
  clear(id) { this.timers = this.timers.filter(t => t.id !== id); }

  advance(ms) {
    const target = this.ms + ms;
    let guard = 0;
    for (;;) {
      let due = null;
      for (const t of this.timers) if (t.at <= target && (!due || t.at < due.at)) due = t;
      if (!due) break;
      this.ms = due.at;
      if (due.interval == null) this.clear(due.id);
      else due.at = this.ms + due.interval;
      due.fn();
      if (++guard > 500_000) throw new Error('virtual-clock timer runaway');
    }
    this.ms = target;
  }
}

// A Date replacement bound to the virtual clock, with a settable tz offset (E.setTimeZone).
function makeVDate(clock, tzRef) {
  const RealDate = Date;
  class VDate {
    constructor(t) { this._r = new RealDate(t === undefined ? clock.nowMs() : t); }
    getTime() { return this._r.getTime(); }
    _local() { return new RealDate(this._r.getTime() + (tzRef.h || 0) * 3600_000); }
    getFullYear() { return this._local().getUTCFullYear(); }
    getMonth() { return this._local().getUTCMonth(); }
    getDate() { return this._local().getUTCDate(); }
    getHours() { return this._local().getUTCHours(); }
    getMinutes() { return this._local().getUTCMinutes(); }
    getSeconds() { return this._local().getUTCSeconds(); }
    getDay() { return this._local().getUTCDay(); }
  }
  VDate.now = () => clock.nowMs();
  return VDate;
}

module.exports = { VirtualClock, makeVDate };
