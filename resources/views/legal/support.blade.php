@php
    // ── Edit these for your deployment ────────────────────────────────────────
    $contact = 'support@titan.fullstacklabs.org';   // a reachable support address
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Support — Titan</title>
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
            line-height: 1.7; font-size: 16px; -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 680px; margin: 0 auto; padding: 56px 24px 96px; }
        .brand {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: 'Archivo', sans-serif; font-weight: 800; letter-spacing: -0.02em;
            font-size: 20px; text-decoration: none;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        h1 { font-family: 'Archivo', sans-serif; font-weight: 800; font-size: 34px; letter-spacing: -0.02em; margin: 28px 0 6px; }
        h2 { font-family: 'Archivo', sans-serif; font-weight: 700; font-size: 19px; margin: 36px 0 8px; }
        p, li { color: var(--text); }
        a { color: var(--accent-2); }
        ul { padding-left: 22px; } li { margin: 7px 0; }
        strong { color: #fff; }
        .card {
            display: block; background: var(--surface); border: 1px solid var(--line);
            border-radius: 16px; padding: 20px 22px; margin: 22px 0; text-decoration: none;
        }
        .card .k { color: var(--muted); font-size: 13px; text-transform: uppercase; letter-spacing: .12em; }
        .card .v { display: block; margin-top: 4px; font-size: 18px; font-weight: 700; color: var(--accent-2); }
        footer { color: var(--muted); font-size: 13.5px; margin-top: 48px; }
        hr { border: 0; border-top: 1px solid var(--line); margin: 28px 0; }
    </style>
</head>
<body>
<div class="wrap">
    <a href="/" class="brand">⚡ TITAN</a>
    <h1>Support</h1>
    <p>Need a hand with Titan? We're happy to help.</p>

    <a class="card" href="mailto:{{ $contact }}">
        <span class="k">Email us</span>
        <span class="v">{{ $contact }}</span>
    </a>

    <h2>Common questions</h2>
    <ul>
        <li><strong>Do I need a wearable?</strong> No. Titan works from Apple Health or quick manual logging — sleep, meals, water, workouts and more.</li>
        <li><strong>How do I connect Apple Health?</strong> Grant permission when the app asks, or in iOS Settings → Privacy &amp; Security → Health → Titan.</li>
        <li><strong>How is my data handled?</strong> It powers only your own coaching and insights — never sold, never used for ads. See our <a href="/privacy">Privacy Policy</a>.</li>
        <li><strong>How do I delete my data or account?</strong> From the app under <em>You → Account</em>, or email us and we'll remove it.</li>
        <li><strong>Something's broken or confusing?</strong> Email us with a screenshot — it helps us fix it fast.</li>
    </ul>

    <hr>
    <footer>© {{ date('Y') }} Titan · <a href="/privacy">Privacy Policy</a></footer>
</div>
</body>
</html>
