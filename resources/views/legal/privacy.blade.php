@php
    // ── Edit these two lines for your deployment ──────────────────────────────
    $contact = 'privacy@titan.fullstacklabs.org';   // a reachable contact address
    $updated = 'July 7, 2026';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy — Titan</title>
    <meta name="robots" content="index,follow">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=archivo:600,700,800|manrope:400,500,600,700" rel="stylesheet">
    <meta name="theme-color" content="#06070A">
    <style>
        :root {
            --ink: #06070A; --surface: #10141E; --line: rgba(255,255,255,0.09);
            --text: #F4F7FB; --muted: #A0AABC; --accent: #6D6BF6; --accent-2: #22D3EE;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            background: var(--ink); color: var(--text);
            font-family: 'Manrope', system-ui, -apple-system, sans-serif;
            line-height: 1.7; font-size: 16px;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 760px; margin: 0 auto; padding: 56px 24px 96px; }
        .brand {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: 'Archivo', sans-serif; font-weight: 800; letter-spacing: -0.02em;
            font-size: 20px; text-decoration: none;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        h1 { font-family: 'Archivo', sans-serif; font-weight: 800; font-size: 34px; letter-spacing: -0.02em; margin: 28px 0 6px; }
        h2 { font-family: 'Archivo', sans-serif; font-weight: 700; font-size: 20px; margin: 40px 0 10px; }
        p, li { color: var(--text); }
        .muted { color: var(--muted); }
        a { color: var(--accent-2); }
        ul { padding-left: 22px; }
        li { margin: 6px 0; }
        hr { border: 0; border-top: 1px solid var(--line); margin: 28px 0; }
        .updated { color: var(--muted); font-size: 14px; }
        .note { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; margin: 22px 0; color: var(--muted); font-size: 14.5px; }
        strong { color: #fff; }
        footer { color: var(--muted); font-size: 13.5px; margin-top: 48px; }
    </style>
</head>
<body>
<div class="wrap">
    <a href="/" class="brand">⚡ TITAN</a>

    <h1>Privacy Policy</h1>
    <p class="updated">Last updated: {{ $updated }}</p>

    <p>Titan is an AI-powered health, fitness and longevity app. This policy explains what we collect,
        how we use it, and the choices you have. It covers the Titan mobile app and the service it connects
        to. Titan is open-source and can be self-hosted; this policy applies to the instance operated at
        <strong>titan.fullstacklabs.org</strong>.</p>

    <div class="note">
        <strong>The short version:</strong> Your health data is used to power <em>your</em> coaching and
        insights — nothing more. We don't sell it, we don't use it for advertising, and we don't track you
        across other apps or websites. You can delete your account and data at any time.
    </div>

    <h2>1. Information we collect</h2>
    <ul>
        <li><strong>Account details</strong> — your name, email address, and a securely hashed password.</li>
        <li><strong>Health &amp; fitness data you log or sync</strong> — sleep, recovery/HRV/resting heart
            rate, workouts and strain, steps and activity, body measurements, nutrition and hydration,
            supplements, blood-test markers you enter, and menstrual-cycle data if you choose to track it.</li>
        <li><strong>Photos</strong> — meal photos (for automatic calorie/macro estimates) and progress/physique
            photos, if you add them.</li>
        <li><strong>Wearable biosignals</strong> — if you connect the optional Titan band, its raw
            optical-sensor and motion data is sent to the service to compute heart rate, HRV, sleep and activity.</li>
        <li><strong>Location</strong> — only while you are actively recording a run, to map your route and
            measure distance and pace. It is not collected in the background otherwise.</li>
        <li><strong>Apple Health data</strong> — if you grant access, we <em>read</em> steps, sleep, heart
            rate and workouts to compute your recovery and trends. The app does not write to Apple Health.</li>
        <li><strong>Basic usage &amp; diagnostics</strong> — data needed to operate the service and fix crashes.</li>
    </ul>

    <h2>2. How we use your data</h2>
    <p>We use your data solely to provide the app's features to you: computing recovery, sleep, strain and
        longevity insights; tracking nutrition and training; and powering your AI coach. We do <strong>not</strong>
        sell your data, and we do <strong>not</strong> use it for advertising or cross-app tracking.</p>

    <h2>3. AI processing &amp; service providers</h2>
    <p>To deliver certain features we share the minimum necessary data with trusted providers:</p>
    <ul>
        <li><strong>AI coaching &amp; photo analysis (OpenAI).</strong> When you chat with the coach or snap a
            meal photo for macros, the relevant messages, photo, and context are sent to OpenAI's API to
            generate a response. This data is processed to serve your request and is not used to train models.</li>
        <li><strong>Maps (Mapbox).</strong> Run routes are rendered using Mapbox.</li>
        <li><strong>Hosting &amp; storage.</strong> Your data is stored on the operator's own servers and object
            storage that run the Titan service.</li>
    </ul>

    <h2>4. Health data</h2>
    <p>We treat your health and fitness information as sensitive. It is used only to provide the service to
        you, is never sold, and is never used for advertising. Data obtained via Apple Health is used solely
        to power your in-app coaching and is not shared with third parties except as needed to provide the
        features you use (see Section 3).</p>

    <h2>5. Security</h2>
    <p>Data is encrypted in transit using HTTPS/TLS. Wearable uploads are signed with a per-device secret.
        Passwords are stored only as salted hashes. No method of transmission or storage is 100% secure, but
        we take reasonable measures to protect your information.</p>

    <h2>6. Data retention &amp; deletion</h2>
    <p>We keep your data for as long as your account is active. You can wipe your Titan data or delete your
        account at any time from within the app; on deletion, your personal data is removed from the service.</p>

    <h2>7. Your rights</h2>
    <p>You can access, correct, export or delete your personal data. To make a request, contact us at the
        address below.</p>

    <h2>8. Children</h2>
    <p>Titan is not directed to children under 16, and we do not knowingly collect data from them.</p>

    <h2>9. Not medical advice</h2>
    <p>Titan is a wellness product. Its scores, estimates and coaching — including recovery, sleep, cycle and
        conception-likelihood features — are for awareness and are not medical advice, diagnosis, or a
        contraceptive method. Consult a qualified healthcare provider for medical decisions.</p>

    <h2>10. Changes to this policy</h2>
    <p>We may update this policy from time to time. Material changes will be reflected by the "Last updated"
        date above.</p>

    <h2>11. Contact</h2>
    <p>Questions about this policy or your data? Email <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>

    <hr>
    <footer>© {{ date('Y') }} Titan. Open-source health you own.</footer>
</div>
</body>
</html>
