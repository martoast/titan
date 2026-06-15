<x-titan-layout title="Simulator" subtitle="Titan virtual band — a digital twin driving the real pipeline">

    {{-- ===== Importmap for Three.js (CDN, no npm/vite) ===== --}}
    <script type="importmap">
    {
        "imports": {
            "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
            "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
        }
    }
    </script>

    @php
        // Pass the per-state physiology presets + signing context to the page.
        $simConfig = [
            'states' => $states,
            'deviceId' => $device->device_id,
            'secret' => $deviceSecret,
            'ingestUrl' => $ingestUrl,
            'timezone' => $device->effectiveTimezone(),
            'csrf' => csrf_token(),
        ];
    @endphp

    <div x-data="simulator(@js($simConfig))" x-init="init()" class="space-y-4 md:space-y-5">

        {{-- ===== 3D band render ===== --}}
        <div class="relative rounded-2xl border border-white/5 bg-gradient-to-b from-white/[0.04] to-transparent overflow-hidden">
            <div class="absolute left-4 top-4 z-10">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Titan Band</div>
                <div class="font-display text-sm font-bold text-gray-200">Digital Twin</div>
            </div>
            {{-- live state pill --}}
            <div class="absolute right-4 top-4 z-10 flex items-center gap-2 rounded-full border border-white/10 bg-black/40 px-3 py-1.5 backdrop-blur">
                <span class="h-2 w-2 rounded-full" :style="`background:${stateColorCss}; box-shadow:0 0 8px ${stateColorCss}`"></span>
                <span class="text-xs font-medium text-gray-200" x-text="states[state].label"></span>
            </div>
            <div class="absolute bottom-3 left-1/2 -translate-x-1/2 z-10 text-[10px] text-gray-600">drag to rotate · pinch / scroll to zoom</div>
            <canvas x-ref="canvas" class="block w-full" style="height: 56vw; max-height: 360px;"></canvas>
        </div>

        {{-- ===== Live readout ===== --}}
        <div class="grid grid-cols-3 gap-3 md:gap-4">
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Heart rate</div>
                <div class="mt-1 font-display text-3xl font-bold nums text-gray-100"
                     :class="state === 'run' || state === 'walk' ? 'text-amber-300' : ''">
                    <span x-text="bpm"></span><span class="text-sm font-normal text-gray-500"> bpm</span>
                </div>
            </div>
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">HRV (RMSSD)</div>
                <div class="mt-1 font-display text-3xl font-bold nums text-gray-100">
                    <span x-text="rmssd"></span><span class="text-sm font-normal text-gray-500"> ms</span>
                </div>
            </div>
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Motion</div>
                <div class="mt-1 font-display text-3xl font-bold nums text-gray-100">
                    <span x-text="motion"></span><span class="text-sm font-normal text-gray-500"> ct</span>
                </div>
            </div>
        </div>

        {{-- live PPG-ish waveform --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <div class="flex items-center justify-between mb-2">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Live PPG · IBI stream</div>
                <div class="text-xs text-gray-500 nums"><span x-text="lastIbi"></span> ms</div>
            </div>
            <canvas x-ref="wave" class="block w-full" style="height: 64px;"></canvas>
        </div>

        {{-- ===== Activity state controls ===== --}}
        <div>
            <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-2">Wear state</div>
            <div class="grid grid-cols-4 gap-2">
                @php $live = ['deep' => 'Deep', 'rest' => 'Rest', 'walk' => 'Walk', 'run' => 'Run']; @endphp
                @foreach ($live as $key => $label)
                    <button type="button" @click="setState('{{ $key }}')"
                            class="h-12 rounded-xl border text-sm font-semibold transition active:scale-95"
                            :class="state === '{{ $key }}'
                                ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-200'
                                : 'border-white/10 bg-white/[0.03] text-gray-400 active:bg-white/10'">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- ===== Simulate a night ===== --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="font-display text-base font-bold text-gray-100">Simulate a night</div>
                    <p class="text-xs text-gray-500 mt-0.5">Fast-forward 8h of sleep and stream IBI + summary to Titan.</p>
                </div>
                <span class="shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-medium"
                      :class="paired ? 'border-emerald-500/30 text-emerald-300 bg-emerald-500/10' : 'border-white/10 text-gray-500'">
                    <span x-text="paired ? 'Paired · ' + deviceId : 'Local only'"></span>
                </span>
            </div>

            {{-- speed control --}}
            <div>
                <div class="flex items-center justify-between text-xs text-gray-500 mb-1.5">
                    <span>Playback speed</span>
                    <span class="nums text-gray-300"><span x-text="speed"></span>×</span>
                </div>
                <input type="range" min="60" max="2400" step="60" x-model.number="speed"
                       class="w-full accent-indigo-500">
            </div>

            <button type="button" @click="simulateNight()" :disabled="running"
                    class="w-full h-12 rounded-xl font-semibold bg-gradient-to-r from-indigo-500 to-cyan-400 text-gray-950
                           disabled:opacity-50 active:scale-[0.99] transition">
                <span x-show="!running">▶ Simulate &amp; stream a night</span>
                <span x-show="running" x-cloak>Simulating… <span class="nums" x-text="Math.round(nightProgress * 100) + '%'"></span></span>
            </button>

            {{-- night progress + hypnogram --}}
            <div x-show="running || nightDone" x-cloak class="space-y-3">
                <div class="h-2 w-full rounded-full bg-white/5 overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400 transition-all"
                         :style="`width:${Math.round(nightProgress * 100)}%`"></div>
                </div>
                {{-- hypnogram --}}
                <div class="flex h-10 w-full overflow-hidden rounded-lg border border-white/5">
                    <template x-for="(seg, i) in hypnogram" :key="i">
                        <div :style="`width:${seg.pct}%; background:${seg.color}`" :title="seg.label"></div>
                    </template>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-500">
                    <span><span class="inline-block h-2 w-2 rounded-full mr-1 align-middle" style="background:#6366f1"></span>Deep</span>
                    <span><span class="inline-block h-2 w-2 rounded-full mr-1 align-middle" style="background:#22d3ee"></span>Light</span>
                    <span><span class="inline-block h-2 w-2 rounded-full mr-1 align-middle" style="background:#a78bfa"></span>REM</span>
                    <span><span class="inline-block h-2 w-2 rounded-full mr-1 align-middle" style="background:#475569"></span>Awake</span>
                </div>
            </div>

            {{-- night result --}}
            <div x-show="nightDone" x-cloak class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-1">
                <template x-for="m in nightMetrics" :key="m.label">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-500" x-text="m.label"></div>
                        <div class="font-display text-xl font-bold nums text-gray-100" x-text="m.value"></div>
                    </div>
                </template>
            </div>

            {{-- delivery status --}}
            <div x-show="status" x-cloak class="rounded-xl px-3 py-2.5 text-sm"
                 :class="delivered ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-300'
                                   : 'bg-amber-500/10 border border-amber-500/20 text-amber-300'">
                <span x-text="status"></span>
            </div>
        </div>

        <p class="text-center text-[11px] text-gray-600 pb-2">
            Synthetic data, signed &amp; ingested exactly like the hardware would —
            Recovery / Sleep / Coach reflect the worn device.
        </p>
    </div>

    {{-- ===== The Three.js scene + physiology engine (module) ===== --}}
    <script type="module">
        import * as THREE from 'three';
        import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
        import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
        import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
        import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
        import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
        import { OutputPass } from 'three/addons/postprocessing/OutputPass.js';

        // Expose a factory the Alpine component calls; it owns the 3D scene + sim loop.
        window.__titanBand = function (canvas, getState, getStates) {
            const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1.15;

            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 100);
            camera.position.set(0, 1.4, 6.2);

            const controls = new OrbitControls(camera, canvas);
            controls.enableDamping = true;
            controls.dampingFactor = 0.08;
            controls.minDistance = 4;
            controls.maxDistance = 10;
            controls.enablePan = false;
            controls.autoRotate = true;
            controls.autoRotateSpeed = 0.8;
            controls.target.set(0, 0, 0);

            // ---- Lighting: soft studio + Titan indigo/cyan rim ----
            scene.add(new THREE.AmbientLight(0x404a66, 0.7));
            const key = new THREE.DirectionalLight(0xffffff, 2.2);
            key.position.set(4, 6, 5);
            scene.add(key);
            const rimI = new THREE.PointLight(0x6366f1, 60, 30); // indigo
            rimI.position.set(-5, 2, -3);
            scene.add(rimI);
            const rimC = new THREE.PointLight(0x22d3ee, 45, 30); // cyan
            rimC.position.set(5, -2, -2);
            scene.add(rimC);
            const fill = new THREE.DirectionalLight(0x88aaff, 0.5);
            fill.position.set(-3, 1, 4);
            scene.add(fill);

            // ---- Realistic reflections (the #1 premium lever): a generated studio env ----
            const pmrem = new THREE.PMREMGenerator(renderer);
            scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;

            const band = new THREE.Group();
            scene.add(band);

            // ---- Continuous silicone strap: one closed, flattened loop (solid, no hollow
            //      back). The sensor case sits at the centre; the band wraps top→bottom. ----
            const siliconeMat = new THREE.MeshPhysicalMaterial({
                color: 0x0d0f14, roughness: 0.5, metalness: 0.0,
                clearcoat: 0.55, clearcoatRoughness: 0.45,
                sheen: 0.6, sheenRoughness: 0.5, sheenColor: new THREE.Color(0x4456a0),
                envMapIntensity: 0.9,
            });
            const loopPts = [];
            const RX = 1.02, RY = 2.05;                       // narrow + tall → a watch band
            for (let i = 0; i < 56; i++) {
                const a = (i / 56) * Math.PI * 2;
                loopPts.push(new THREE.Vector3(Math.sin(a) * RX, Math.cos(a) * RY, 0));
            }
            const loopCurve = new THREE.CatmullRomCurve3(loopPts, true);
            const strapGeo = new THREE.TubeGeometry(loopCurve, 260, 0.34, 32, true);
            strapGeo.scale(1, 1, 0.46);                        // flatten into a band section
            const strap = new THREE.Mesh(strapGeo, siliconeMat);
            band.add(strap);

            // a soft stitched-edge highlight line down each side of the band
            const seamMat = new THREE.MeshStandardMaterial({ color: 0x2a3552, roughness: 0.6, metalness: 0.1, emissive: 0x141a2e, emissiveIntensity: 0.4 });

            // ---- Sensor module: brushed-metal case ----
            const bodyMat = new THREE.MeshPhysicalMaterial({
                color: 0x1c2230, roughness: 0.28, metalness: 0.92,
                clearcoat: 0.4, clearcoatRoughness: 0.25, envMapIntensity: 1.4,
            });
            const body = new THREE.Mesh(new RoundedBox(1.95, 1.22, 0.6, 0.26, 8), bodyMat);
            band.add(body);

            // polished chamfer/bezel ring around the glass for a jewellery edge
            const bezel = new THREE.Mesh(
                new RoundedBox(1.82, 1.08, 0.12, 0.22, 8),
                new THREE.MeshPhysicalMaterial({ color: 0x39435e, roughness: 0.12, metalness: 1.0, envMapIntensity: 1.8 })
            );
            bezel.position.z = 0.27;
            band.add(bezel);

            // Glossy domed top glass (clearcoat + faint transmission) — reads like a screen
            const glass = new THREE.Mesh(
                new RoundedBox(1.66, 0.92, 0.08, 0.2, 6),
                new THREE.MeshPhysicalMaterial({
                    color: 0x05070c, roughness: 0.04, metalness: 0.0,
                    clearcoat: 1, clearcoatRoughness: 0.03, envMapIntensity: 1.6,
                    reflectivity: 0.9,
                })
            );
            glass.position.z = 0.34;
            band.add(glass);

            // ---- Underside optical stack: matte-black cavity + dual windows ----
            const cavityMat = new THREE.MeshStandardMaterial({ color: 0x050608, roughness: 1.0, metalness: 0 });
            const cavity = new THREE.Mesh(new RoundedBox(1.55, 0.82, 0.08, 0.16, 4), cavityMat);
            cavity.position.z = -0.33;
            band.add(cavity);

            // black divider between LED window and photodiode window
            const divider = new THREE.Mesh(
                new THREE.BoxGeometry(0.06, 0.7, 0.12),
                new THREE.MeshStandardMaterial({ color: 0x000000, roughness: 1 })
            );
            divider.position.set(0, 0, -0.36);
            band.add(divider);

            // green PPG LEDs (left window) — these pulse with the heartbeat
            const ledMat = new THREE.MeshStandardMaterial({
                color: 0x18ff7a, emissive: 0x16e070, emissiveIntensity: 1.2, roughness: 0.4,
            });
            const leds = [];
            for (let i = 0; i < 2; i++) {
                const led = new THREE.Mesh(new THREE.CircleGeometry(0.12, 24), ledMat);
                led.position.set(-0.32, 0.18 - i * 0.36, -0.375);
                led.rotation.y = Math.PI;
                band.add(led);
                leds.push(led);
            }
            // glow light that pulses
            const ppgGlow = new THREE.PointLight(0x18ff7a, 0, 6);
            ppgGlow.position.set(-0.32, 0, -0.7);
            band.add(ppgGlow);

            // photodiode windows (right) — dark, slightly reflective
            const pdMat = new THREE.MeshPhysicalMaterial({ color: 0x0b1410, roughness: 0.15, metalness: 0.3, clearcoat: 1 });
            for (let i = 0; i < 2; i++) {
                const pd = new THREE.Mesh(new THREE.CircleGeometry(0.11, 24), pdMat);
                pd.position.set(0.32, 0.18 - i * 0.36, -0.375);
                pd.rotation.y = Math.PI;
                band.add(pd);
            }

            // ---- Status indicator on the body edge ----
            const statusMat = new THREE.MeshStandardMaterial({ color: 0x22d3ee, emissive: 0x22d3ee, emissiveIntensity: 0.8 });
            const status = new THREE.Mesh(new THREE.CircleGeometry(0.05, 16), statusMat);
            status.position.set(0.78, -0.42, 0.33);
            band.add(status);

            // subtle Titan logo etch (a thin gradient ring) on the glass
            const ring = new THREE.Mesh(
                new THREE.RingGeometry(0.16, 0.2, 32),
                new THREE.MeshBasicMaterial({ color: 0x6366f1, transparent: true, opacity: 0.5 })
            );
            ring.position.set(0, 0, 0.37);
            band.add(ring);

            band.rotation.x = -0.28;
            band.rotation.y = 0.18;

            // ---- Studio backdrop gradient: gives the product depth and lets bloom
            //      composite cleanly (no transparent-background artefacts) ----
            const bgC = document.createElement('canvas'); bgC.width = 4; bgC.height = 256;
            const bx = bgC.getContext('2d');
            const bgGrad = bx.createLinearGradient(0, 0, 0, 256);
            bgGrad.addColorStop(0, '#121723'); bgGrad.addColorStop(0.5, '#0a0d15'); bgGrad.addColorStop(1, '#05070b');
            bx.fillStyle = bgGrad; bx.fillRect(0, 0, 4, 256);
            const bgTex = new THREE.CanvasTexture(bgC); bgTex.colorSpace = THREE.SRGBColorSpace;
            scene.background = bgTex;

            // ---- Soft contact shadow grounding the band ----
            const shC = document.createElement('canvas'); shC.width = shC.height = 256;
            const sx = shC.getContext('2d');
            const shGrad = sx.createRadialGradient(128, 128, 6, 128, 128, 128);
            shGrad.addColorStop(0, 'rgba(0,0,0,0.55)'); shGrad.addColorStop(0.7, 'rgba(0,0,0,0.18)'); shGrad.addColorStop(1, 'rgba(0,0,0,0)');
            sx.fillStyle = shGrad; sx.fillRect(0, 0, 256, 256);
            const shadow = new THREE.Mesh(
                new THREE.PlaneGeometry(9, 5.5),
                new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(shC), transparent: true, depthWrite: false })
            );
            shadow.rotation.x = -Math.PI / 2; shadow.position.y = -2.75;
            scene.add(shadow);

            // ---- RoundedBox helper (Three has one in addons, but keep CDN deps minimal) ----
            function RoundedBox(w, h, d, r, s) {
                const shape = new THREE.Shape();
                const eps = 0.0001, radius = r - eps;
                shape.absarc(-w / 2 + r, -h / 2 + r, eps, -Math.PI / 2, -Math.PI, true);
                shape.absarc(-w / 2 + r, h / 2 - r, eps, Math.PI, Math.PI / 2, true);
                shape.absarc(w / 2 - r, h / 2 - r, eps, Math.PI / 2, 0, true);
                shape.absarc(w / 2 - r, -h / 2 + r, eps, 0, -Math.PI / 2, true);
                const geo = new THREE.ExtrudeGeometry(shape, {
                    depth: d - r * 2, bevelEnabled: true, bevelSegments: s,
                    steps: 1, bevelSize: radius, bevelThickness: radius, curveSegments: s,
                });
                geo.center();
                return geo;
            }

            // ---- Bloom: makes the optical LEDs + polished edges glow (premium). Guarded:
            //      falls back to a direct render if the composer can't initialise. ----
            let composer = null;
            try {
                composer = new EffectComposer(renderer);
                composer.addPass(new RenderPass(scene, camera));
                composer.addPass(new UnrealBloomPass(new THREE.Vector2(1, 1), 0.55, 0.7, 0.82));
                composer.addPass(new OutputPass());
            } catch (e) { composer = null; }

            function resize() {
                const w = canvas.clientWidth, h = canvas.clientHeight;
                if (w === 0 || h === 0) return;
                renderer.setSize(w, h, false);
                if (composer) composer.setSize(w, h);
                camera.aspect = w / h;
                camera.updateProjectionMatrix();
            }
            window.addEventListener('resize', resize);
            // Also correct the size whenever the canvas itself is laid out/changes —
            // guards against clientHeight being 0 at the exact moment of init.
            if (window.ResizeObserver) { new ResizeObserver(resize).observe(canvas); }
            resize();

            // ---- Pulse state driven from outside (heartbeat-synced) ----
            let pulse = 0;          // 0..1, set on each beat, decays
            const api = {
                beat() { pulse = 1; },
                dispose() { renderer.dispose(); if (composer) composer.dispose && composer.dispose(); },
            };

            const clock = new THREE.Clock();
            function loop() {
                api._raf = requestAnimationFrame(loop);
                const dt = clock.getDelta();
                pulse = Math.max(0, pulse - dt * 3.2); // decay after each beat
                const st = getState();
                const calm = st === 'deep' || st === 'rest';
                // calm green vs elevated amber
                const baseColor = calm ? new THREE.Color(0x18ff7a) : new THREE.Color(0xffb020);
                const lit = baseColor.clone().multiplyScalar(0.7 + pulse * 1.5);
                ledMat.emissive.copy(baseColor);
                ledMat.emissiveIntensity = 1.0 + pulse * 3.4;
                ledMat.color.copy(lit);
                ppgGlow.color.copy(baseColor);
                ppgGlow.intensity = 1.2 + pulse * 6.5;
                ring.material.color.copy(calm ? new THREE.Color(0x6366f1) : new THREE.Color(0xf59e0b));

                controls.update();
                if (composer) composer.render(); else renderer.render(scene, camera);
            }
            loop();
            return api;
        };
    </script>

    {{-- ===== Alpine component: physiology engine + pipeline streaming ===== --}}
    <script>
        function simulator(cfg) {
            return {
                states: cfg.states,
                deviceId: cfg.deviceId,
                secret: cfg.secret,
                ingestUrl: cfg.ingestUrl,
                timezone: cfg.timezone,
                paired: !!cfg.deviceId,

                state: 'rest',
                bpm: 58,
                rmssd: 65,
                motion: 1,
                lastIbi: 1034,
                meanIbi: 1034,

                speed: 600,
                running: false,
                nightDone: false,
                nightProgress: 0,
                hypnogram: [],
                nightMetrics: [],
                status: '',
                delivered: false,

                _band: null,
                _ibiBuf: [],          // rolling IBI for live RMSSD
                _beatTimer: null,
                _rsaPhase: 0,
                _wave: [],            // waveform points
                _waveCtx: null,

                get stateColorCss() {
                    return (this.state === 'deep' || this.state === 'rest') ? '#18ff7a' : '#ffb020';
                },

                init() {
                    this.$nextTick(() => {
                        this._waveCtx = this.$refs.wave.getContext('2d');
                        this.scheduleBeat();
                        this.drawWave();
                        // Boot the 3D band once its deferred module has defined the global.
                        // Alpine's init can fire before the <script type="module"> evaluates,
                        // so retry for a few seconds instead of failing once.
                        let tries = 0;
                        const boot = () => {
                            if (window.__titanBand) {
                                try {
                                    this._band = window.__titanBand(this.$refs.canvas, () => this.state, () => this.states);
                                } catch (e) { console.warn('3D init failed', e); }
                            } else if (tries++ < 300) {
                                requestAnimationFrame(boot);
                            } else {
                                console.warn('3D band module never loaded (Three.js CDN blocked?)');
                            }
                        };
                        boot();
                    });
                },

                // --- gaussian noise (Box-Muller) ---
                gauss() {
                    let u = 0, v = 0;
                    while (u === 0) u = Math.random();
                    while (v === 0) v = Math.random();
                    return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
                },

                // generate the NEXT inter-beat interval for the current state
                nextIbi() {
                    const s = this.states[this.state];
                    const meanIbi = 60000 / s.hr;
                    const sd = Math.min(s.rmssd / 1.4142, meanIbi * 0.18);
                    this._rsaPhase += 2 * Math.PI * 0.25 * (this.meanIbi / 1000);
                    const rsa = Math.sin(this._rsaPhase) * sd * 0.6;
                    let ibi = meanIbi + rsa + this.gauss() * sd * 0.9;
                    ibi = Math.max(320, Math.min(1900, ibi));
                    this.meanIbi += (meanIbi - this.meanIbi) * 0.08; // ease toward target HR
                    return Math.round(ibi);
                },

                scheduleBeat() {
                    const ibi = this.nextIbi();
                    this.lastIbi = ibi;
                    this._ibiBuf.push(ibi);
                    if (this._ibiBuf.length > 60) this._ibiBuf.shift();

                    // live metrics
                    this.bpm = Math.round(60000 / ibi);
                    this.rmssd = this.computeRmssd(this._ibiBuf);
                    const m = this.states[this.state].motion;
                    this.motion = Math.max(0, Math.round(m + this.gauss() * m * 0.3));

                    if (this._band) this._band.beat();
                    this.pushWave();

                    this._beatTimer = setTimeout(() => this.scheduleBeat(), ibi);
                },

                computeRmssd(buf) {
                    if (buf.length < 3) return this.rmssd;
                    let sum = 0;
                    for (let i = 1; i < buf.length; i++) { const d = buf[i] - buf[i - 1]; sum += d * d; }
                    return Math.round(Math.sqrt(sum / (buf.length - 1)));
                },

                setState(s) {
                    this.state = s;
                    this.meanIbi = 60000 / this.states[s].hr;
                    this._ibiBuf = [];
                },

                // --- live PPG-ish waveform (a dicrotic-ish pulse per beat) ---
                pushWave() { this._beatFlash = 1; },
                drawWave() {
                    const ctx = this._waveCtx;
                    if (!ctx) { requestAnimationFrame(() => this.drawWave()); return; }
                    const cv = this.$refs.wave;
                    const dpr = Math.min(window.devicePixelRatio, 2);
                    const w = cv.clientWidth, h = cv.clientHeight;
                    if (cv.width !== w * dpr) { cv.width = w * dpr; cv.height = h * dpr; ctx.scale(dpr, dpr); }
                    // advance a synthetic pulse driven by current bpm
                    const t = performance.now() / 1000;
                    const f = this.bpm / 60;
                    this._wave.push(Math.sin(2 * Math.PI * f * t) * 0.6 + Math.sin(4 * Math.PI * f * t) * 0.18);
                    if (this._wave.length > w) this._wave.shift();
                    ctx.clearRect(0, 0, w, h);
                    const calm = this.state === 'deep' || this.state === 'rest';
                    const grad = ctx.createLinearGradient(0, 0, w, 0);
                    grad.addColorStop(0, calm ? '#6366f1' : '#f59e0b');
                    grad.addColorStop(1, calm ? '#22d3ee' : '#fb7185');
                    ctx.strokeStyle = grad; ctx.lineWidth = 2; ctx.beginPath();
                    this._wave.forEach((v, i) => {
                        const y = h / 2 - v * h * 0.4;
                        i === 0 ? ctx.moveTo(0, y) : ctx.lineTo(i, y);
                    });
                    ctx.stroke();
                    requestAnimationFrame(() => this.drawWave());
                },

                // ============ Simulate a night ============
                async simulateNight() {
                    if (this.running) return;
                    this.running = true; this.nightDone = false; this.nightProgress = 0;
                    this.status = ''; this.hypnogram = [];

                    const minutes = 480;
                    const segments = this.buildHypnogram(minutes);
                    const colors = { deep: '#6366f1', sleep: '#22d3ee', rem: '#a78bfa', rest: '#475569' };
                    const labels = { deep: 'Deep', sleep: 'Light', rem: 'REM', rest: 'Awake' };
                    this.hypnogram = segments.map(s => ({ pct: s.minutes / minutes * 100, color: colors[s.state], label: labels[s.state] }));

                    // generate windows + collect IBI for whole-night HRV/RHR
                    const wake = new Date(); wake.setHours(7, 0, 0, 0);
                    const bedtime = new Date(wake.getTime() - minutes * 60000);
                    let cursor = new Date(bedtime);
                    const windows = []; let allIbi = []; const rhrMedians = [];

                    const totalMs = (3600 * 8 * 1000) / this.speed; // wall-clock duration of the playback
                    const start = performance.now();

                    for (let si = 0; si < segments.length; si++) {
                        const seg = segments[si];
                        const w = this.genWindow(seg.state, seg.minutes * 60);
                        const sStart = new Date(cursor);
                        const sEnd = new Date(cursor.getTime() + seg.minutes * 60000);
                        windows.push({
                            kind: 'ibi',
                            start: sStart.toISOString(),
                            end: sEnd.toISOString(),
                            ibi_ms: w.ibi, accel_counts: w.accel,
                            confidence: seg.state === 'rest' ? 0.7 : 0.95,
                        });
                        allIbi = allIbi.concat(w.ibi);
                        if (seg.state !== 'rest' && w.ibi.length > 5) rhrMedians.push(this.meanHrOf(w.ibi));

                        // drive the live view to this stage as we "play" the night
                        if (seg.state === 'deep') this.state = 'deep';
                        else if (seg.state === 'rest') this.state = 'rest';
                        else this.state = 'deep';

                        cursor = sEnd;
                        this.nightProgress = (si + 1) / segments.length;
                        // pace the playback
                        const target = start + totalMs * this.nightProgress;
                        const waitMs = Math.max(0, target - performance.now());
                        await new Promise(r => setTimeout(r, Math.min(waitMs, 400)));
                    }

                    const summary = this.summarise(segments);
                    const rmssd = this.computeRmssd(allIbi);
                    const rhr = rhrMedians.length ? Math.min(...rhrMedians) : this.meanHrOf(allIbi);
                    const date = wake.toISOString().slice(0, 10);

                    this.nightMetrics = [
                        { label: 'Duration', value: (summary.duration_min / 60).toFixed(1) + 'h' },
                        { label: 'Deep', value: summary.deep_min + 'm' },
                        { label: 'REM', value: summary.rem_min + 'm' },
                        { label: 'Quality', value: summary.quality },
                        { label: 'RMSSD', value: rmssd + 'ms' },
                        { label: 'Resting HR', value: rhr + ' bpm' },
                        { label: 'Beats', value: allIbi.length.toLocaleString() },
                        { label: 'Windows', value: windows.length },
                    ];
                    this.nightDone = true;

                    // ---- stream to the REAL pipeline (signed) ----
                    const ulid = () => 'sim' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
                    const payloadA = { batch_uid: ulid(), device_id: this.deviceId, timezone: this.timezone, windows };
                    const payloadC = {
                        batch_uid: ulid(), device_id: this.deviceId, timezone: this.timezone,
                        summaries: [
                            { kind: 'sleep', date, duration_min: summary.duration_min, deep_min: summary.deep_min,
                              rem_min: summary.rem_min, light_min: summary.light_min, awake_min: summary.awake_min,
                              bedtime: this.hhmm(bedtime), wake_time: this.hhmm(wake), quality: summary.quality },
                            { kind: 'recovery', date, hrv_ms: rmssd, resting_hr: rhr },
                        ],
                    };

                    const a = await this.ingest(payloadA);
                    const c = await this.ingest(payloadC);
                    this.delivered = a || c;
                    this.status = this.delivered
                        ? '✓ Streamed to Titan — open Recovery / Sleep / Coach to see the worn device.'
                        : '⚠ Ingestion API not reachable yet — night generated locally. Data will flow once the device pipeline is live.';
                    this.running = false;
                    this.setState('rest');
                },

                // HMAC-SHA256 sign + POST (degrades gracefully)
                async ingest(payload) {
                    const body = JSON.stringify(payload);
                    const ts = Math.floor(Date.now() / 1000).toString();
                    try {
                        const sig = await this.hmac(ts + '.' + body, this.secret);
                        const res = await fetch(this.ingestUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Device-Id': this.deviceId,
                                'X-Titan-Signature': `t=${ts},v1=${sig}`,
                            },
                            body,
                        });
                        return res.ok;
                    } catch (e) { return false; }
                },

                async hmac(message, keyHex) {
                    const enc = new TextEncoder();
                    const key = await crypto.subtle.importKey('raw', enc.encode(keyHex),
                        { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
                    const sig = await crypto.subtle.sign('HMAC', key, enc.encode(message));
                    return [...new Uint8Array(sig)].map(b => b.toString(16).padStart(2, '0')).join('');
                },

                // --- night-generation helpers (mirror BiosignalSimulator.php) ---
                buildHypnogram(total) {
                    const segs = []; let rem = total;
                    const ri = (a, b) => a + Math.floor(Math.random() * (b - a + 1));
                    segs.push({ state: 'rest', minutes: ri(4, 10) }); rem -= segs[0].minutes;
                    let cycle = 0;
                    while (rem > 12) {
                        cycle++;
                        let l = Math.min(ri(15, 28), rem); segs.push({ state: 'sleep', minutes: l }); rem -= l; if (rem <= 0) break;
                        let d = Math.max(0, Math.round(ri(18, 35) * Math.max(0.15, 1 - cycle * 0.28)));
                        if (d > 2) { d = Math.min(d, rem); segs.push({ state: 'deep', minutes: d }); rem -= d; } if (rem <= 0) break;
                        let l2 = Math.min(ri(6, 14), rem); segs.push({ state: 'sleep', minutes: l2 }); rem -= l2; if (rem <= 0) break;
                        let r = Math.min(Math.round(ri(8, 18) * Math.min(2, 0.4 + cycle * 0.4)), rem); segs.push({ state: 'rem', minutes: r }); rem -= r;
                        if (rem > 6 && Math.random() < 0.4) { let w = Math.min(ri(1, 4), rem); segs.push({ state: 'rest', minutes: w }); rem -= w; }
                    }
                    if (rem > 0) segs.push({ state: 'sleep', minutes: rem });
                    return segs;
                },

                genWindow(state, seconds) {
                    const s = this.states[state] || this.states.rest;
                    const meanIbi = 60000 / s.hr;
                    const sd = Math.min(s.rmssd / 1.4142, meanIbi * 0.18);
                    const ibi = []; let elapsed = 0, phase = Math.random() * Math.PI * 2, prev = meanIbi;
                    while (elapsed < seconds * 1000) {
                        phase += 2 * Math.PI * 0.25 * (prev / 1000);
                        let beat = meanIbi + Math.sin(phase) * sd * 0.6 + this.gauss() * sd * 0.9;
                        if (Math.random() < (s.motion > 5 ? 0.012 : 0.002)) beat += (Math.random() < 0.5 ? -1 : 1) * meanIbi * 0.35;
                        beat = Math.max(320, Math.min(1900, beat));
                        ibi.push(Math.round(beat)); elapsed += beat; prev = beat;
                    }
                    const epochs = Math.max(1, Math.round(seconds / 30));
                    const accel = [];
                    for (let i = 0; i < epochs; i++) {
                        let base = s.motion + this.gauss() * s.motion * 0.25;
                        if (s.motion < 2 && Math.random() < 0.08) base += Math.random() * 8;
                        accel.push(Math.max(0, Math.round(base)));
                    }
                    return { ibi, accel };
                },

                meanHrOf(ibi) { return Math.round(60000 / (ibi.reduce((a, b) => a + b, 0) / ibi.length)); },

                summarise(segs) {
                    let deep = 0, rem = 0, light = 0, awake = 0;
                    segs.forEach(s => {
                        if (s.state === 'deep') deep += s.minutes;
                        else if (s.state === 'rem') rem += s.minutes;
                        else if (s.state === 'sleep') light += s.minutes;
                        else awake += s.minutes;
                    });
                    const asleep = deep + rem + light, duration = asleep + awake;
                    const eff = duration ? asleep / duration : 0, rest = asleep ? (deep + rem) / asleep : 0;
                    const quality = Math.round(Math.min(100, Math.max(0, (eff * 0.6 + rest * 0.9) * 100)));
                    return { duration_min: duration, deep_min: deep, rem_min: rem, light_min: light, awake_min: awake, quality };
                },

                hhmm(d) { return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); },
            };
        }
    </script>
</x-titan-layout>
