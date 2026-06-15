<x-titan-layout title="Validation lab" subtitle="Prove the Bangle against a Polar H10 — beat-for-beat HRV agreement.">

    <div class="max-w-3xl mx-auto space-y-5"
         x-data="validationLab({ csrf: @js(csrf_token()), previewUrl: @js(route('devices.hrv-preview')) })"
         x-init="init()">

        {{-- ===== devices ===== --}}
        <section class="grid grid-cols-2 gap-3">
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full" :class="polar.connected ? 'bg-emerald-400 animate-pulse' : 'bg-gray-600'"></span>
                    <span class="text-sm font-semibold text-gray-100">Polar H10</span>
                    <span class="text-[10px] uppercase tracking-wide text-gray-500">reference</span>
                </div>
                <p class="mt-2 font-display text-2xl font-bold text-gray-100 nums" x-text="polar.connected ? (polar.bpm || '--') + ' bpm' : 'offline'"></p>
                <button @click="connectPolar()" x-show="!polar.connected" class="mt-2 w-full h-10 rounded-xl bg-white/5 border border-white/10 text-sm font-semibold text-gray-200 hover:bg-white/10 transition">Connect</button>
                <p x-show="polar.connected" class="mt-2 text-xs text-emerald-300/80 nums" x-text="polar.rrCount + ' RR intervals'"></p>
            </div>
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full" :class="bangle.connected ? 'bg-cyan-400 animate-pulse' : 'bg-gray-600'"></span>
                    <span class="text-sm font-semibold text-gray-100">Bangle.js</span>
                    <span class="text-[10px] uppercase tracking-wide text-gray-500">device</span>
                </div>
                <p class="mt-2 font-display text-2xl font-bold text-gray-100 nums" x-text="bangle.connected ? (bangle.bpm || '--') + ' bpm' : 'offline'"></p>
                <button @click="connectBangle()" x-show="!bangle.connected" class="mt-2 w-full h-10 rounded-xl bg-white/5 border border-white/10 text-sm font-semibold text-gray-200 hover:bg-white/10 transition">Connect</button>
                <p x-show="bangle.connected" class="mt-2 text-xs text-cyan-300/80 nums" x-text="bangle.sampleCount + ' PPG samples'"></p>
            </div>
        </section>

        {{-- ===== capture controls ===== --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-display font-bold text-gray-100">Capture</h3>
                    <p class="text-sm text-gray-500">Sit still. Each ~2-minute window becomes one paired data point.</p>
                </div>
                <span class="font-display text-2xl font-bold nums" :class="capturing ? 'text-cyan-300' : 'text-gray-500'" x-text="fmtElapsed"></span>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <select x-model.number="captureSec" :disabled="capturing" class="h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-sm text-gray-100 disabled:opacity-50">
                    <option :value="240">4 min (2 points)</option>
                    <option :value="480">8 min (4 points)</option>
                    <option :value="720">12 min (6 points · best)</option>
                </select>
                <button @click="capturing ? stop() : start()" :disabled="!capturing && !(polar.connected && bangle.connected)"
                        class="flex-1 min-w-[10rem] h-11 rounded-xl px-5 text-sm font-semibold transition disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="capturing ? 'bg-rose-500/90 text-white' : 'bg-gradient-to-r from-indigo-500 to-cyan-500 text-gray-950'"
                        x-text="capturing ? 'Stop' : 'Start capture'"></button>
                <button @click="runDemo()" :disabled="capturing" class="h-11 rounded-xl px-4 text-sm font-semibold bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition disabled:opacity-40">Demo (no hardware)</button>
            </div>
        </section>

        {{-- ===== verdict ===== --}}
        <section x-show="summary" x-cloak class="rounded-2xl border p-4 md:p-5"
                 :class="summary && summary.pass ? 'border-emerald-500/30 bg-emerald-500/[0.06]' : 'border-amber-500/30 bg-amber-500/[0.06]'">
            <div class="flex items-center gap-2">
                <svg x-show="summary && summary.pass" class="h-6 w-6 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <svg x-show="summary && !summary.pass" x-cloak class="h-6 w-6 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                <h3 class="font-display text-lg font-bold" :class="summary && summary.pass ? 'text-emerald-200' : 'text-amber-200'" x-text="summary?.verdict"></h3>
            </div>
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div><p class="font-display text-xl font-bold text-gray-100 nums" x-text="summary?.polarMean"></p><p class="text-[11px] uppercase tracking-wide text-gray-500">Polar RMSSD</p></div>
                <div><p class="font-display text-xl font-bold text-gray-100 nums" x-text="summary?.bangleMean"></p><p class="text-[11px] uppercase tracking-wide text-gray-500">Bangle RMSSD</p></div>
                <div><p class="font-display text-xl font-bold nums" :class="summary && Math.abs(summary.biasRaw) <= 10 ? 'text-emerald-300' : 'text-amber-300'" x-text="summary?.bias"></p><p class="text-[11px] uppercase tracking-wide text-gray-500">mean bias</p></div>
                <div><p class="font-display text-xl font-bold text-gray-100 nums" x-text="summary?.r"></p><p class="text-[11px] uppercase tracking-wide text-gray-500">correlation</p></div>
            </div>
            <p class="mt-3 text-xs text-gray-500">95% limits of agreement: <span class="text-gray-300 nums" x-text="summary?.loa"></span>. Over <span x-text="points.length"></span> two-minute windows.</p>
        </section>

        {{-- ===== charts ===== --}}
        <section x-show="points.length > 0" x-cloak class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h4 class="text-sm font-semibold text-gray-300">RMSSD per minute — <span class="text-cyan-300">Bangle</span> vs <span class="text-emerald-300">Polar</span></h4>
            <canvas x-ref="ts" class="mt-2 w-full" style="height:180px"></canvas>
            <h4 class="mt-5 text-sm font-semibold text-gray-300">Bland–Altman — agreement (diff vs mean)</h4>
            <canvas x-ref="ba" class="mt-2 w-full" style="height:200px"></canvas>
            <p class="mt-3 text-[11px] text-gray-600">Each dot is one minute. The solid line is the mean bias; dashed lines are the 95% limits of agreement. Tight, centred-on-zero = the Bangle tracks the chest strap.</p>
        </section>

        {{-- ===== log ===== --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100">Log</h3>
            <div class="mt-2 space-y-1 max-h-48 overflow-y-auto font-mono text-xs">
                <template x-for="(l,i) in log" :key="i"><div :class="l.k==='err'?'text-rose-300':(l.k==='ok'?'text-emerald-300':'text-gray-400')"><span class="text-gray-600" x-text="l.t"></span> <span x-text="l.m"></span></div></template>
                <p x-show="log.length===0" class="text-gray-600">Connect both devices (or hit Demo), then Start. iOS isn't supported — desktop/Android Chrome only.</p>
            </div>
        </section>
    </div>

    <script>
        const HR_SVC = '0000180d-0000-1000-8000-00805f9b34fb';
        const HR_CHR = '00002a37-0000-1000-8000-00805f9b34fb';
        const NUS_SVC = '6e400001-b5a3-f393-e0a9-e50e24dcca9e';
        const NUS_TX  = '6e400003-b5a3-f393-e0a9-e50e24dcca9e';

        function validationLab(cfg) {
            return {
                polar: { connected: false, bpm: 0, rrCount: 0, _dev: null },
                bangle: { connected: false, bpm: 0, sampleCount: 0, _dev: null, _rx: '' },
                captureSec: 480, WINDOW_MS: 120000, capturing: false, elapsed: 0,
                _t0: 0, _tick: null, _winTimer: null,
                _rr: [], _ppg: [],         // {t,v} buffers for the current minute
                points: [], summary: null, log: [],

                init() { window.addEventListener('resize', () => this._draw()); },
                get fmtElapsed() { const s = this.elapsed; return (s < 0 ? 0 : Math.floor(s / 60)) + ':' + String(Math.floor(s % 60)).padStart(2, '0'); },

                // ---------- Polar H10 (standard HR service, RR intervals) ----------
                async connectPolar() {
                    try {
                        const d = await navigator.bluetooth.requestDevice({ filters: [{ namePrefix: 'Polar' }], optionalServices: [HR_SVC] });
                        this.polar._dev = d;
                        d.addEventListener('gattserverdisconnected', () => { this.polar.connected = false; });
                        const s = await d.gatt.connect();
                        const c = await (await s.getPrimaryService(HR_SVC)).getCharacteristic(HR_CHR);
                        await c.startNotifications();
                        c.addEventListener('characteristicvaluechanged', (e) => this._onPolar(e.target.value));
                        this.polar.connected = true;
                        this._log('ok', 'Polar H10 connected');
                    } catch (e) { this._log('err', 'Polar: ' + (e.message || e)); }
                },
                _onPolar(dv) {
                    const flags = dv.getUint8(0); let i = 1;
                    this.polar.bpm = (flags & 0x01) ? dv.getUint16(i, true) : dv.getUint8(i);
                    i += (flags & 0x01) ? 2 : 1;
                    if (flags & 0x08) i += 2;          // energy expended
                    if (flags & 0x10) {                // RR intervals present
                        for (; i + 2 <= dv.byteLength; i += 2) {
                            const rr = dv.getUint16(i, true) / 1024 * 1000; // 1/1024 s → ms
                            if (this.capturing) this._rr.push({ t: Date.now(), v: rr });
                            this.polar.rrCount++;
                        }
                    }
                },

                // ---------- Bangle (T1 binary frames → raw PPG) ----------
                async connectBangle() {
                    try {
                        const d = await navigator.bluetooth.requestDevice({ filters: [{ namePrefix: 'Bangle' }], optionalServices: [NUS_SVC] });
                        this.bangle._dev = d;
                        d.addEventListener('gattserverdisconnected', () => { this.bangle.connected = false; });
                        const s = await d.gatt.connect();
                        const tx = await (await s.getPrimaryService(NUS_SVC)).getCharacteristic(NUS_TX);
                        await tx.startNotifications();
                        tx.addEventListener('characteristicvaluechanged', (e) => this._onBangle(e.target.value));
                        this.bangle.connected = true;
                        this._log('ok', 'Bangle.js connected');
                    } catch (e) { this._log('err', 'Bangle: ' + (e.message || e)); }
                },
                _onBangle(dv) {
                    this.bangle._rx += new TextDecoder().decode(dv); let nl;
                    while ((nl = this.bangle._rx.indexOf('\n')) >= 0) {
                        const line = this.bangle._rx.slice(0, nl).trim();
                        this.bangle._rx = this.bangle._rx.slice(nl + 1);
                        if (line.startsWith('T1:')) this._frame(line.slice(3));
                    }
                },
                _frame(b64) {
                    let bin; try { bin = atob(b64); } catch (e) { return; }
                    const by = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) by[i] = bin.charCodeAt(i);
                    if (by.length < 16) return;
                    const dv = new DataView(by.buffer);
                    const count = dv.getUint16(2, true);
                    const epoch = dv.getUint32(8, true) * 4294967296 + dv.getUint32(4, true);
                    for (let i = 0; i < count; i++) {
                        const o = 16 + i * 12; if (o + 12 > by.length) break;
                        if (this.capturing) this._ppg.push({ t: epoch + dv.getUint32(o, true), v: dv.getInt16(o + 4, true) });
                        this.bangle.sampleCount++;
                    }
                },

                // ---------- capture ----------
                start() {
                    this.points = []; this.summary = null; this._rr = []; this._ppg = [];
                    this.capturing = true; this._t0 = Date.now(); this.elapsed = 0;
                    this._log('ok', 'capture started (' + (this.captureSec / 60) + ' min)');
                    this._tick = setInterval(() => {
                        this.elapsed = (Date.now() - this._t0) / 1000;
                        if (this.elapsed >= this.captureSec) this.stop();
                    }, 250);
                    this._winTimer = setInterval(() => this._closeWindow(), this.WINDOW_MS);
                },
                async stop() {
                    if (!this.capturing) return;
                    this.capturing = false;
                    clearInterval(this._tick); clearInterval(this._winTimer);
                    await this._closeWindow();         // flush the final partial minute
                    this._summarize();
                    this._log('ok', 'capture complete — ' + this.points.length + ' windows');
                },

                // Close the last ~60s: Polar RMSSD client-side, Bangle RMSSD via the real server.
                async _closeWindow() {
                    const now = Date.now(), from = now - this.WINDOW_MS;
                    const rr = this._rr.filter(x => x.t >= from).map(x => x.v);
                    const ppg = this._ppg.filter(x => x.t >= from);
                    this._rr = this._rr.filter(x => x.t < from); this._ppg = this._ppg.filter(x => x.t < from);
                    const pol = this._rmssd(rr);
                    if (pol === null || ppg.length < 400) return;
                    const wn = this.points.length + 1;
                    let ban = null;
                    try {
                        const dur = (ppg[ppg.length - 1].t - ppg[0].t) / 1000 || 1;
                        const rate = Math.max(1, Math.round(ppg.length / dur));
                        const r = await fetch(cfg.previewUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ ppg: ppg.map(x => x.v), sample_rate_hz: rate, start: new Date(ppg[0].t).toISOString(), end: new Date(ppg[ppg.length - 1].t).toISOString() }),
                        });
                        const j = await r.json();
                        // `valid` is the strict recovery-grade gate; for a methods comparison we plot any
                        // window with a real RMSSD, flagging marginal signal quality rather than hiding it.
                        ban = (j && typeof j.rmssd === 'number') ? j.rmssd : null;
                        if (ban === null) this._log('info', 'window ' + wn + ': no RMSSD (signal too weak) — skipped');
                        else if (j.valid === false) this._log('info', 'window ' + wn + ': marginal signal quality — plotted');
                    } catch (e) { this._log('err', 'preview: ' + (e.message || e)); }
                    if (ban !== null) {
                        this.points.push({ polar: pol, bangle: ban });
                        this.bangle.bpm = Math.round(60000 / (this._mean(rr) || 1000));
                        this._draw();
                    }
                },

                // ---------- demo ----------
                runDemo() {
                    this.points = []; this.summary = null;
                    const rng = (s => () => (s = (s * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff)(99);
                    for (let m = 0; m < 6; m++) {
                        const polar = 42 + 10 * Math.sin(m / 2) + (rng() - 0.5) * 6;
                        const bangle = polar + 2.5 + (rng() - 0.5) * 7;   // small +bias + realistic scatter
                        this.points.push({ polar: +polar.toFixed(1), bangle: +Math.max(8, bangle).toFixed(1) });
                    }
                    this._summarize(); this._draw();
                    this._log('ok', 'demo rendered (simulated paired RMSSD)');
                },

                // ---------- stats ----------
                _rmssd(ibi) {
                    ibi = (ibi || []).filter(x => x >= 300 && x <= 2000);
                    if (ibi.length < 3) return null;
                    let s = 0, n = 0; for (let i = 1; i < ibi.length; i++) { const d = ibi[i] - ibi[i - 1]; s += d * d; n++; }
                    return Math.sqrt(s / n);
                },
                _mean(a) { return a.length ? a.reduce((x, y) => x + y, 0) / a.length : 0; },
                _summarize() {
                    const p = this.points; if (!p.length) { this.summary = null; return; }
                    const pol = p.map(x => x.polar), ban = p.map(x => x.bangle), diff = p.map(x => x.bangle - x.polar);
                    const bias = this._mean(diff);
                    const sd = Math.sqrt(this._mean(diff.map(d => (d - bias) ** 2)));
                    const r = this._pearson(pol, ban);
                    const pass = Math.abs(bias) <= 10 && (1.96 * sd) <= 20;
                    this.summary = {
                        polarMean: this._mean(pol).toFixed(1) + ' ms',
                        bangleMean: this._mean(ban).toFixed(1) + ' ms',
                        bias: (bias >= 0 ? '+' : '') + bias.toFixed(1) + ' ms', biasRaw: bias,
                        loa: (bias - 1.96 * sd).toFixed(1) + ' to ' + (bias + 1.96 * sd).toFixed(1) + ' ms',
                        r: isNaN(r) ? '—' : r.toFixed(2), pass,
                        verdict: pass ? 'Validated — the Bangle tracks the Polar H10' : 'Marginal — tighten fit, warm up, re-test',
                    };
                },
                _pearson(a, b) {
                    const n = a.length, ma = this._mean(a), mb = this._mean(b);
                    let num = 0, da = 0, db = 0;
                    for (let i = 0; i < n; i++) { const x = a[i] - ma, y = b[i] - mb; num += x * y; da += x * x; db += y * y; }
                    return num / Math.sqrt(da * db || 1);
                },

                // ---------- charts ----------
                _draw() { this.$nextTick(() => { this._drawTS(); this._drawBA(); }); },
                _fit(c) { c.width = c.clientWidth * devicePixelRatio; c.height = c.clientHeight * devicePixelRatio; return [c.getContext('2d'), c.width, c.height]; },
                _drawTS() {
                    const c = this.$refs.ts; if (!c || !this.points.length) return;
                    const [ctx, w, h] = this._fit(c); ctx.clearRect(0, 0, w, h);
                    const all = this.points.flatMap(p => [p.polar, p.bangle]);
                    let lo = Math.min(...all) - 5, hi = Math.max(...all) + 5; if (hi - lo < 1) hi = lo + 1;
                    const pad = 28 * devicePixelRatio, n = this.points.length;
                    const X = i => pad + (n === 1 ? w / 2 : (i / (n - 1)) * (w - pad * 1.5));
                    const Y = v => h - pad - ((v - lo) / (hi - lo)) * (h - pad * 1.6);
                    const line = (key, col) => { ctx.beginPath(); ctx.strokeStyle = col; ctx.lineWidth = 2 * devicePixelRatio;
                        this.points.forEach((p, i) => { i ? ctx.lineTo(X(i), Y(p[key])) : ctx.moveTo(X(i), Y(p[key])); }); ctx.stroke();
                        ctx.fillStyle = col; this.points.forEach((p, i) => { ctx.beginPath(); ctx.arc(X(i), Y(p[key]), 3 * devicePixelRatio, 0, 7); ctx.fill(); }); };
                    line('polar', '#34d399'); line('bangle', '#22d3ee');
                },
                _drawBA() {
                    const c = this.$refs.ba; if (!c || !this.points.length || !this.summary) return;
                    const [ctx, w, h] = this._fit(c); ctx.clearRect(0, 0, w, h);
                    const means = this.points.map(p => (p.polar + p.bangle) / 2), diffs = this.points.map(p => p.bangle - p.polar);
                    const bias = this._mean(diffs), sd = Math.sqrt(this._mean(diffs.map(d => (d - bias) ** 2)));
                    const dmax = Math.max(20, ...diffs.map(d => Math.abs(d)), Math.abs(bias) + 2 * sd) * 1.1;
                    let mlo = Math.min(...means) - 3, mhi = Math.max(...means) + 3; if (mhi - mlo < 1) mhi = mlo + 1;
                    const pad = 30 * devicePixelRatio;
                    const X = m => pad + ((m - mlo) / (mhi - mlo)) * (w - pad * 1.5);
                    const Y = d => h / 2 - (d / dmax) * (h / 2 - pad);
                    const hline = (d, col, dash) => { ctx.beginPath(); ctx.setLineDash(dash || []); ctx.strokeStyle = col; ctx.lineWidth = 1.5 * devicePixelRatio; ctx.moveTo(pad, Y(d)); ctx.lineTo(w - pad * 0.5, Y(d)); ctx.stroke(); ctx.setLineDash([]); };
                    hline(0, 'rgba(255,255,255,0.15)'); hline(bias, '#818cf8'); hline(bias + 1.96 * sd, 'rgba(251,191,36,0.6)', [5, 5]); hline(bias - 1.96 * sd, 'rgba(251,191,36,0.6)', [5, 5]);
                    ctx.fillStyle = '#22d3ee'; this.points.forEach((p, i) => { ctx.beginPath(); ctx.arc(X(means[i]), Y(diffs[i]), 3.5 * devicePixelRatio, 0, 7); ctx.fill(); });
                },
                _log(k, m) { this.log.unshift({ k, m, t: new Date().toLocaleTimeString() }); if (this.log.length > 40) this.log.pop(); },
            };
        }
    </script>
</x-titan-layout>
