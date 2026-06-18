@props(['title' => 'Titan'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Titan' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <meta name="theme-color" content="#06070A">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=archivo:600,700,800,900|manrope:400,500,600,700" rel="stylesheet">

    @verbatim
    <style>
        :root {
            --ink: #06070A; --surface: #10141E; --surface-2: #141926;
            --line: rgba(255,255,255,0.08); --line-2: rgba(255,255,255,0.14);
            --text: #F4F7FB; --muted: #99A3B5; --faint: #6A7385;
            --indigo: #6366F1; --cyan: #22D3EE; --green: #34D399; --rose: #FB7185;
            --grad: linear-gradient(100deg, #818CF8 0%, #22D3EE 100%);
            --font-d: 'Archivo', system-ui, sans-serif;
            --font-b: 'Manrope', system-ui, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--ink); color: var(--text); font-family: var(--font-b); -webkit-font-smoothing: antialiased; min-height: 100vh; }
        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; }
        ::selection { background: rgba(99,102,241,0.35); }

        .auth { display: grid; grid-template-columns: 1.08fr 1fr; min-height: 100vh; }

        /* ---- left visual panel ---- */
        .auth-visual { position: relative; overflow: hidden; background: #04050a; }
        .auth-visual > img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0.92; }
        .auth-visual::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(4,5,10,0.55) 0%, rgba(4,5,10,0.1) 40%, rgba(4,5,10,0.82) 100%); }
        .vtop { position: absolute; top: 2.1rem; left: 2.4rem; z-index: 2; }
        .vbottom { position: absolute; bottom: 2.6rem; left: 2.4rem; right: 2.4rem; z-index: 2; }
        .vbottom h2 { font-family: var(--font-d); font-weight: 800; font-size: clamp(1.6rem, 2.6vw, 2.3rem); line-height: 1.08; letter-spacing: -0.02em; color: #fff; }
        .vbottom p { color: #c4cdda; margin-top: 0.7rem; font-size: 1rem; max-width: 36ch; }

        .brand { display: inline-flex; align-items: center; gap: 0.55rem; font-family: var(--font-d); font-weight: 800; font-size: 1.3rem; letter-spacing: -0.01em; }
        .brand .mark { width: 25px; height: 25px; }
        .grad-text { background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }

        /* ---- right form panel ---- */
        .auth-pane { position: relative; display: flex; align-items: center; justify-content: center; padding: 2.5rem 1.5rem; background: var(--ink); }
        .auth-pane::before { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 80% 50% at 70% 0%, rgba(99,102,241,0.08), transparent 60%); pointer-events: none; }
        .auth-home { position: absolute; top: 1.8rem; right: 2rem; color: var(--faint); font-size: 0.84rem; font-weight: 600; transition: color .15s; z-index: 2; }
        .auth-home:hover { color: var(--text); }
        .auth-card { position: relative; width: 100%; max-width: 396px; }

        .auth-head { margin-bottom: 1.9rem; }
        .auth-head h1 { font-family: var(--font-d); font-weight: 800; font-size: clamp(1.8rem, 3vw, 2.15rem); letter-spacing: -0.02em; color: #fff; }
        .auth-head p { color: var(--muted); margin-top: 0.5rem; font-size: 0.98rem; }

        /* the on-page brand for mobile (when the visual panel is hidden) */
        .auth-brand-m { display: none; margin-bottom: 2rem; }

        /* ---- form elements (used by custom markup AND the shared components) ---- */
        .field { margin-bottom: 1.1rem; }
        .auth-label { display: block; font-size: 0.82rem; font-weight: 600; color: #c4cdda; margin-bottom: 0.45rem; }
        .auth-input { width: 100%; padding: 0.82rem 1rem; background: var(--surface); border: 1px solid var(--line-2); border-radius: 12px; color: #fff; font-family: var(--font-b); font-size: 0.98rem; transition: border-color .15s, box-shadow .15s; }
        .auth-input::placeholder { color: var(--faint); }
        .auth-input:focus { outline: none; border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(34,211,238,0.14); }
        .auth-input:-webkit-autofill { -webkit-text-fill-color: #fff; -webkit-box-shadow: 0 0 0 40px var(--surface) inset; caret-color: #fff; }
        .auth-error { list-style: none; color: var(--rose); font-size: 0.82rem; margin-top: 0.45rem; }
        .auth-note { background: rgba(52,211,153,0.1); border: 1px solid rgba(52,211,153,0.25); color: #6ee7b7; font-size: 0.86rem; padding: 0.7rem 0.9rem; border-radius: 10px; margin-bottom: 1.2rem; }

        .auth-row { display: flex; align-items: center; justify-content: space-between; margin: 0.2rem 0 1.4rem; flex-wrap: wrap; gap: 0.6rem; }
        .checkbox { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.86rem; color: var(--muted); cursor: pointer; user-select: none; }
        .checkbox input { width: 16px; height: 16px; accent-color: var(--cyan); }
        .link { color: var(--cyan); font-size: 0.86rem; font-weight: 600; }
        .link:hover { color: #67e8f9; }

        .auth-submit { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--grad); color: #08111c; border: none; padding: 0.92rem 1.5rem; border-radius: 999px; font-family: var(--font-d); font-weight: 700; font-size: 1rem; cursor: pointer; transition: transform .15s ease, box-shadow .2s ease; box-shadow: 0 8px 30px -10px rgba(34,211,238,0.5); }
        .auth-submit:hover { transform: translateY(-2px); box-shadow: 0 14px 40px -10px rgba(34,211,238,0.6); }

        .auth-foot { text-align: center; margin-top: 1.7rem; color: var(--muted); font-size: 0.92rem; }
        .auth-foot a { color: #fff; font-weight: 600; border-bottom: 1px solid rgba(34,211,238,0.6); padding-bottom: 1px; }

        /* spacing fallback for the component-built auth pages (forgot/reset/etc.) */
        .auth-card form > div { margin-bottom: 1.1rem; }
        .auth-card form > div:last-child { margin-bottom: 0; margin-top: 1.4rem; }

        @media (max-width: 860px) {
            .auth { grid-template-columns: 1fr; }
            .auth-visual { display: none; }
            .auth-brand-m { display: block; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
    @endverbatim
</head>
<body>
    <div class="auth">
        <aside class="auth-visual">
            <img src="{{ asset('images/auth-bg.png') }}" alt="">
            <a href="/" class="vtop brand">
                <svg class="mark" viewBox="0 0 32 32" fill="none">
                    <defs><linearGradient id="ag" x1="0" y1="0" x2="32" y2="32"><stop stop-color="#818CF8"/><stop offset="1" stop-color="#22D3EE"/></linearGradient></defs>
                    <circle cx="16" cy="16" r="13" stroke="url(#ag)" stroke-width="2.4"/>
                    <path d="M6 16.5h4.5l2-4 3.2 8.5 2.6-6 1.6 3h5.5" stroke="url(#ag)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="grad-text">TITAN</span>
            </a>
            <div class="vbottom">
                <h2>Your body,<br>fully understood.</h2>
                <p>24/7 health insights and a personal AI coach — open, and yours forever.</p>
            </div>
        </aside>

        <main class="auth-pane">
            <a href="/" class="auth-home">← Back to home</a>
            <div class="auth-card">
                <a href="/" class="auth-brand-m brand">
                    <svg class="mark" viewBox="0 0 32 32" fill="none">
                        <defs><linearGradient id="agm" x1="0" y1="0" x2="32" y2="32"><stop stop-color="#818CF8"/><stop offset="1" stop-color="#22D3EE"/></linearGradient></defs>
                        <circle cx="16" cy="16" r="13" stroke="url(#agm)" stroke-width="2.4"/>
                        <path d="M6 16.5h4.5l2-4 3.2 8.5 2.6-6 1.6 3h5.5" stroke="url(#agm)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="grad-text">TITAN</span>
                </a>
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
