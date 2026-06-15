/**
 * Tests the bridge frame decoders + workout assembler against frames encoded EXACTLY as the
 * firmware (firmware/banglejs/titan.app.js) writes them. Run: node --test tests/js/
 */
import assert from 'node:assert';
import { test } from 'node:test';

import { decodeT1, decodeT4, decodeT5, WorkoutAssembler } from '../../resources/js/bridge-decode.js';

// --- frame encoders mirroring the firmware byte layouts ---
const b64 = (buf) => buf.toString('base64');
const lo = (ms) => ms >>> 0;
const hi = (ms) => Math.floor(ms / 4294967296) >>> 0;

function encT1(epochMs, samples) {
  const buf = Buffer.alloc(16 + samples.length * 12);
  buf.writeUInt8(1, 0);
  buf.writeUInt8(0, 1);
  buf.writeUInt16LE(samples.length, 2);
  buf.writeUInt32LE(lo(epochMs), 4);
  buf.writeUInt32LE(hi(epochMs), 8);
  samples.forEach((s, i) => {
    const o = 16 + i * 12;
    buf.writeUInt32LE(s.t - epochMs, o);
    buf.writeInt16LE(s.ppg, o + 4);
    buf.writeInt16LE(s.ax, o + 6);
    buf.writeInt16LE(s.ay, o + 8);
    buf.writeInt16LE(s.az, o + 10);
  });
  return b64(buf);
}

function encT4(t, speedKmh, altM, sats) {
  const buf = Buffer.alloc(20);
  buf.writeUInt8(4, 0);
  buf.writeUInt8(sats, 1);
  buf.writeInt16LE(Math.round(speedKmh * 100), 2);
  buf.writeUInt32LE(lo(t), 4);
  buf.writeUInt32LE(hi(t), 8);
  buf.writeInt32LE(altM === null ? -2147483648 : Math.round(altM * 10), 12);
  buf.writeUInt32LE(0, 16);
  return b64(buf);
}

function encT5(t, bpm, conf) {
  const buf = Buffer.alloc(12);
  buf.writeUInt8(5, 0);
  buf.writeUInt8(bpm, 1);
  buf.writeUInt8(conf, 2);
  buf.writeUInt8(0, 3);
  buf.writeUInt32LE(lo(t), 4);
  buf.writeUInt32LE(hi(t), 8);
  return b64(buf);
}

const BASE = Date.UTC(2026, 5, 15, 12, 0, 0); // 1.7e12 ms — exercises the 64-bit timestamp split

test('decodeT1 recovers epoch + 3-axis accel samples', () => {
  const samples = [
    { t: BASE, ppg: 2048, ax: 120, ay: -300, az: 980 },
    { t: BASE + 40, ppg: 2100, ax: 130, ay: -310, az: 990 },
  ];
  const out = decodeT1(encT1(BASE, samples));
  assert.equal(out.epoch, BASE);
  assert.equal(out.samples.length, 2);
  assert.deepEqual(
    { t: out.samples[1].t, ax: out.samples[1].ax, ay: out.samples[1].ay, az: out.samples[1].az, ppg: out.samples[1].ppg },
    { t: BASE + 40, ax: 130, ay: -310, az: 990, ppg: 2100 },
  );
});

test('decodeT4 recovers speed, altitude, sats (and the null-altitude sentinel)', () => {
  const f = decodeT4(encT4(BASE, 12.34, 105.6, 9));
  assert.equal(f.t, BASE);
  assert.ok(Math.abs(f.speedKmh - 12.34) < 0.01);
  assert.ok(Math.abs(f.alt - 105.6) < 0.05);
  assert.equal(f.sats, 9);
  assert.equal(decodeT4(encT4(BASE, 0, null, 0)).alt, null);
});

test('decodeT5 recovers bpm + confidence', () => {
  const h = decodeT5(encT5(BASE, 162, 95));
  assert.deepEqual({ bpm: h.bpm, conf: h.conf, t: h.t }, { bpm: 162, conf: 95, t: BASE });
});

test('WorkoutAssembler builds a workout window from a live uphill run', () => {
  const wa = new WorkoutAssembler();
  const fs = 25, secs = 70;
  for (let s = 0; s < secs; s++) {
    const t0 = BASE + s * 1000;
    // GPS fix: ~10 km/h, climbing 0.2 m/s (uphill) → positive grade.
    wa.addGps(decodeT4(encT4(t0, 10, 100 + s * 0.2, 8)));
    // 25 accel samples for this second (rhythmic, milli-g).
    const samp = [];
    for (let i = 0; i < fs; i++) {
      const t = t0 + i * 40;
      samp.push({ t, ppg: 2048, ax: 200 * Math.sin(i), ay: 150, az: 980 });
    }
    wa.addAccel(decodeT1(encT1(t0, samp)).samples);
    wa.addHr(decodeT5(encT5(t0, 150, 90)));
  }
  // GPS stops → after the end-gap, the workout finalises.
  const win = wa.tick(BASE + secs * 1000 + 130000);
  assert.ok(win, 'a workout window should be emitted');
  assert.equal(win.kind, 'workout');
  assert.equal(win.accel_unit, 'mg');
  assert.equal(win.accel_xyz.x.length, secs * fs);           // 1750 accel samples
  assert.ok(Math.abs(win.accel_fs - fs) <= 1);
  assert.ok(win.hr_bpm.length >= secs - 1 && win.hr_bpm.every((b) => b === 150));
  assert.ok(win.gps.speed_kmh.length >= secs - 1);
  assert.ok(win.gps.grade.some((g) => g > 0.01), 'uphill → positive grade');
  assert.ok(win.accel_counts.length >= 2);                   // ~70s / 30s epochs
  assert.match(win.start, /^2026-06-15T12:00/);
});

test('accel before the first GPS fix is ignored (no workout yet)', () => {
  const wa = new WorkoutAssembler();
  wa.addAccel([{ t: BASE, ax: 100, ay: 100, az: 980 }]);   // pre-workout motion
  assert.equal(wa.accel.length, 0);
  assert.equal(wa.flush(), null);                           // nothing to emit
});
