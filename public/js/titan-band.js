        import * as THREE from 'three';
        import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
        import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
        import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
        import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
        import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
        import { OutputPass } from 'three/addons/postprocessing/OutputPass.js';

        // Expose a factory the Alpine component calls; it owns the 3D scene + sim loop.
        window.__titanBand = function (canvas, getState, getStates, getMetrics) {
            const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1.12;
            renderer.setClearColor(0x000000, 0);   // transparent canvas — the band floats on the page

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
            scene.add(new THREE.AmbientLight(0x4a5478, 0.95));
            const key = new THREE.DirectionalLight(0xffffff, 2.9);
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

            // rounded-rectangle profile (used as the strap cross-section)
            function roundRectShape(w, h, r) {
                const s = new THREE.Shape();
                s.moveTo(-w / 2 + r, -h / 2);
                s.lineTo(w / 2 - r, -h / 2); s.quadraticCurveTo(w / 2, -h / 2, w / 2, -h / 2 + r);
                s.lineTo(w / 2, h / 2 - r); s.quadraticCurveTo(w / 2, h / 2, w / 2 - r, h / 2);
                s.lineTo(-w / 2 + r, h / 2); s.quadraticCurveTo(-w / 2, h / 2, -w / 2, h / 2 - r);
                s.lineTo(-w / 2, -h / 2 + r); s.quadraticCurveTo(-w / 2, -h / 2, -w / 2 + r, -h / 2);
                return s;
            }

            // ---- Matte silicone straps: SOLID (extruded along a path, capped ends — no
            //      hollow tubes). Two straps emerge from the case top & bottom and curve
            //      back, like the band wrapping an (invisible) wrist. ----
            const siliconeMat = new THREE.MeshStandardMaterial({
                color: 0x15181f, roughness: 0.86, metalness: 0.04, envMapIntensity: 0.3,
            });
            function buildStrap(dir) {
                const curve = new THREE.CatmullRomCurve3([
                    new THREE.Vector3(0, 1.0 * dir, 0.06),
                    new THREE.Vector3(0, 1.65 * dir, -0.12),
                    new THREE.Vector3(0, 2.1 * dir, -0.9),
                    new THREE.Vector3(0, 1.95 * dir, -1.85),
                    new THREE.Vector3(0, 1.3 * dir, -2.45),
                ]);
                const profile = roundRectShape(0.94, 0.22, 0.1);
                const geo = new THREE.ExtrudeGeometry(profile, { extrudePath: curve, steps: 96, bevelEnabled: false });
                return new THREE.Mesh(geo, siliconeMat);
            }
            band.add(buildStrap(1), buildStrap(-1));

            // ---- Watch case: tall rounded-rectangle, dark titanium ----
            const bodyMat = new THREE.MeshPhysicalMaterial({
                color: 0x2c313c, roughness: 0.38, metalness: 0.85,
                clearcoat: 0.5, clearcoatRoughness: 0.3, envMapIntensity: 1.2,
            });
            const body = new THREE.Mesh(new RoundedBox(1.85, 2.25, 0.58, 0.5, 10), bodyMat);
            band.add(body);

            // polished bezel ring for a jewellery edge around the screen
            const bezelMat = new THREE.MeshPhysicalMaterial({ color: 0x3b465f, roughness: 0.16, metalness: 1.0, envMapIntensity: 1.6 });
            const bezel = new THREE.Mesh(new RoundedBox(1.74, 2.12, 0.1, 0.46, 10), bezelMat);
            bezel.position.z = 0.25;
            band.add(bezel);

            // Glossy black screen glass
            const glass = new THREE.Mesh(
                new RoundedBox(1.62, 2.0, 0.08, 0.42, 8),
                new THREE.MeshPhysicalMaterial({
                    color: 0x05070c, roughness: 0.05, metalness: 0.0,
                    clearcoat: 1, clearcoatRoughness: 0.04, envMapIntensity: 1.4, reflectivity: 0.85,
                })
            );
            glass.position.z = 0.33;
            band.add(glass);

            // side crown/button
            const crown = new THREE.Mesh(
                new THREE.CylinderGeometry(0.085, 0.085, 0.16, 24),
                new THREE.MeshPhysicalMaterial({ color: 0x3b465f, roughness: 0.2, metalness: 1.0, envMapIntensity: 1.4 })
            );
            crown.rotation.z = Math.PI / 2;
            crown.position.set(0.95, 0.35, 0.02);
            band.add(crown);

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

            // ---- Always-on watch face (live time + heart rate) on the screen ----
            const faceCanvas = document.createElement('canvas'); faceCanvas.width = 384; faceCanvas.height = 472;
            const fctx = faceCanvas.getContext('2d');
            const faceTex = new THREE.CanvasTexture(faceCanvas);
            faceTex.colorSpace = THREE.SRGBColorSpace; faceTex.anisotropy = 4;
            const face = new THREE.Mesh(
                new THREE.PlaneGeometry(1.47, 1.82),
                new THREE.MeshBasicMaterial({ map: faceTex, transparent: true, depthWrite: false, toneMapped: false })
            );
            face.position.z = 0.345;
            band.add(face);
            function drawFace() {
                const W = faceCanvas.width, H = faceCanvas.height, cx = W / 2;
                fctx.clearRect(0, 0, W, H);
                const m = getMetrics ? getMetrics() : {};
                const st = getState(); const calm = st === 'deep' || st === 'rest';
                const accent = calm ? '#27e587' : '#ffb020';
                fctx.textAlign = 'center';
                const d = new Date();
                fctx.fillStyle = 'rgba(214,224,255,0.82)';
                fctx.font = '600 40px Archivo, system-ui, sans-serif';
                fctx.fillText(String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'), cx, 72);
                fctx.fillStyle = accent;
                fctx.font = '800 152px Archivo, system-ui, sans-serif';
                fctx.fillText(String(Math.round(m.bpm || 0)), cx, 272);
                fctx.fillStyle = 'rgba(170,184,214,0.8)';
                fctx.font = '600 30px Manrope, system-ui, sans-serif';
                fctx.fillText('BPM', cx, 316);
                fctx.fillStyle = 'rgba(150,165,200,0.7)';
                fctx.font = '500 27px Manrope, system-ui, sans-serif';
                fctx.fillText('HRV ' + Math.round(m.rmssd || 0) + ' ms', cx, 378);
                fctx.fillStyle = 'rgba(126,140,178,0.55)';
                fctx.font = '700 24px Archivo, system-ui, sans-serif';
                fctx.fillText('TITAN', cx, 432);
                fctx.strokeStyle = accent; fctx.globalAlpha = 0.42; fctx.lineWidth = 6; fctx.lineCap = 'round';
                fctx.beginPath(); fctx.arc(cx, 252, 166, -Math.PI * 0.78, -Math.PI * 0.22); fctx.stroke();
                fctx.globalAlpha = 1;
                faceTex.needsUpdate = true;
            }
            drawFace();

            band.rotation.x = -0.1;
            band.rotation.y = 0.42;

            // ---- Studio backdrop gradient: gives the product depth and lets bloom
            //      composite cleanly (no transparent-background artefacts) ----
            const bgC = document.createElement('canvas'); bgC.width = 4; bgC.height = 256;
            const bx = bgC.getContext('2d');
            const bgGrad = bx.createLinearGradient(0, 0, 0, 256);
            bgGrad.addColorStop(0, '#121723'); bgGrad.addColorStop(0.5, '#0a0d15'); bgGrad.addColorStop(1, '#05070b');
            bx.fillStyle = bgGrad; bx.fillRect(0, 0, 4, 256);
            const bgTex = new THREE.CanvasTexture(bgC); bgTex.colorSpace = THREE.SRGBColorSpace;
            // scene.background intentionally NOT set — the canvas stays transparent so the band
            // floats over the page's own gradient instead of sitting in a black box.

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
            // contact shadow omitted on the transparent hero — a dark plane would smudge the page gradient.

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

            // Bloom is intentionally disabled here: the UnrealBloom chain composites over an
            // opaque target and reintroduces a dark box, which breaks the floating effect. We
            // render directly (alpha-preserving) and lean on the page's CSS glow + the indigo/
            // cyan rim lights + emissive LEDs for the premium look.
            let composer = null;

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
            let faceT = 0;          // watch-face redraw throttle
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
                // The optical LEDs live on the BACK of the case (against the wrist), so they
                // must NOT light up the front. Keep them a dim, subtle indicator — no
                // scene-flooding strobe — only noticeable if you rotate to the underside.
                const baseColor = calm ? new THREE.Color(0x18ff7a) : new THREE.Color(0xffb020);
                ledMat.emissive.copy(baseColor);
                ledMat.emissiveIntensity = 0.3 + pulse * 0.7;
                ledMat.color.copy(baseColor.clone().multiplyScalar(0.35));
                // keep the live watch face fresh (a few times a second)
                faceT += dt;
                if (faceT > 0.25) { faceT = 0; drawFace(); }

                controls.update();
                if (composer) composer.render(); else renderer.render(scene, camera);
            }
            loop();
            return api;
        };
