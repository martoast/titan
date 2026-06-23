<x-titan-layout title="Live stream" subtitle="Bridge a Bangle.js straight into Titan over Bluetooth — raw PPG in, HRV out.">

    <div class="max-w-3xl mx-auto space-y-5"
         x-data="bangleBridge({
            ingestUrl: @js($ingestUrl),
            seed: @js($justPaired ? ['device_id' => $justPaired['device_id'] ?? '', 'secret' => $justPaired['secret'] ?? ''] : null),
         })"
         x-init="init()">

        {{-- ===== credentials ===== --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-center justify-between gap-3">
                <h3 class="font-display font-bold text-gray-100">Device credentials</h3>
                <span class="text-[11px] uppercase tracking-wide px-2 py-0.5 rounded"
                      :class="hasCreds ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300'"
                      x-text="hasCreds ? 'ready' : 'needed'"></span>
            </div>
            <p class="text-sm text-gray-500 mt-1">Pair a <span class="text-gray-300">Bangle.js</span> on the <a href="/devices" class="text-indigo-400 hover:underline">Devices</a> page to get a device ID + one-time secret. They're stored only in this browser.</p>
            <div class="mt-3 grid grid-cols-1 gap-2.5">
                {{-- These are device-signing credentials, NOT a login. We deliberately avoid a
                     real password field (and tag every major manager's ignore attr) so browser
                     password autofill can't dump the user's account login into them. The secret
                     is masked with CSS text-security instead of type=password. --}}
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Device ID</span>
                    <input type="text" inputmode="text" x-model="deviceId" @input="saveCreds()" placeholder="bangle_01j…"
                           name="titan_device_id" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                           data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"
                           class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-sm text-gray-100 font-mono focus:border-indigo-500 focus:ring-0">
                </label>
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Secret</span>
                    <input type="text" inputmode="text" x-model="secret" @input="saveCreds()" placeholder="64-hex one-time secret"
                           :style="showSecret ? '' : '-webkit-text-security: disc; text-security: disc;'"
                           name="titan_device_secret" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                           data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"
                           class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-sm text-gray-100 font-mono focus:border-indigo-500 focus:ring-0">
                    <button type="button" @click="showSecret = !showSecret" class="mt-1 text-[11px] text-gray-500 hover:text-gray-300" x-text="showSecret ? 'hide' : 'show'"></button>
                </label>
            </div>
        </section>

        {{-- ===== connection + live waveform ===== --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] overflow-hidden">
            <div class="relative h-40 bg-gray-950">
                <canvas x-ref="wave" class="absolute inset-0 w-full h-full"></canvas>
                <div class="absolute top-3 left-4 flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full" :class="connected ? 'bg-emerald-400 animate-pulse' : 'bg-gray-600'"></span>
                    <span class="text-xs font-medium" :class="connected ? 'text-emerald-300' : 'text-gray-500'" x-text="statusLabel"></span>
                </div>
                <div class="absolute top-3 right-4 text-right">
                    <span class="font-display text-2xl font-bold text-gray-100 nums" x-text="bpm || '--'"></span>
                    <span class="text-xs text-gray-500">bpm</span>
                </div>
                <div x-show="!connected && !waveHasData" class="absolute inset-0 grid place-items-center text-sm text-gray-600">Waveform appears here once streaming</div>
            </div>

            <div class="p-4 md:p-5">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div><p class="font-display text-xl font-bold text-gray-100 nums" x-text="samples"></p><p class="text-[11px] uppercase tracking-wide text-gray-500">samples</p></div>
                    <div><p class="font-display text-xl font-bold text-gray-100 nums" x-text="windowsSent"></p><p class="text-[11px] uppercase tracking-wide text-gray-500">windows sent</p></div>
                    <div><p class="font-display text-xl font-bold text-gray-100 nums" x-text="rateHz || '--'"></p><p class="text-[11px] uppercase tracking-wide text-gray-500">Hz</p></div>
                </div>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <button @click="connected ? disconnect() : connect()" :disabled="!hasCreds && !connected"
                            class="h-12 rounded-xl px-5 text-sm font-semibold transition disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="connected ? 'bg-rose-500/90 hover:bg-rose-500 text-white' : 'bg-gradient-to-r from-indigo-500 to-cyan-500 text-gray-950'">
                        <span x-text="connected ? 'Disconnect' : 'Connect Bangle over Bluetooth'"></span>
                    </button>
                    <button @click="sendTestWindow()" :disabled="!hasCreds || busy"
                            class="h-12 rounded-xl px-5 text-sm font-semibold bg-white/5 border border-white/10 text-gray-200 hover:bg-white/10 transition disabled:opacity-40 disabled:cursor-not-allowed">
                        Send test window (no hardware)
                    </button>
                </div>
                <p x-show="!btSupported" x-cloak class="mt-3 text-xs text-amber-400/90">
                    This browser has no Web Bluetooth. <span class="text-amber-200">On iPhone, open this page in <a href="https://apps.apple.com/app/bluefy-web-ble-browser/id1492822055" target="_blank" rel="noopener" class="underline">Bluefy</a></span> (a free Web-Bluetooth browser) — or use desktop Chrome/Edge or Android Chrome.
                </p>
            </div>
        </section>

        {{-- ===== activity log ===== --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-center justify-between">
                <h3 class="font-display font-bold text-gray-100">Ingest log</h3>
                <a href="/recovery" class="text-sm text-indigo-400 hover:underline">View recovery →</a>
            </div>
            <div class="mt-3 space-y-1.5 max-h-72 overflow-y-auto font-mono text-xs">
                <template x-for="(line, i) in log" :key="i">
                    <div class="flex gap-2" :class="{
                        'text-emerald-300': line.kind==='ok',
                        'text-rose-300': line.kind==='err',
                        'text-gray-400': line.kind==='info'
                    }">
                        <span class="text-gray-600 shrink-0" x-text="line.t"></span>
                        <span x-text="line.msg"></span>
                    </div>
                </template>
                <p x-show="log.length===0" class="text-gray-600">No activity yet. <strong>Morning sync:</strong> wear the band overnight (it logs to its own memory), then Connect here in the morning — it streams the whole night in seconds. Or stream live, or send a test window.</p>
            </div>
        </section>

        {{-- Build marker — so you can confirm the deployed bridge matches the latest commit.
             Prefers the checked-out git SHA (read straight from .git, no shell); falls back to
             a manually-bumped tag if .git isn't present in the runtime image. --}}
        @php
            $bridgeBuild = 'v3-livestream';
            try {
                $head = @file_get_contents(base_path('.git/HEAD'));
                if ($head) {
                    $head = trim($head);
                    if (str_starts_with($head, 'ref:')) {
                        $sha = @file_get_contents(base_path('.git/'.trim(substr($head, 5))));
                        if ($sha) { $bridgeBuild = substr(trim($sha), 0, 7); }
                    } else {
                        $bridgeBuild = substr($head, 0, 7);
                    }
                }
            } catch (\Throwable $e) { /* keep the fallback tag */ }
        @endphp
        <p class="text-center text-[11px] text-gray-600">
            Titan bridge · build <span class="font-mono text-gray-500">{{ $bridgeBuild }}</span>
        </p>
    </div>

    <script>
        const NUS_SERVICE = '6e400001-b5a3-f393-e0a9-e50e24dcca9e';
        const NUS_TX      = '6e400003-b5a3-f393-e0a9-e50e24dcca9e'; // device → us (notify)

        function bangleBridge(cfg) {
            return {
                ingestUrl: cfg.ingestUrl,
                deviceId: '', secret: '', showSecret: false,
                connected: false, busy: false,
                samples: 0, windowsSent: 0, rateHz: 0, bpm: 0,
                statusLabel: 'Disconnected',
                btSupported: !!(navigator.bluetooth && navigator.bluetooth.requestDevice),
                _device: null, _rx: '', _wave: [], waveHasData: false,
                _samples: [], _trailTimer: null, WINDOW_MS: 120000,
                log: [],

                get hasCreds() { return this.deviceId.trim().length > 4 && this.secret.trim().length >= 32; },

                init() {
                    // Seed from just-paired flash, else from localStorage.
                    if (cfg.seed && cfg.seed.device_id) {
                        this.deviceId = cfg.seed.device_id; this.secret = cfg.seed.secret || '';
                        this.saveCreds();
                    } else {
                        this.deviceId = localStorage.getItem('titan.bangle.deviceId') || '';
                        this.secret = localStorage.getItem('titan.bangle.secret') || '';
                    }
                    this._initWave();
                    // Resume streaming after a page reload without making the user re-pick the
                    // device in the chooser (Web Bluetooth drops the link on every refresh).
                    this._reconnectKnown();
                },
                saveCreds() {
                    localStorage.setItem('titan.bangle.deviceId', this.deviceId.trim());
                    localStorage.setItem('titan.bangle.secret', this.secret.trim());
                },

                // ---- Web Bluetooth ----
                async connect() {
                    if (!this.btSupported) return this._log('err', 'Web Bluetooth unavailable in this browser');
                    try {
                        this.statusLabel = 'Requesting device…';
                        // requestDevice() needs a user gesture (this click); it shows the chooser.
                        const device = await navigator.bluetooth.requestDevice({
                            filters: [{ namePrefix: 'Bangle' }],
                            optionalServices: [NUS_SERVICE],
                        });
                        await this._attach(device);
                    } catch (err) {
                        this.statusLabel = 'Disconnected';
                        this._log('err', 'Connect failed: ' + (err.message || err));
                    }
                },
                // Wire up a chosen device's GATT/NUS. Shared by manual connect() and the
                // silent reconnect on load so both paths stay identical.
                async _attach(device) {
                    this._device = device;
                    this._device.addEventListener('gattserverdisconnected', () => this._onDrop());
                    this.statusLabel = 'Connecting…';
                    const server = await this._device.gatt.connect();
                    const svc = await server.getPrimaryService(NUS_SERVICE);
                    const tx = await svc.getCharacteristic(NUS_TX);
                    await tx.startNotifications();
                    tx.addEventListener('characteristicvaluechanged', (e) => this._onBytes(e.target.value));
                    this.connected = true;
                    this.statusLabel = 'Streaming · ' + (this._device.name || 'Bangle');
                    this._log('ok', 'Connected to ' + (this._device.name || 'Bangle.js'));
                    // Finalize a live workout once its GPS frames stop: T1 keeps the device
                    // clock advancing, so the assembler sees the end-gap and emits the session.
                    clearInterval(this._waTimer);
                    this._waTimer = setInterval(() => {
                        const win = this._wa && this._wa.tick(this._maxDeviceT);
                        if (win) this._ship(win);
                    }, 5000);
                },
                // A Web Bluetooth link is bound to the page session, so a refresh drops it.
                // Chrome remembers granted devices, though — so on load we silently re-attach
                // to a previously-authorized Bangle (no chooser, no user gesture needed for
                // gatt.connect on an already-permitted device). Stays quiet if the API is
                // unavailable, nothing's been paired, or the watch is out of range/asleep.
                async _reconnectKnown() {
                    if (!this.btSupported || !navigator.bluetooth.getDevices) return;
                    try {
                        const known = await navigator.bluetooth.getDevices();
                        const dev = known.find((d) => (d.name || '').indexOf('Bangle') === 0);
                        if (!dev) return;
                        this.statusLabel = 'Reconnecting…';
                        await this._attach(dev);
                    } catch (err) {
                        this.statusLabel = 'Disconnected';   // out of range / asleep — user can tap Connect
                    }
                },
                disconnect() {
                    try { this._device && this._device.gatt.connected && this._device.gatt.disconnect(); } catch (e) {}
                    this._onDrop();
                },
                _onDrop() {
                    this.connected = false; this.statusLabel = 'Disconnected';
                    clearInterval(this._waTimer);
                    clearTimeout(this._woTrail);
                    this._flushWindow(); // ship whatever PPG samples are accumulated
                    const win = this._wa && this._wa.flush(); // ship any in-progress workout
                    if (win) this._ship(win);
                    this._log('info', 'Disconnected');
                },

                // The firmware sends newline-delimited frames over NUS:
                //   T1: live raw (16-B header + 12-B samples, with accel + per-sample ts)
                //   T2: overnight log, compact PPG-only (20-B header + int16 samples),
                //       streamed as a burst on connect — the morning sync.
                _onBytes(dataview) {
                    this._rx += new TextDecoder().decode(dataview);
                    let nl;
                    while ((nl = this._rx.indexOf('\n')) >= 0) {
                        const line = this._rx.slice(0, nl).trim();
                        this._rx = this._rx.slice(nl + 1);
                        if (line.startsWith('T1:')) this._decodeFrame(line.slice(3));
                        else if (line.startsWith('T2:')) this._decodeT2(line.slice(3));
                        else if (line.startsWith('T4:')) this._onGps(line.slice(3));   // workout GPS fix
                        else if (line.startsWith('T5:')) this._onWorkoutHr(line.slice(3)); // workout HR
                        else if (line.startsWith('T6:')) this._onWorkoutAccel(line.slice(3)); // offline workout accel
                    }
                },

                // --- Workout frames (T4 GPS + T5 HR) → assemble a kind=workout window ---
                // GPS only powers on once the watch detects a workout, so a T4 frame means a
                // workout is under way; the assembler bundles the 3-axis accel (from T1) + HR +
                // GPS and emits the window SealActivityJob ingests. See resources/js/bridge-decode.js.
                _wa: null, _maxDeviceT: 0, _woTrail: null,
                _ensureWa() {
                    if (!this._wa && window.TitanBridge) this._wa = new window.TitanBridge.WorkoutAssembler();
                    return this._wa;
                },
                // Track the latest DEVICE timestamp across all frames — the assembler finalizes by
                // device-time gaps (so a morning-sync burst, which arrives in milliseconds of
                // wall-clock but spans a real workout, finalizes correctly). After a quiet spell
                // (live workout ended, or the burst settled) flush the trailing session.
                _seen(t) {
                    if (t > this._maxDeviceT) this._maxDeviceT = t;
                    clearTimeout(this._woTrail);
                    this._woTrail = setTimeout(() => {
                        const win = this._wa && this._wa.flush();
                        if (win) this._ship(win);
                    }, 4000);
                },
                _onGps(b64) {
                    const wa = this._ensureWa(); if (!wa) return;
                    const fix = window.TitanBridge.decodeT4(b64); if (fix) this._seen(fix.t);
                    const win = wa.addGps(fix);
                    if (win) this._ship(win); // periodic flush / gap-split
                },
                _onWorkoutHr(b64) {
                    const wa = this._ensureWa(); if (!wa) return;
                    const hr = window.TitanBridge.decodeT5(b64);
                    if (hr) { this._seen(hr.t); wa.addHr(hr); this.bpm = hr.bpm; }
                },
                _onWorkoutAccel(b64) {
                    const wa = this._ensureWa(); if (!wa) return;
                    const samples = window.TitanBridge.decodeT6(b64);
                    if (samples.length) this._seen(samples[samples.length - 1].t);
                    const win = wa.addWorkoutAccel(samples);
                    if (win) this._ship(win); // gap-split between back-to-back offline sessions
                },

                // T1 live frame. hdr[ver u8, ppgField u8, count u16, epochLo u32, epochHi u32, rsvd u32];
                // sample[relT u32, ppg i16, ax i16, ay i16, az i16] = 12 B.
                _decodeFrame(b64) {
                    const bytes = this._b64bytes(b64); if (!bytes || bytes.length < 16) return;
                    const dv = new DataView(bytes.buffer);
                    const count = dv.getUint16(2, true);
                    const epoch = dv.getUint32(8, true) * 4294967296 + dv.getUint32(4, true);
                    const live = [];
                    const accel = []; // raw 3-axis (milli-g) for the workout assembler
                    for (let i = 0; i < count; i++) {
                        const off = 16 + i * 12;
                        if (off + 12 > bytes.length) break;
                        const ppg = dv.getInt16(off + 4, true);
                        const ax = dv.getInt16(off + 6, true), ay = dv.getInt16(off + 8, true), az = dv.getInt16(off + 10, true);
                        const t = epoch + dv.getUint32(off, true);
                        this._samples.push({ t, ppg, mag: Math.round(Math.sqrt(ax * ax + ay * ay + az * az) / 10) });
                        accel.push({ t, ax, ay, az });
                        live.push(ppg);
                    }
                    // Feed the workout assembler — it ignores accel until a GPS fix opens a workout.
                    const wa = this._ensureWa(); if (wa) wa.addAccel(accel);
                    // T1 keeps the device clock moving so a live workout can detect its end-gap.
                    if (accel.length) this._maxDeviceT = Math.max(this._maxDeviceT, accel[accel.length - 1].t);
                    this.samples += live.length;
                    this._pushWave(live);
                    this._drainWindows();
                },

                // T2 compact log frame. hdr[ver u8, rsvd u8, count u16, startLo u32, startHi u32, durMs u32, activity u32];
                // samples = int16 PPG. Timestamps spread evenly across [start, start+durMs];
                // the frame's actigraphy `activity` is spread per sample so a per-epoch sum
                // recovers the total movement (the stager uses it relatively).
                _decodeT2(b64) {
                    const bytes = this._b64bytes(b64); if (!bytes || bytes.length < 20) return;
                    const dv = new DataView(bytes.buffer);
                    const count = dv.getUint16(2, true);
                    const start = dv.getUint32(8, true) * 4294967296 + dv.getUint32(4, true);
                    const durMs = dv.getUint32(12, true);
                    const activity = dv.getUint32(16, true);
                    const perSample = count > 0 ? activity / count : 0;
                    const denom = Math.max(1, count - 1);
                    const live = [];
                    for (let i = 0; i < count; i++) {
                        const off = 20 + i * 2;
                        if (off + 2 > bytes.length) break;
                        const ppg = dv.getInt16(off, true);
                        this._samples.push({ t: start + Math.round(durMs * i / denom), ppg, mag: perSample });
                        live.push(ppg);
                    }
                    this.samples += live.length;
                    this._pushWave(live);
                    this._drainWindows();
                },

                _b64bytes(b64) {
                    let bin; try { bin = atob(b64); } catch (e) { return null; }
                    const a = new Uint8Array(bin.length);
                    for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i);
                    return a;
                },

                // Timestamp-driven windowing: emit every complete 120 s window by the samples'
                // OWN timestamps. Works for both a live trickle and a burst-synced night.
                _drainWindows() {
                    while (this._samples.length > 1) {
                        const t0 = this._samples[0].t;
                        if (this._samples[this._samples.length - 1].t - t0 < this.WINDOW_MS) break;
                        const cut = t0 + this.WINDOW_MS;
                        let i = 0;
                        while (i < this._samples.length && this._samples[i].t < cut) i++;
                        const win = this._samples.slice(0, i);
                        this._samples = this._samples.slice(i);
                        this._shipSamples(win);
                    }
                    // After a burst (or live idle) settles, ship the trailing partial window.
                    clearTimeout(this._trailTimer);
                    this._trailTimer = setTimeout(() => this._flushWindow(), 3000);
                },

                _flushWindow() {
                    if (this._samples.length < 1) return;
                    const span = this._samples[this._samples.length - 1].t - this._samples[0].t;
                    if (span < 30000 && this.connected) return; // too short while still live — keep collecting
                    const w = this._samples; this._samples = [];
                    this._shipSamples(w);
                },

                // Build a ppg_raw window from a sample array and ship it (sign + POST).
                async _shipSamples(s) {
                    if (s.length < 30) return;
                    const startMs = s[0].t, endMs = s[s.length - 1].t;
                    const durSec = Math.max(1, (endMs - startMs) / 1000);
                    const rate = Math.max(1, Math.round(s.length / durSec));
                    this.rateHz = rate;
                    const win = {
                        kind: 'ppg_raw',
                        start: new Date(startMs).toISOString(),
                        end: new Date(endMs).toISOString(),
                        sample_rate_hz: rate,
                        ppg: s.map(x => x.ppg),
                        accel_mag_cg: s.map(x => x.mag),
                        src: 'banglejs2',
                    };
                    this._estimateBpm(win);
                    await this._ship(win);
                },

                // ---- sign + POST one window as a batch ----
                async _ship(win) {
                    this.busy = true;
                    try {
                        const body = JSON.stringify({ batch_uid: this._ulid(), windows: [win] });
                        const ts = Math.floor(Date.now() / 1000).toString();
                        const sig = await this._sign(ts, body);
                        const res = await fetch(this.ingestUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Device-Id': this.deviceId.trim(),
                                'X-Titan-Signature': `t=${ts},v1=${sig}`,
                            },
                            body,
                        });
                        const j = await res.json().catch(() => ({}));
                        if (res.ok) {
                            this.windowsSent++;
                            const desc = win.kind === 'workout'
                                ? `workout (${win.accel_xyz.x.length} accel, ${win.hr_bpm.length}s HR/GPS)`
                                : `${(win.ppg || []).length} samples @ ${win.sample_rate_hz}Hz`;
                            this._log('ok', `${win.kind} window accepted (${desc}) → queued ${j.windows_queued ?? 0}`);
                        } else {
                            this._log('err', `ingest ${res.status}: ${j.error || res.statusText}`);
                        }
                    } catch (err) {
                        this._log('err', 'ship failed: ' + (err.message || err));
                    } finally {
                        this.busy = false;
                    }
                },

                // HMAC-SHA256("<ts>.<body>", key) where key = sha256-hex(secret) — matches the server.
                async _sign(ts, body) {
                    const enc = new TextEncoder();
                    const digest = await crypto.subtle.digest('SHA-256', enc.encode(this.secret.trim()));
                    const keyHex = [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('');
                    const key = await crypto.subtle.importKey('raw', enc.encode(keyHex), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
                    const mac = await crypto.subtle.sign('HMAC', key, enc.encode(ts + '.' + body));
                    return [...new Uint8Array(mac)].map(b => b.toString(16).padStart(2, '0')).join('');
                },

                // ---- test window: synthesize ~120s of 25Hz PPG with mild HRV ----
                async sendTestWindow() {
                    const hz = 25, secs = 120, n = hz * secs, ppg = [];
                    let phase = 0;
                    for (let i = 0; i < n; i++) {
                        const t = i / hz;
                        // ~60 bpm with small beat-to-beat variation (so RMSSD is non-zero)
                        const ibi = 1.0 + 0.04 * Math.sin(t * 0.25) + (((i * 2654435761) % 1000) / 1000 - 0.5) * 0.03;
                        phase += (1 / hz) / ibi;
                        const beat = Math.sin(2 * Math.PI * phase);
                        ppg.push(Math.round(2048 + 350 * beat + 30 * Math.sin(2 * Math.PI * 0.2 * t)));
                    }
                    const end = new Date();
                    const win = {
                        kind: 'ppg_raw',
                        start: new Date(end.getTime() - secs * 1000).toISOString(),
                        end: end.toISOString(),
                        sample_rate_hz: hz, ppg, accel_mag_cg: [], src: 'bridge-test',
                    };
                    this.samples += n; this.rateHz = hz;
                    this._pushWave(ppg); this._estimateBpm(win);
                    this._log('info', 'sending synthesized test window…');
                    await this._ship(win);
                },

                _estimateBpm(win) {
                    // crude: count zero-up-crossings of the de-meaned signal
                    const p = win.ppg, m = p.reduce((a, b) => a + b, 0) / p.length;
                    let beats = 0;
                    for (let i = 1; i < p.length; i++) if (p[i - 1] - m < 0 && p[i] - m >= 0) beats++;
                    const secs = (win.ppg.length) / (win.sample_rate_hz || 25);
                    this.bpm = secs > 0 ? Math.round(beats / secs * 60) : 0;
                },

                // ---- live waveform ----
                _initWave() {
                    const c = this.$refs.wave; if (!c) return;
                    const fit = () => { c.width = c.clientWidth * devicePixelRatio; c.height = c.clientHeight * devicePixelRatio; this._drawWave(); };
                    fit(); window.addEventListener('resize', fit);
                },
                _pushWave(arr) {
                    const step = Math.max(1, Math.floor(arr.length / 400));
                    for (let i = 0; i < arr.length; i += step) this._wave.push(arr[i]);
                    if (this._wave.length > 600) this._wave = this._wave.slice(-600);
                    this.waveHasData = true; this._drawWave();
                },
                _drawWave() {
                    const c = this.$refs.wave; if (!c) return;
                    const ctx = c.getContext('2d'), w = c.width, h = c.height, d = this._wave;
                    ctx.clearRect(0, 0, w, h);
                    if (d.length < 2) return;
                    let lo = Math.min(...d), hi = Math.max(...d); if (hi - lo < 1) hi = lo + 1;
                    ctx.beginPath();
                    ctx.lineWidth = 2 * devicePixelRatio; ctx.strokeStyle = '#22d3ee'; ctx.lineJoin = 'round';
                    for (let i = 0; i < d.length; i++) {
                        const x = (i / (d.length - 1)) * w;
                        const y = h - 12 - ((d[i] - lo) / (hi - lo)) * (h - 24);
                        i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
                    }
                    ctx.stroke();
                },

                // ---- helpers ----
                _ulid() {
                    const ENC = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
                    let ts = Date.now(), time = '';
                    for (let i = 9; i >= 0; i--) { time = ENC[ts % 32] + time; ts = Math.floor(ts / 32); }
                    let rand = '';
                    const r = crypto.getRandomValues(new Uint8Array(16));
                    for (let i = 0; i < 16; i++) rand += ENC[r[i] % 32];
                    return time + rand; // 26 chars, Crockford base32
                },
                _log(kind, msg) {
                    const t = new Date().toLocaleTimeString();
                    this.log.unshift({ kind, msg, t });
                    if (this.log.length > 50) this.log.pop();
                },
            };
        }
    </script>
</x-titan-layout>
