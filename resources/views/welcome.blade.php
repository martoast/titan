<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Titan — Understand your body. Own your data.</title>
    <meta name="description" content="Titan is the open-source, subscription-free health platform: a wearable you own, recovery / sleep / strain you can act on, and an AI coach that knows your body. No monthly fees. Ever.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    <meta property="og:title" content="Titan — Understand your body. Own your data.">
    <meta property="og:description" content="The open, subscription-free health OS. A wearable you own, an AI coach that knows you, and your data that stays yours. Forever.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ asset('images/hero-bg.png') }}">
    <meta name="theme-color" content="#06070A">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=archivo:500,600,700,800,900|manrope:400,500,600,700" rel="stylesheet">

    <script type="importmap">
    {
      "imports": {
        "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
        "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
      }
    }
    </script>

    @verbatim
    <style>
        :root {
            --ink: #06070A; --ink-2: #090C13; --surface: #10141E; --surface-2: #141926;
            --line: rgba(255,255,255,0.08); --line-2: rgba(255,255,255,0.13);
            --text: #F4F7FB; --muted: #99A3B5; --faint: #6A7385;
            --indigo: #6366F1; --cyan: #22D3EE; --green: #34D399; --amber: #FBBF24; --rose: #FB7185;
            --grad: linear-gradient(100deg, #818CF8 0%, #22D3EE 100%);
            --maxw: 1180px;
            --font-d: 'Archivo', system-ui, sans-serif;
            --font-b: 'Manrope', system-ui, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
        body {
            background: var(--ink); color: var(--text); font-family: var(--font-b);
            font-size: 17px; line-height: 1.6; -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }
        img, canvas { display: block; max-width: 100%; }
        ::selection { background: rgba(99,102,241,0.35); }

        .wrap { width: 100%; max-width: var(--maxw); margin: 0 auto; padding: 0 24px; }
        .grad-text { background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .eyebrow { font-family: var(--font-d); font-weight: 700; font-size: 0.74rem; letter-spacing: 0.22em; text-transform: uppercase; color: var(--cyan); }
        h1, h2, h3 { font-family: var(--font-d); font-weight: 800; letter-spacing: -0.02em; line-height: 1.04; color: #fff; }
        .lead { color: var(--muted); font-size: clamp(1.05rem, 1.6vw, 1.25rem); line-height: 1.55; }

        /* ---- buttons ---- */
        .btn { display: inline-flex; align-items: center; gap: 0.5rem; font-family: var(--font-d); font-weight: 700; font-size: 0.96rem; padding: 0.85rem 1.5rem; border-radius: 999px; cursor: pointer; transition: transform .15s ease, box-shadow .2s ease, background .2s ease; border: 1px solid transparent; white-space: nowrap; }
        .btn-primary { background: var(--grad); color: #08111c; box-shadow: 0 8px 30px -8px rgba(34,211,238,0.5); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 14px 40px -8px rgba(34,211,238,0.6); }
        .btn-ghost { background: rgba(255,255,255,0.04); border-color: var(--line-2); color: var(--text); }
        .btn-ghost:hover { background: rgba(255,255,255,0.09); transform: translateY(-2px); }

        /* ---- nav ---- */
        .nav { position: fixed; top: 0; left: 0; right: 0; z-index: 50; transition: background .3s ease, border-color .3s ease, backdrop-filter .3s ease; border-bottom: 1px solid transparent; }
        .nav.scrolled { background: rgba(6,7,10,0.72); backdrop-filter: blur(16px); border-bottom-color: var(--line); }
        .nav-inner { display: flex; align-items: center; justify-content: space-between; height: 68px; }
        .brand { display: flex; align-items: center; gap: 0.6rem; font-family: var(--font-d); font-weight: 800; font-size: 1.35rem; letter-spacing: -0.01em; }
        .brand .mark { width: 26px; height: 26px; }
        .nav-links { display: flex; align-items: center; gap: 2rem; }
        .nav-links a.lnk { color: var(--muted); font-size: 0.92rem; font-weight: 600; transition: color .15s; }
        .nav-links a.lnk:hover { color: var(--text); }
        .nav-cta { display: flex; align-items: center; gap: 0.9rem; }

        /* ---- hero ---- */
        .hero { position: relative; padding-top: 68px; min-height: 100vh; display: flex; align-items: center; overflow: hidden; }
        .hero-bg { position: absolute; inset: 0; z-index: 0; background-image: url('/images/hero-bg.png'); background-size: cover; background-position: center; opacity: 0.62; }
        .hero-bg::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(6,7,10,0.55) 0%, rgba(6,7,10,0.2) 35%, rgba(6,7,10,0.85) 85%, var(--ink) 100%); }
        .hero-grid { position: relative; z-index: 2; display: grid; grid-template-columns: 1.05fr 0.95fr; gap: 2rem; align-items: center; width: 100%; padding: 4rem 0; }
        .hero h1 { font-size: clamp(2.2rem, 4.4vw, 3.4rem); font-weight: 900; white-space: nowrap; letter-spacing: -0.025em; }
        .hero .lead { margin-top: 1.4rem; max-width: 33ch; }
        .hero-cta { margin-top: 2.2rem; display: flex; gap: 0.9rem; flex-wrap: wrap; }
        .pillrow { margin-top: 2.4rem; display: flex; gap: 1.4rem; flex-wrap: wrap; align-items: center; }
        .pill { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.82rem; font-weight: 600; color: var(--muted); }
        .pill .dot { width: 7px; height: 7px; border-radius: 999px; }

        .band-stage { position: relative; height: clamp(360px, 52vw, 600px); }
        .band-poster { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; z-index: 1; }
        .band-stage canvas, .hw-stage canvas { position: absolute; inset: 0; width: 100% !important; height: 100% !important; z-index: 2; }
        .band-glow { position: absolute; inset: 2% 4%; z-index: 0; background: radial-gradient(circle at 50% 44%, rgba(99,102,241,0.34), transparent 58%), radial-gradient(circle at 56% 64%, rgba(34,211,238,0.2), transparent 60%); filter: blur(42px); }
        .band-cap { position: absolute; bottom: 6px; left: 50%; transform: translateX(-50%); font-size: 0.72rem; color: var(--faint); letter-spacing: 0.04em; }

        /* ---- section scaffolding ---- */
        section { position: relative; }
        .sec { padding: clamp(5rem, 10vw, 8rem) 0; }
        .sec-head { max-width: 720px; }
        .sec-head.center { margin: 0 auto; text-align: center; }
        .sec-head h2 { font-size: clamp(2rem, 4.4vw, 3.3rem); }
        .sec-head .lead { margin-top: 1.1rem; }
        .kicker { display: inline-block; margin-bottom: 1rem; }

        /* ---- the thesis / comparison ---- */
        .thesis { background: var(--ink-2); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .cmp { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-top: 3rem; }
        .cmp-card { border-radius: 20px; padding: 2rem; border: 1px solid var(--line); position: relative; overflow: hidden; }
        .cmp-them { background: var(--surface); }
        .cmp-us { background: linear-gradient(180deg, rgba(34,211,238,0.07), rgba(99,102,241,0.05)); border-color: rgba(34,211,238,0.32); }
        .cmp-card h3 { font-size: 1.05rem; letter-spacing: 0.02em; text-transform: uppercase; font-weight: 700; }
        .cmp-them h3 { color: var(--faint); }
        .cmp-price { font-family: var(--font-d); font-weight: 900; font-size: 2.8rem; margin: 0.5rem 0 0.2rem; line-height: 1; }
        .cmp-them .cmp-price { color: #5e6677; }
        .cmp-sub { font-size: 0.9rem; color: var(--faint); margin-bottom: 1.4rem; }
        .cmp-list { list-style: none; display: flex; flex-direction: column; gap: 0.8rem; }
        .cmp-list li { display: flex; gap: 0.7rem; font-size: 0.95rem; color: var(--muted); align-items: flex-start; }
        .cmp-list li svg { flex-shrink: 0; margin-top: 3px; }
        .cmp-us .cmp-list li { color: #cdd6e6; }

        /* ---- score cards (data viz) ---- */
        .scores { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; margin-top: 3rem; }
        .score { background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 1.7rem; text-align: center; transition: transform .25s ease, border-color .25s ease; }
        .score:hover { transform: translateY(-4px); border-color: var(--line-2); }
        .ring { width: 116px; height: 116px; margin: 0 auto 1.1rem; border-radius: 50%; display: grid; place-items: center; background: conic-gradient(var(--c) calc(var(--p) * 1%), rgba(255,255,255,0.06) 0); position: relative; }
        .ring::before { content: ''; position: absolute; inset: 9px; border-radius: 50%; background: var(--surface); }
        .ring-val { position: relative; font-family: var(--font-d); font-weight: 800; font-size: 1.85rem; line-height: 1; color: #fff; }
        .ring-val small { display: block; font-size: 0.62rem; font-weight: 600; letter-spacing: 0.12em; color: var(--faint); margin-top: 4px; text-transform: uppercase; font-family: var(--font-b); }
        .score-label { font-family: var(--font-d); font-weight: 700; font-size: 1.05rem; }
        .score-sub { font-size: 0.86rem; color: var(--muted); margin-top: 0.35rem; }

        /* ---- coach ---- */
        .coach-grid { display: grid; grid-template-columns: 0.95fr 1.05fr; gap: 3.5rem; align-items: center; }
        .feat-list { margin-top: 2rem; display: flex; flex-direction: column; gap: 1.3rem; }
        .feat { display: flex; gap: 1rem; }
        .feat-ic { flex-shrink: 0; width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: rgba(99,102,241,0.14); border: 1px solid rgba(99,102,241,0.25); }
        .feat h4 { font-family: var(--font-d); font-weight: 700; font-size: 1.05rem; color: #fff; }
        .feat p { font-size: 0.92rem; color: var(--muted); margin-top: 0.2rem; }

        .chat { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 1.3rem; box-shadow: 0 40px 80px -40px rgba(0,0,0,0.8); }
        .chat-top { display: flex; align-items: center; gap: 0.6rem; padding: 0.3rem 0.4rem 1rem; border-bottom: 1px solid var(--line); }
        .chat-av { width: 30px; height: 30px; border-radius: 50%; background: var(--grad); display: grid; place-items: center; font-family: var(--font-d); font-weight: 800; font-size: 0.8rem; color: #08111c; }
        .chat-top b { font-family: var(--font-d); font-size: 0.92rem; }
        .chat-top span { font-size: 0.72rem; color: var(--green); margin-left: auto; display: inline-flex; align-items: center; gap: 0.3rem; }
        .bub { margin-top: 1rem; padding: 0.8rem 1rem; border-radius: 16px; font-size: 0.92rem; line-height: 1.5; max-width: 86%; }
        .bub.me { margin-left: auto; background: rgba(99,102,241,0.16); border: 1px solid rgba(99,102,241,0.25); border-bottom-right-radius: 5px; }
        .bub.ai { background: var(--surface-2); border: 1px solid var(--line); border-bottom-left-radius: 5px; color: #d7deea; }
        .mini-card { margin-top: 0.9rem; background: var(--ink-2); border: 1px solid var(--line); border-radius: 14px; padding: 0.9rem 1rem; display: flex; align-items: center; gap: 0.9rem; }
        .mini-ring { width: 52px; height: 52px; border-radius: 50%; flex-shrink: 0; background: conic-gradient(var(--green) 87%, rgba(255,255,255,0.06) 0); display: grid; place-items: center; position: relative; }
        .mini-ring::before { content: ''; position: absolute; inset: 5px; border-radius: 50%; background: var(--ink-2); }
        .mini-ring b { position: relative; font-family: var(--font-d); font-weight: 800; font-size: 1rem; }
        .mini-card .mc-t { font-family: var(--font-d); font-weight: 700; font-size: 0.9rem; color: #fff; }
        .mini-card .mc-s { font-size: 0.8rem; color: var(--muted); }

        /* ---- hardware ---- */
        .hw { background: var(--ink-2); border-top: 1px solid var(--line); }
        .hw-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; align-items: center; }
        .hw-stage { position: relative; height: clamp(340px, 44vw, 500px); }
        .hw-stage canvas { width: 100% !important; height: 100% !important; }
        .specs { display: flex; flex-direction: column; gap: 1.1rem; margin-top: 2rem; }
        .spec { display: flex; gap: 1rem; padding: 1.1rem 1.2rem; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; }
        .spec .sd { width: 9px; height: 9px; border-radius: 999px; margin-top: 7px; flex-shrink: 0; }
        .spec b { font-family: var(--font-d); color: #fff; font-size: 0.98rem; }
        .spec p { font-size: 0.88rem; color: var(--muted); margin-top: 0.15rem; }

        /* ---- everyone ---- */
        .every { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; margin-top: 3rem; }
        .ev { padding: 1.7rem; border-radius: 18px; background: var(--surface); border: 1px solid var(--line); transition: transform .2s ease, border-color .2s; }
        .ev:hover { transform: translateY(-4px); border-color: var(--line-2); }
        .ev .evi { font-size: 1.6rem; }
        .ev h4 { font-family: var(--font-d); font-weight: 700; font-size: 1.1rem; color: #fff; margin-top: 0.7rem; }
        .ev p { font-size: 0.9rem; color: var(--muted); margin-top: 0.35rem; }

        /* ---- self host ---- */
        .host { background: var(--ink-2); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .host-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; align-items: center; }
        .terminal { background: #05070b; border: 1px solid var(--line-2); border-radius: 16px; overflow: hidden; box-shadow: 0 40px 80px -40px rgba(0,0,0,0.9); }
        .term-bar { display: flex; gap: 0.45rem; padding: 0.8rem 1rem; border-bottom: 1px solid var(--line); }
        .term-bar i { width: 11px; height: 11px; border-radius: 50%; display: block; }
        .term-body { padding: 1.3rem 1.4rem; font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 0.85rem; line-height: 1.9; }
        .term-body .c { color: var(--faint); }
        .term-body .p { color: var(--cyan); }
        .term-body .g { color: var(--green); }
        .term-body .w { color: #dde4f0; }

        /* ---- final cta ---- */
        .final { text-align: center; padding: clamp(6rem, 12vw, 9rem) 0; position: relative; overflow: hidden; }
        .final::before { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 60% 50% at 50% 40%, rgba(99,102,241,0.18), transparent 70%); }
        .final h2 { font-size: clamp(2.2rem, 5.6vw, 4rem); font-weight: 900; position: relative; }
        .final .lead { margin: 1.2rem auto 0; max-width: 40ch; position: relative; }
        .final .hero-cta { justify-content: center; margin-top: 2.4rem; position: relative; }

        /* ---- footer ---- */
        footer { border-top: 1px solid var(--line); padding: 3rem 0; }
        .foot { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap; }
        .foot .fl { font-size: 0.86rem; color: var(--faint); }
        .foot-links { display: flex; gap: 1.5rem; }
        .foot-links a { font-size: 0.88rem; color: var(--muted); }
        .foot-links a:hover { color: var(--text); }

        /* ---- reveal ---- */
        .rv { opacity: 0; transform: translateY(26px); transition: opacity .7s cubic-bezier(.2,.7,.2,1), transform .7s cubic-bezier(.2,.7,.2,1); }
        .rv.in { opacity: 1; transform: none; }
        /* No-JS / observer-less fallback: never leave content hidden. */
        html:not(.js) .rv { opacity: 1; transform: none; }

        .menu-btn { display: none; }

        /* ---- responsive ---- */
        @media (max-width: 900px) {
            .nav-links { display: none; }
            .hero-grid { grid-template-columns: 1fr; gap: 1rem; text-align: center; }
            .hero .lead { margin-left: auto; margin-right: auto; }
            .hero-cta, .pillrow { justify-content: center; }
            .band-stage { order: -1; height: clamp(300px, 70vw, 420px); }
            .cmp, .coach-grid, .hw-grid, .host-grid { grid-template-columns: 1fr; }
            .scores { grid-template-columns: repeat(2, 1fr); }
            .every { grid-template-columns: 1fr; }
            .coach-grid .chat { order: -1; }
            .hw-stage { order: -1; }
        }
        @media (max-width: 640px) {
            .hero h1 { white-space: normal; }
        }
        @media (max-width: 540px) {
            body { font-size: 16px; }
            .scores { grid-template-columns: 1fr 1fr; gap: 0.8rem; }
            .score { padding: 1.2rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { scroll-behavior: auto; }
            .rv { transition: none; opacity: 1; transform: none; }
        }
    </style>
    @endverbatim
</head>
<body>
    <script>document.documentElement.classList.add('js');</script>

    <!-- NAV -->
    <nav class="nav" id="nav">
        <div class="wrap nav-inner">
            <a href="#top" class="brand">
                <svg class="mark" viewBox="0 0 32 32" fill="none">
                    <defs><linearGradient id="g1" x1="0" y1="0" x2="32" y2="32">
                        <stop stop-color="#818CF8"/><stop offset="1" stop-color="#22D3EE"/>
                    </linearGradient></defs>
                    <circle cx="16" cy="16" r="13" stroke="url(#g1)" stroke-width="2.4"/>
                    <path d="M6 16.5h4.5l2-4 3.2 8.5 2.6-6 1.6 3h5.5" stroke="url(#g1)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="grad-text">TITAN</span>
            </a>
            <div class="nav-links">
                <a class="lnk" href="#data">What it sees</a>
                <a class="lnk" href="#coach">The coach</a>
                <a class="lnk" href="#band">The band</a>
                <a class="lnk" href="#open">Open source</a>
            </div>
            <div class="nav-cta">
                <a class="lnk" href="{{ route('login') }}" style="font-weight:600;">Sign in</a>
                <a class="btn btn-primary" href="{{ route('register') }}">Get started</a>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <header class="hero" id="top">
        <div class="hero-bg"></div>
        <div class="wrap hero-grid">
            <div>
                <div class="eyebrow rv">Open-source · Subscription-free</div>
                <h1 class="rv" style="margin-top:1.1rem;">Your body,<br><span class="grad-text">fully understood.</span></h1>
                <p class="lead rv">Titan turns a wearable you own into recovery, sleep, and strain you can actually act on — guided by an AI coach that knows your body. No subscription. No data tax. Yours, forever.</p>
                <div class="hero-cta rv">
                    <a class="btn btn-primary" href="{{ route('register') }}">Get started — it's free</a>
                    <a class="btn btn-ghost" href="#data">See how it works</a>
                </div>
                <div class="pillrow rv">
                    <span class="pill"><span class="dot" style="background:var(--green)"></span> Open source</span>
                    <span class="pill"><span class="dot" style="background:var(--cyan)"></span> Self-hostable</span>
                    <span class="pill"><span class="dot" style="background:var(--indigo)"></span> $0 / month, forever</span>
                </div>
            </div>
            <div class="band-stage rv">
                <div class="band-glow"></div>
                <img class="band-poster" src="{{ asset('images/band.png') }}" alt="The Titan band — an open, research-grade recovery wearable" fetchpriority="high">
                <canvas id="heroBand"></canvas>
                <div class="band-cap">drag to rotate</div>
            </div>
        </div>
    </header>

    <!-- THESIS -->
    <section class="thesis">
        <div class="wrap sec">
            <div class="sec-head center rv">
                <span class="eyebrow kicker">The end of the data tax</span>
                <h2>Everyone else rents you<br><span class="grad-text">your own body.</span></h2>
                <p class="lead">The big wearables lock your health behind a monthly fee — and your data behind their servers. Stop paying, and the screen goes dark. We think understanding your body is a right, not a subscription.</p>
            </div>
            <div class="cmp">
                <div class="cmp-card cmp-them rv">
                    <h3>The subscription wearable</h3>
                    <div class="cmp-price" style="color:#5e6677">$239<span style="font-size:1.1rem;font-weight:600;color:var(--faint)">/yr</span></div>
                    <div class="cmp-sub">…on top of the hardware. Forever.</div>
                    <ul class="cmp-list">
                        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="#fb7185" stroke-width="2.2" stroke-linecap="round"/></svg> Cancel, and the band turns into a paperweight</li>
                        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="#fb7185" stroke-width="2.2" stroke-linecap="round"/></svg> Your raw data lives on their servers, not yours</li>
                        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="#fb7185" stroke-width="2.2" stroke-linecap="round"/></svg> Closed hardware, closed algorithms, closed future</li>
                    </ul>
                </div>
                <div class="cmp-card cmp-us rv">
                    <h3 class="grad-text">Titan</h3>
                    <div class="cmp-price grad-text">$0<span style="font-size:1.1rem;font-weight:600;color:var(--muted)">/mo</span></div>
                    <div class="cmp-sub" style="color:var(--muted)">Own the band. Pay nothing after.</div>
                    <ul class="cmp-list">
                        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7" stroke="#34d399" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg> Nothing to cancel — there's no subscription to begin with</li>
                        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7" stroke="#34d399" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg> Your raw signal lives on a server you control</li>
                        <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7" stroke="#34d399" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg> Open hardware, open algorithms, yours to build on</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- DATA / WHAT IT SEES -->
    <section class="sec" id="data">
        <div class="wrap">
            <div class="sec-head center rv">
                <span class="eyebrow kicker">What Titan sees</span>
                <h2>Your whole body, in numbers you can act on.</h2>
                <p class="lead">From one wearable: overnight recovery, sleep architecture, daily strain, and heart-rate variability — validated against gold-standard science, explained in plain language by your coach.</p>
            </div>
            <div class="scores">
                <div class="score rv">
                    <div class="ring" style="--p:87;--c:var(--green)"><span class="ring-val">87<small>Recovery</small></span></div>
                    <div class="score-label">Primed to push</div>
                    <div class="score-sub">HRV &amp; resting HR vs your own baseline</div>
                </div>
                <div class="score rv">
                    <div class="ring" style="--p:92;--c:var(--cyan)"><span class="ring-val">7:42<small>Sleep</small></span></div>
                    <div class="score-label">Deep, REM &amp; light</div>
                    <div class="score-sub">Whole-night staging from a real PSG model</div>
                </div>
                <div class="score rv">
                    <div class="ring" style="--p:68;--c:var(--amber)"><span class="ring-val">14.2<small>Strain</small></span></div>
                    <div class="score-label">Cardiovascular load</div>
                    <div class="score-sub">Today's effort vs your recovery-aware target</div>
                </div>
                <div class="score rv">
                    <div class="ring" style="--p:74;--c:var(--indigo)"><span class="ring-val">68<small>HRV ms</small></span></div>
                    <div class="score-label">Above baseline</div>
                    <div class="score-sub">Whole-night RMSSD, signal-quality graded</div>
                </div>
            </div>
        </div>
    </section>

    <!-- COACH -->
    <section class="sec" id="coach" style="background:var(--ink-2);border-top:1px solid var(--line);border-bottom:1px solid var(--line);">
        <div class="wrap coach-grid">
            <div class="rv">
                <span class="eyebrow kicker">The coach</span>
                <h2 class="sec-head" style="font-size:clamp(2rem,4.2vw,3rem)">An AI coach that actually knows your body.</h2>
                <p class="lead" style="margin-top:1rem;">Not a chatbot bolted on. A coach that reads your live data, remembers your life, and tells you what to do today — and why.</p>
                <div class="feat-list">
                    <div class="feat">
                        <div class="feat-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 21s-7-4.6-7-10a4 4 0 017-2.6A4 4 0 0119 11c0 5.4-7 10-7 10z" stroke="#a5b4fc" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                        <div><h4>It remembers you</h4><p>Injuries, goals, what's worked, what you hate. It carries your context forever, like a coach who's known you for years.</p></div>
                    </div>
                    <div class="feat">
                        <div class="feat-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h10M4 17h13" stroke="#67e8f9" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                        <div><h4>It's grounded in your data</h4><p>Every call is built on your real recovery, sleep, training and nutrition — never generic advice, never invented numbers.</p></div>
                    </div>
                    <div class="feat">
                        <div class="feat-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="#6ee7b7" stroke-width="1.8"/><path d="M16 16l4 4" stroke="#6ee7b7" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                        <div><h4>It does the research</h4><p>Asks it anything and it digs into the science, files what it learns, and applies it to you specifically.</p></div>
                    </div>
                </div>
            </div>
            <div class="chat rv">
                <div class="chat-top">
                    <div class="chat-av">T</div>
                    <b>Titan</b>
                    <span><span style="width:6px;height:6px;border-radius:50%;background:var(--green);display:inline-block"></span> live</span>
                </div>
                <div class="bub me">Should I train hard today?</div>
                <div class="bub ai">
                    You're primed for it. 💪
                    <div class="mini-card">
                        <div class="mini-ring"><b>87</b></div>
                        <div><div class="mc-t">Recovery 87 · Primed</div><div class="mc-s">HRV 68ms (above baseline) · slept 7h 42m</div></div>
                    </div>
                </div>
                <div class="bub ai">Go for the heavy lower session you planned. Your knee's been touchy, so warm up the hips first — and aim for 165g protein to back the load.</div>
            </div>
        </div>
    </section>

    <!-- BAND / HARDWARE -->
    <section class="sec hw" id="band">
        <div class="wrap hw-grid">
            <div class="hw-stage rv">
                <div class="band-glow" style="inset:14% 16%;"></div>
                <img class="band-poster" src="{{ asset('images/band.png') }}" alt="The Titan band hardware" loading="lazy">
                <canvas id="hwBand"></canvas>
            </div>
            <div class="rv">
                <span class="eyebrow kicker">The band</span>
                <h2 class="sec-head" style="font-size:clamp(2rem,4.2vw,3rem)">Open hardware. Real signal. Yours to keep.</h2>
                <p class="lead" style="margin-top:1rem;">A research-grade optical wearable you can build, buy, or self-host — streaming the raw waveform straight to a server you own.</p>
                <div class="specs">
                    <div class="spec"><span class="sd" style="background:var(--green)"></span><div><b>Optical PPG sensor</b><p>Continuous heart rate &amp; whole-night HRV — the same physiology the labs measure, on your wrist.</p></div></div>
                    <div class="spec"><span class="sd" style="background:var(--cyan)"></span><div><b>3-axis accelerometer</b><p>Sleep stages, steps, strain and movement — fused with HR for honest, validated metrics.</p></div></div>
                    <div class="spec"><span class="sd" style="background:var(--indigo)"></span><div><b>Your raw data, exported</b><p>Every sample is yours. Export it, self-host it, build on it. No walled garden, no lock-in.</p></div></div>
                </div>
            </div>
        </div>
    </section>

    <!-- FOR EVERYONE -->
    <section class="sec" id="everyone">
        <div class="wrap">
            <div class="sec-head center rv">
                <span class="eyebrow kicker">For every body</span>
                <h2>Health is universal. So is Titan.</h2>
                <p class="lead">Not just for athletes. For anyone with a body who wants to understand it better — at any age, any goal, any starting point.</p>
            </div>
            <div class="every">
                <div class="ev rv"><div class="evi">🌙</div><h4>The exhausted parent</h4><p>See your real sleep debt and get tiny, doable ways to recover on broken nights.</p></div>
                <div class="ev rv"><div class="evi">📚</div><h4>The stressed student</h4><p>Watch how late nights and stress hit your body — and what actually brings you back.</p></div>
                <div class="ev rv"><div class="evi">🏃</div><h4>The masters athlete</h4><p>Train with your recovery, not against it. Push on green days, hold back on red.</p></div>
                <div class="ev rv"><div class="evi">🩺</div><h4>Managing a condition</h4><p>Track honest trends over time and bring real data to the conversation with your doctor.</p></div>
                <div class="ev rv"><div class="evi">🥾</div><h4>The weekend adventurer</h4><p>Show up to the trail, the climb, the game rested and ready — and know when you're not.</p></div>
                <div class="ev rv"><div class="evi">✨</div><h4>Just getting started</h4><p>No jargon. The coach meets you where you are and explains everything in plain language.</p></div>
            </div>
        </div>
    </section>

    <!-- SELF HOST / OPEN -->
    <section class="host" id="open">
        <div class="wrap sec host-grid">
            <div class="rv">
                <span class="eyebrow kicker">Open &amp; self-hostable</span>
                <h2 class="sec-head" style="font-size:clamp(2rem,4.2vw,3rem)">Run the whole thing yourself.</h2>
                <p class="lead" style="margin-top:1rem;">Every line is open source. Your data never has to leave a server you control. One command brings up the entire platform — the app, the AI coach, the biosignal engine, all of it.</p>
                <div class="hero-cta">
                    <a class="btn btn-primary" href="https://github.com/martoast/titan">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.58 2 12.25c0 4.53 2.87 8.37 6.84 9.73.5.1.68-.22.68-.49v-1.7c-2.78.62-3.37-1.22-3.37-1.22-.45-1.18-1.11-1.5-1.11-1.5-.91-.64.07-.62.07-.62 1 .07 1.53 1.06 1.53 1.06.89 1.56 2.34 1.11 2.91.85.09-.66.35-1.11.63-1.36-2.22-.26-4.56-1.14-4.56-5.07 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.71 0 0 .84-.27 2.75 1.05a9.4 9.4 0 015 0c1.91-1.32 2.75-1.05 2.75-1.05.55 1.41.2 2.45.1 2.71.64.72 1.03 1.63 1.03 2.75 0 3.94-2.34 4.81-4.57 5.06.36.32.68.94.68 1.9v2.81c0 .27.18.6.69.49A10.26 10.26 0 0022 12.25C22 6.58 17.52 2 12 2z"/></svg>
                        Star on GitHub
                    </a>
                    <a class="btn btn-ghost" href="https://github.com/martoast/titan/blob/master/DEPLOY.md">Read the deploy guide</a>
                </div>
            </div>
            <div class="terminal rv">
                <div class="term-bar"><i style="background:#fb7185"></i><i style="background:#fbbf24"></i><i style="background:#34d399"></i></div>
                <div class="term-body">
                    <div class="c"># your server, your data, forever</div>
                    <div><span class="p">$</span> <span class="w">git clone github.com/martoast/titan</span></div>
                    <div><span class="p">$</span> <span class="w">docker compose -f docker-compose.prod.yml up -d</span></div>
                    <div class="g">✓ app · coach · biosignal · db · live</div>
                    <div class="c" style="margin-top:0.4rem;"># no subscription. no data leaves home.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- FINAL CTA -->
    <section class="final">
        <div class="wrap rv">
            <h2>Understand your body.<br><span class="grad-text">Starting today.</span></h2>
            <p class="lead">Free to start. Free to run forever. Built open, for every body.</p>
            <div class="hero-cta">
                <a class="btn btn-primary" href="{{ route('register') }}">Get started — it's free</a>
                <a class="btn btn-ghost" href="https://github.com/martoast/titan">View the source</a>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <div class="wrap foot">
            <a href="#top" class="brand" style="font-size:1.2rem;"><span class="grad-text">TITAN</span></a>
            <div class="foot-links">
                <a href="#data">What it sees</a>
                <a href="#coach">The coach</a>
                <a href="#band">The band</a>
                <a href="https://github.com/martoast/titan">GitHub</a>
            </div>
            <div class="fl">Open source · Subscription-free · Made for every body.</div>
        </div>
    </footer>

    <!-- ===== 3D band module + page behaviour ===== -->
    <script type="module" src="{{ asset('js/titan-band.js') }}"></script>
    <script type="module">
        @verbatim
        // ---- Drive a band canvas with a calm, resting heartbeat ----
        function liveBand(canvasId) {
            const canvas = document.getElementById(canvasId);
            if (!canvas || !window.__titanBand) return;
            let bpm = 58, rmssd = 66, t = 0;
            const metrics = () => {
                t += 0.016;
                return { bpm: bpm + Math.sin(t * 0.25) * 2.5, rmssd: rmssd + Math.sin(t * 0.13) * 4 };
            };
            let api;
            try { api = window.__titanBand(canvas, () => 'rest', null, metrics); }
            catch (e) { canvas.style.display = 'none'; return; }
            // heartbeat → pulse the optical LEDs in time with the bpm
            function beat() {
                if (api && api.beat) api.beat();
                setTimeout(beat, 60000 / (bpm + Math.sin(t * 0.25) * 2.5));
            }
            beat();
        }
        liveBand('heroBand');
        const hw = document.getElementById('hwBand');
        if (hw) liveBand('hwBand');

        // ---- Nav scroll state ----
        const nav = document.getElementById('nav');
        const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 24);
        onScroll(); window.addEventListener('scroll', onScroll, { passive: true });

        // ---- Scroll reveals ----
        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
        }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
        document.querySelectorAll('.rv').forEach((el, i) => {
            el.style.transitionDelay = (Math.min(i % 4, 3) * 80) + 'ms';
            io.observe(el);
        });
        @endverbatim
    </script>
</body>
</html>
