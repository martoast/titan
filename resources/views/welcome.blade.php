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
    <meta property="og:image" content="{{ asset('images/og-card.png') }}">
    <meta property="og:image:width" content="1424">
    <meta property="og:image:height" content="752">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Titan — Understand your body. Own your data.">
    <meta name="twitter:description" content="The open, subscription-free health OS. A wearable you own, an AI coach that knows you, your data that stays yours.">
    <meta name="twitter:image" content="{{ asset('images/og-card.png') }}">
    <meta name="theme-color" content="#06070A">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=archivo:500,600,700,800,900|manrope:400,500,600,700" rel="stylesheet">

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

        /* ---- comparison table ---- */
        .cmp-table-wrap { margin-top: 3rem; overflow-x: auto; border-radius: 20px; border: 1px solid var(--line); -webkit-overflow-scrolling: touch; }
        .cmp-table { width: 100%; border-collapse: collapse; min-width: 640px; background: var(--surface); }
        .cmp-table th, .cmp-table td { padding: 1.05rem 1.1rem; text-align: center; border-bottom: 1px solid var(--line); vertical-align: middle; }
        .cmp-table thead th { font-family: var(--font-d); font-weight: 800; font-size: 1.1rem; color: var(--faint); padding: 1.5rem 1.1rem; }
        .cmp-table th.ft { text-align: left; font-family: var(--font-b); font-weight: 600; font-size: 0.82rem; color: var(--faint); text-transform: uppercase; letter-spacing: 0.06em; }
        .cmp-table td:first-child { text-align: left; color: #d4dce9; font-weight: 600; font-size: 0.96rem; }
        .cmp-table thead .brand-us { color: #fff; font-size: 1.35rem; background: linear-gradient(180deg, rgba(34,211,238,0.12), rgba(99,102,241,0.06)); }
        .cmp-table td.us { background: linear-gradient(180deg, rgba(34,211,238,0.07), rgba(99,102,241,0.045)); }
        .cmp-table tbody tr:last-child td { border-bottom: none; }
        .cmp-table .ic { width: 22px; height: 22px; fill: none; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }
        .cmp-table .ic.ok { stroke: var(--green); }
        .cmp-table .ic.no { stroke: #565e6d; stroke-width: 2.3; width: 19px; height: 19px; }
        .cmp-table .lim { color: var(--amber); font-size: 0.82rem; font-weight: 600; }
        .cmp-table td { color: var(--faint); font-size: 0.95rem; }
        .cmp-table .g { color: var(--green); font-family: var(--font-d); }
        .cmp-table .cost td { font-family: var(--font-d); font-weight: 700; font-size: 1.02rem; color: var(--muted); padding-top: 1.3rem; padding-bottom: 1.3rem; }
        .cmp-table .cost td:first-child { color: #fff; }
        .cmp-foot { text-align: center; margin-top: 1.7rem; color: var(--muted); font-size: clamp(1rem, 1.6vw, 1.18rem); }

        /* ---- floating product image (replaces the 3D canvas) ---- */
        .band-hero-img { position: relative; z-index: 1; width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 30px 60px rgba(0,0,0,0.55)); animation: floaty 6s ease-in-out infinite; }
        @keyframes floaty { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-14px); } }
        @media (prefers-reduced-motion: reduce) { .band-hero-img { animation: none; } }

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

        /* ---- sleep architecture panel ---- */
        .sleeparch { margin-top: 1.5rem; background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 1.6rem 1.7rem; }
        .sleeparch-top { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 0.4rem 1rem; }
        .sleeparch-top h4 { font-family: var(--font-d); font-weight: 700; color: #fff; font-size: 1.06rem; }
        .sleeparch-top h4 span { color: var(--cyan); }
        .sleeparch-dur { font-family: var(--font-d); font-weight: 900; font-size: 1.5rem; color: #fff; letter-spacing: -0.01em; }
        .sleeparch-dur small { font-size: 0.8rem; color: var(--muted); font-weight: 600; margin-left: 0.2rem; }
        .sleeparch-bar { display: flex; height: 38px; border-radius: 10px; overflow: hidden; margin-top: 1.1rem; gap: 2px; }
        .sleeparch-bar i { display: block; height: 100%; }
        .sleeparch-legend { display: flex; flex-wrap: wrap; gap: 0.7rem 1.5rem; margin-top: 1.1rem; }
        .sleeparch-legend span { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.86rem; color: var(--muted); font-weight: 600; }
        .sleeparch-legend i { width: 11px; height: 11px; border-radius: 3px; }
        .sleeparch-legend b { color: #fff; font-family: var(--font-d); font-weight: 700; }

        /* ---- snap your food (AI nutrition) ---- */
        .snap-demo { margin-top: 3.2rem; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 1.6rem; }
        .snap-photo { position: relative; border-radius: 22px; overflow: hidden; border: 1px solid var(--line-2); box-shadow: 0 40px 80px -40px rgba(0,0,0,0.85); aspect-ratio: 16 / 11; }
        .snap-photo img { width: 100%; height: 100%; object-fit: cover; }
        .snap-photo::after { content: ''; position: absolute; left: 0; right: 0; height: 38%; top: -38%; background: linear-gradient(180deg, transparent, rgba(34,211,238,0.28), transparent); animation: scanmove 2.6s ease-in-out infinite; }
        @keyframes scanmove { 0% { top: -38%; } 60%,100% { top: 100%; } }
        .snap-tag { position: absolute; left: 12px; bottom: 12px; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.7rem; border-radius: 999px; background: rgba(6,7,10,0.7); backdrop-filter: blur(8px); font-size: 0.74rem; font-weight: 600; color: #e7ecf5; border: 1px solid var(--line-2); }
        .snap-shutter { width: 9px; height: 9px; border-radius: 999px; background: var(--cyan); box-shadow: 0 0 0 0 rgba(34,211,238,0.6); animation: pulsedot 1.6s ease-out infinite; }
        @keyframes pulsedot { 0% { box-shadow: 0 0 0 0 rgba(34,211,238,0.55); } 100% { box-shadow: 0 0 0 10px rgba(34,211,238,0); } }
        .snap-arrow { color: var(--faint); font-size: 1.5rem; }
        .snap-card { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 1.5rem 1.6rem; box-shadow: 0 40px 80px -40px rgba(0,0,0,0.8); }
        .snap-card-head { display: flex; align-items: center; justify-content: space-between; }
        .snap-ai { font-size: 0.74rem; font-weight: 700; color: var(--cyan); display: inline-flex; align-items: center; gap: 0.35rem; }
        .snap-done { font-size: 0.74rem; font-weight: 700; color: var(--green); }
        .snap-title { font-family: var(--font-d); font-weight: 800; font-size: 1.16rem; color: #fff; margin-top: 0.7rem; }
        .snap-cal { font-family: var(--font-d); margin-top: 0.5rem; display: flex; align-items: baseline; gap: 0.4rem; }
        .snap-cal b { font-size: 2.8rem; font-weight: 900; letter-spacing: -0.02em; color: #fff; line-height: 1; }
        .snap-cal span { font-size: 0.95rem; color: var(--muted); font-weight: 600; }
        .snap-macros { margin-top: 1.3rem; display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.9rem; }
        .snap-macro { text-align: center; }
        .snap-mr { width: 76px; height: 76px; margin: 0 auto 0.5rem; border-radius: 50%; display: grid; place-items: center; position: relative; background: conic-gradient(var(--mc) calc(var(--p) * 1%), rgba(255,255,255,0.07) 0); }
        .snap-mr::before { content: ''; position: absolute; inset: 7px; border-radius: 50%; background: var(--surface); }
        .snap-mr b { position: relative; font-family: var(--font-d); font-weight: 800; font-size: 1.15rem; color: #fff; }
        .snap-mr i { position: relative; font-style: normal; font-size: 0.7rem; color: var(--muted); font-weight: 600; }
        .snap-macro span { font-size: 0.8rem; color: var(--muted); font-weight: 600; }

        /* ---- women's cycle ---- */
        .cycle { background: var(--ink-2); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .cycle-grid { display: grid; grid-template-columns: 0.95fr 1.05fr; gap: 3.5rem; align-items: center; }
        .cycle-wheel-wrap { position: relative; display: grid; place-items: center; }
        .cycle-wheel { width: clamp(230px, 32vw, 320px); aspect-ratio: 1; border-radius: 50%; position: relative;
            background: conic-gradient(var(--rose) 0 17.9%, var(--green) 17.9% 46.4%, var(--cyan) 46.4% 57.1%, var(--amber) 57.1% 100%);
            -webkit-mask: radial-gradient(circle, transparent 53%, #000 54%); mask: radial-gradient(circle, transparent 53%, #000 54%); }
        .cycle-center { position: absolute; inset: 0; display: grid; place-items: center; text-align: center; }
        .cycle-day { font-family: var(--font-d); font-weight: 900; font-size: clamp(2rem, 4vw, 2.6rem); color: #fff; line-height: 1; }
        .cycle-day small { display: block; font-size: 0.62rem; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: var(--faint); font-family: var(--font-b); margin-bottom: 0.25rem; }
        .cycle-phase { font-size: 0.92rem; font-weight: 700; color: var(--green); margin-top: 0.45rem; }
        .cycle-marker { position: absolute; inset: 0; transform: rotate(103deg); }
        .cycle-marker i { position: absolute; top: -7px; left: 50%; transform: translateX(-50%); width: 20px; height: 20px; border-radius: 50%; background: #fff; border: 4px solid var(--green); box-shadow: 0 2px 10px rgba(0,0,0,0.5); }
        .cycle-legend { display: flex; flex-wrap: wrap; gap: 0.8rem 1.3rem; margin-top: 2rem; }
        .cycle-legend span { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.84rem; font-weight: 600; color: var(--muted); }
        .cycle-legend i { width: 10px; height: 10px; border-radius: 999px; }

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
            .cmp, .coach-grid, .hw-grid, .host-grid, .cycle-grid { grid-template-columns: 1fr; }
            .cycle-grid .cycle-wheel-wrap { order: -1; }
            .snap-demo { grid-template-columns: 1fr; gap: 1.1rem; }
            .snap-arrow { transform: rotate(90deg); }
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
                <div class="eyebrow rv">24/7 health intelligence</div>
                <h1 class="rv" style="margin-top:1.1rem;">Your body,<br><span class="grad-text">fully understood.</span></h1>
                <p class="lead rv">Snap your meals, read your recovery, see your future self — all coached by one AI that actually knows your body. A wearable you own, no subscription, your data forever.</p>
                <div class="hero-cta rv">
                    <a class="btn btn-primary" href="{{ route('register') }}">Get started</a>
                    <a class="btn btn-ghost" href="#data">See how it works</a>
                </div>
                <div class="pillrow rv">
                    <span class="pill"><span class="dot" style="background:var(--green)"></span> 24/7 monitoring</span>
                    <span class="pill"><span class="dot" style="background:var(--cyan)"></span> Personal AI coach</span>
                    <span class="pill"><span class="dot" style="background:var(--indigo)"></span> Open &amp; yours forever</span>
                </div>
            </div>
            <div class="band-stage rv">
                <div class="band-glow"></div>
                <img class="band-hero-img" src="{{ asset('images/band-hero.png') }}" alt="The Titan band — an open, research-grade recovery wearable showing a 92% recovery score" fetchpriority="high">
            </div>
        </div>
    </header>

    <!-- SNAP YOUR FOOD (AI NUTRITION) -->
    <section class="sec" id="food">
        <div class="wrap">
            <div class="sec-head center rv">
                <span class="eyebrow kicker">AI nutrition</span>
                <h2>Just snap it.<br><span class="grad-text">Titan reads the plate.</span></h2>
                <p class="lead">No weighing, no barcodes, no scrolling a database. Photograph your meal — or just tell your coach what you ate — and the calories and macros are logged in seconds, then tracked against your targets all day.</p>
            </div>
            <div class="snap-demo rv">
                <figure class="snap-photo">
                    <img src="{{ asset('images/meal-hero.png') }}" alt="A meal of grilled chicken, rice and broccoli being analyzed by Titan" loading="lazy">
                    <figcaption class="snap-tag"><span class="snap-shutter"></span> Snapped · analyzing…</figcaption>
                </figure>
                <div class="snap-arrow" aria-hidden="true">&rarr;</div>
                @verbatim
                <div class="snap-card">
                    <div class="snap-card-head">
                        <span class="snap-ai">✨ AI estimated</span>
                        <span class="snap-done">Logged ✓</span>
                    </div>
                    <div class="snap-title">Grilled chicken, rice &amp; broccoli</div>
                    <div class="snap-cal"><b>540</b> <span>kcal</span></div>
                    <div class="snap-macros">
                        <div class="snap-macro">
                            <div class="snap-mr" style="--p:61;--mc:var(--green)"><b>61</b><i>g</i></div>
                            <span>Protein</span>
                        </div>
                        <div class="snap-macro">
                            <div class="snap-mr" style="--p:46;--mc:var(--amber)"><b>56</b><i>g</i></div>
                            <span>Carbs</span>
                        </div>
                        <div class="snap-macro">
                            <div class="snap-mr" style="--p:14;--mc:var(--rose)"><b>7</b><i>g</i></div>
                            <span>Fat</span>
                        </div>
                    </div>
                </div>
                @endverbatim
            </div>
        </div>
    </section>

    <!-- DATA / WHAT IT SEES -->
    <section class="sec" id="data">
        <div class="wrap">
            <div class="sec-head center rv">
                <span class="eyebrow kicker">What Titan sees</span>
                <h2>Your whole body, in numbers you can act on.</h2>
                <p class="lead">From one wearable: overnight recovery, sleep architecture, daily strain, VO₂max, even your biological age — validated against gold-standard science, and explained in plain language by your coach.</p>
            </div>
            <div class="scores">
                <div class="score rv">
                    <div class="ring" style="--p:87;--c:var(--green)"><span class="ring-val">87<small>Recovery</small></span></div>
                    <div class="score-label">Primed to push</div>
                    <div class="score-sub">HRV &amp; resting HR vs your own baseline</div>
                </div>
                <div class="score rv">
                    <div class="ring" style="--p:82;--c:var(--cyan)"><span class="ring-val">52<small>VO₂max</small></span></div>
                    <div class="score-label">Top 10% for your age</div>
                    <div class="score-sub">Cardio fitness, the #1 longevity signal</div>
                </div>
                <div class="score rv">
                    <div class="ring" style="--p:90;--c:var(--indigo)"><span class="ring-val">22<small>Bio age</small></span></div>
                    <div class="score-label">8 yrs younger</div>
                    <div class="score-sub">PhenoAge from your bloodwork &amp; vitals</div>
                </div>
                <div class="score rv">
                    <div class="ring" style="--p:68;--c:var(--amber)"><span class="ring-val">14.2<small>Strain</small></span></div>
                    <div class="score-label">Cardiovascular load</div>
                    <div class="score-sub">Today's effort vs your recovery-aware target</div>
                </div>
            </div>
            @verbatim
            <div class="sleeparch rv">
                <div class="sleeparch-top">
                    <h4>Last night's <span>sleep architecture</span></h4>
                    <div class="sleeparch-dur">7h 42m <small>· 92% performance</small></div>
                </div>
                <div class="sleeparch-bar">
                    <i style="width:5%;background:#475569" title="Awake"></i>
                    <i style="width:24%;background:#818cf8" title="Light"></i>
                    <i style="width:16%;background:#6366f1" title="Deep"></i>
                    <i style="width:13%;background:#22d3ee" title="REM"></i>
                    <i style="width:22%;background:#818cf8" title="Light"></i>
                    <i style="width:8%;background:#6366f1" title="Deep"></i>
                    <i style="width:12%;background:#22d3ee" title="REM"></i>
                </div>
                <div class="sleeparch-legend">
                    <span><i style="background:#6366f1"></i> Deep <b>1h 12m</b></span>
                    <span><i style="background:#22d3ee"></i> REM <b>1h 58m</b></span>
                    <span><i style="background:#818cf8"></i> Light <b>4h 13m</b></span>
                    <span><i style="background:#475569"></i> Awake <b>0h 19m</b></span>
                </div>
            </div>
            @endverbatim
        </div>
    </section>

    <!-- WOMEN'S CYCLE -->
    <section class="sec cycle" id="cycle">
        <div class="wrap cycle-grid">
            <div class="cycle-wheel-wrap rv">
                @verbatim
                <div class="cycle-wheel"></div>
                <div class="cycle-center">
                    <div class="cycle-day"><small>Cycle</small>Day 8</div>
                    <div class="cycle-phase">Follicular phase</div>
                </div>
                <div class="cycle-marker"><i></i></div>
                @endverbatim
            </div>
            <div class="rv">
                <span class="eyebrow kicker">Women's health</span>
                <h2 class="sec-head" style="font-size:clamp(2rem,4.2vw,3rem)">Built for your cycle, too.</h2>
                <p class="lead" style="margin-top:1rem;">Most health apps treat every day the same. Your hormones don't — so Titan weaves your cycle into your training, your nutrition, and your recovery.</p>
                <div class="feat-list">
                    <div class="feat">
                        <div class="feat-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M20 12a8 8 0 11-2.3-5.6M20 4v4h-4" stroke="#fda4af" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                        <div><h4>Training that flexes with your phase</h4><p>Push hard in your follicular phase when energy peaks; ease back before your period. Your plan adapts — you're not fighting your body.</p></div>
                    </div>
                    <div class="feat">
                        <div class="feat-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M5 3v7a3 3 0 006 0V3M8 3v18" stroke="#fbbf24" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                        <div><h4>Nutrition that meets you there</h4><p>Your calorie target rises in the luteal phase, when your body genuinely burns more. Cravings explained and fueled, never shamed.</p></div>
                    </div>
                    <div class="feat">
                        <div class="feat-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3s5 5.5 5 9.5a5 5 0 11-10 0C7 8.5 12 3 12 3z" stroke="#67e8f9" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                        <div><h4>Private, predictive, yours</h4><p>Log your period in a tap and Titan predicts the next, factoring your phase into recovery. For awareness and coaching — never a diagnosis.</p></div>
                    </div>
                </div>
                <div class="cycle-legend">
                    <span><i style="background:var(--rose)"></i> Menstrual</span>
                    <span><i style="background:var(--green)"></i> Follicular</span>
                    <span><i style="background:var(--cyan)"></i> Ovulation</span>
                    <span><i style="background:var(--amber)"></i> Luteal</span>
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
                <img class="band-hero-img" src="{{ asset('images/band.png') }}" alt="The Titan band hardware" loading="lazy">
            </div>
            <div class="rv">
                <span class="eyebrow kicker">The band</span>
                <h2 class="sec-head" style="font-size:clamp(2rem,4.2vw,3rem)">Built to be worn 24/7.</h2>
                <p class="lead" style="margin-top:1rem;">Always-on sensors, a multi-day battery, and a focused, screen-free design — research-grade signal, comfortable enough to forget you're wearing it.</p>
                <div class="specs">
                    <div class="spec"><span class="sd" style="background:var(--green)"></span><div><b>Always-on optical sensor</b><p>Continuous heart rate and whole-night HRV — the same physiology the labs measure, right on your wrist.</p></div></div>
                    <div class="spec"><span class="sd" style="background:var(--cyan)"></span><div><b>Multi-day battery, screen-free focus</b><p>Wear it for days, charge in minutes. No pings, no distractions — just your body, measured.</p></div></div>
                    <div class="spec"><span class="sd" style="background:var(--indigo)"></span><div><b>Open hardware, your data</b><p>Every sample is yours to export, self-host, and build on. No walled garden, no lock-in.</p></div></div>
                </div>
            </div>
        </div>
    </section>

    <!-- THESIS -->
    <section class="thesis">
        <div class="wrap sec">
            <div class="sec-head center rv">
                <span class="eyebrow kicker">The complete picture</span>
                <h2>A complete view of<br><span class="grad-text">your health.</span></h2>
                <p class="lead">24/7 monitoring across sleep, recovery, strain, and heart health — the same validated science the premium wearables run on, so you can make smarter decisions every day. With one quiet difference: it's yours to keep.</p>
            </div>
            @verbatim
            <div class="cmp-table-wrap rv">
                <table class="cmp-table">
                    <thead>
                        <tr><th class="ft">Same science as the big names</th><th class="brand-us">Titan</th><th>Whoop</th><th>Oura</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Recovery &amp; readiness score</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td></tr>
                        <tr><td>Sleep stages &amp; quality</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td></tr>
                        <tr><td>HRV, resting HR &amp; strain</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td></tr>
                        <tr><td>Snap-a-photo nutrition &amp; macros</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td></tr>
                        <tr><td>Cycle-aware training &amp; nutrition</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td></tr>
                        <tr><td>AI coach that knows your body</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><span class="lim">Limited</span></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td></tr>
                        <tr><td>Export your raw data</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td></tr>
                        <tr><td>Open source</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td></tr>
                        <tr><td>Self-hostable — your server</td><td class="us"><svg class="ic ok" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td><td><svg class="ic no" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></td></tr>
                        <tr><td>Monthly subscription</td><td class="us"><b class="g">None</b></td><td>$30 / mo</td><td>$6 / mo</td></tr>
                        <tr class="cost"><td>Cost after the device</td><td class="us"><b class="grad-text">$0 forever</b></td><td>$239 / yr</td><td>$70 / yr</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="cmp-foot rv">Same science. Same insights. <span class="grad-text">None of the rent</span> — and your data never leaves your hands.</p>
            @endverbatim
        </div>
    </section>

    <!-- FOR EVERYONE -->
    <section class="sec" id="everyone">
        <div class="wrap">
            <div class="sec-head center rv">
                <span class="eyebrow kicker">For every body</span>
                <h2>Health is universal. So is Titan.</h2>
                <p class="lead">Whether you're chasing a personal record or just better sleep, on day one of your journey or year ten — Titan meets you where you are and helps you get better.</p>
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
            <h2>Start understanding<br><span class="grad-text">your body today.</span></h2>
            <p class="lead">Real insights from day one — and a coach in your corner for the long run.</p>
            <div class="hero-cta">
                <a class="btn btn-primary" href="{{ route('register') }}">Get started</a>
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

    <!-- ===== page behaviour ===== -->
    <script type="module">
        @verbatim
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
