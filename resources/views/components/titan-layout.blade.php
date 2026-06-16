@props(['title' => 'Titan', 'subtitle' => null])

@php
    // Full nav registry (desktop sidebar + mobile "More" sheet). Single source of truth.
    $nav = [
        ['label' => 'Dashboard', 'path' => 'dashboard',  'icon' => 'M3 12l2-2 7-7 7 7 2 2M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['label' => 'The Brain', 'path' => 'brain',      'icon' => 'M9.5 4a2.5 2.5 0 00-2.45 3A2.5 2.5 0 005 9.5a2.5 2.5 0 001.5 2.29M9.5 4A2.5 2.5 0 0112 6.5m-2.5-2.5A2.5 2.5 0 0112 6.5m0 0v13m0-13A2.5 2.5 0 0114.5 4a2.5 2.5 0 012.45 3A2.5 2.5 0 0119 9.5a2.5 2.5 0 01-1.5 2.29'],
        ['label' => 'Bloodwork', 'path' => 'biomarkers', 'icon' => 'M12 3s5 5.5 5 9.5a5 5 0 11-10 0C7 8.5 12 3 12 3z'],
        ['label' => 'Meals',     'path' => 'meals',      'icon' => 'M5 3v7a3 3 0 006 0V3M8 3v18m9-18s2 1 2 5-2 4-2 4v7'],
        ['label' => 'Workouts',  'path' => 'workouts',   'icon' => 'M6.5 6.5l11 11M4 9l1.5-1.5M9 4L7.5 5.5m9 13L18 17m-1-9l2-2M2.5 11.5l3 3m13-3l-3-3'],
        ['label' => 'Sleep',     'path' => 'sleep',      'icon' => 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z'],
        ['label' => 'Recovery',  'path' => 'recovery',   'icon' => 'M13 10V3L4 14h7v7l9-11h-7z'],
        ['label' => 'Fitness',   'path' => 'fitness',    'icon' => 'M3 12h3l2-7 4 14 2-7h7'],
        ['label' => 'Devices',   'path' => 'devices',    'icon' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zm10 0a2 2 0 11-4 0 2 2 0 014 0zM5 9h14M7 9V6a1 1 0 011-1h8a1 1 0 011 1v3m-1 0v4a1 1 0 01-1 1H9a1 1 0 01-1-1V9'],
        ['label' => 'Connect',   'path' => 'connect',    'icon' => 'M13 10V3L4 14h7v7l9-11h-7zM8 21l8-18'],
        ['label' => 'Simulator', 'path' => 'simulator',  'icon' => 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z'],
        ['label' => 'Physique',  'path' => 'photos',     'icon' => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ['label' => 'Coach',     'path' => 'coach',      'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-4A7.97 7.97 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
        ['label' => 'Duo',       'path' => 'duo',        'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-2a4 4 0 10-4-4 4 4 0 004 4zm6 0a3.5 3.5 0 00-1-.2'],
    ];
    // Cycle is shown only when relevant (female profile, or tracking enabled, or has data).
    if (auth()->check() && \App\Support\Cycle::available(auth()->user()->ensureProfile())) {
        $cycleItem = ['label' => 'Cycle', 'path' => 'cycle', 'icon' => 'M21 12a9 9 0 11-2.64-6.36M21 4v4h-4'];
        $ri = collect($nav)->search(fn ($i) => $i['path'] === 'recovery');
        array_splice($nav, $ri === false ? count($nav) : $ri + 1, 0, [$cycleItem]);
    }
    // Primary destinations for the mobile bottom bar (most-used daily).
    $tabPaths = ['dashboard', 'meals', 'workouts', 'coach'];
    $tabs = collect($nav)->whereIn('path', $tabPaths)->sortBy(fn ($i) => array_search($i['path'], $tabPaths))->values();
    $isActive = fn ($path) => request()->is($path) || request()->is($path.'/*');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07080a">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="vapid-public-key" content="{{ config('services.webpush.public_key') }}">
    <title>{{ $title }} · Titan</title>

    {{-- PWA --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.svg">
    <link rel="icon" type="image/svg+xml" href="/icons/icon.svg">
    <meta name="application-name" content="Titan">
    <meta name="apple-mobile-web-app-title" content="Titan">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Register the service worker (PWA offline shell) --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function (e) {
                    console.warn('SW registration failed:', e);
                });
            });
        }
    </script>
</head>
<body class="min-h-dvh text-gray-100 antialiased" style="background: var(--titan-bg);"
      x-data="{ moreOpen: false }">

    {{-- Ambient atmosphere --}}
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 h-[36rem] w-[36rem] rounded-full bg-indigo-600/15 blur-[120px]"></div>
        <div class="absolute top-1/3 -right-32 h-[28rem] w-[28rem] rounded-full bg-cyan-500/10 blur-[120px]"></div>
    </div>

    <div class="flex min-h-dvh">
        {{-- ===== Desktop sidebar ===== --}}
        <aside class="hidden md:flex md:flex-col w-60 shrink-0 border-r border-white/5 bg-white/[0.02] backdrop-blur">
            <div class="h-16 flex items-center px-5 border-b border-white/5">
                <span class="font-display text-2xl font-extrabold tracking-tight bg-gradient-to-r from-indigo-400 to-cyan-300 bg-clip-text text-transparent">TITAN</span>
            </div>
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                @foreach ($nav as $item)
                    <a href="/{{ $item['path'] }}"
                       class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition
                              {{ $isActive($item['path']) ? 'bg-indigo-500/15 text-indigo-200' : 'text-gray-400 hover:text-gray-100 hover:bg-white/5' }}">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" /></svg>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="px-3 py-4 border-t border-white/5">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left text-sm text-gray-500 hover:text-gray-200 px-3 py-2 rounded-xl hover:bg-white/5 transition">Log out</button>
                </form>
            </div>
        </aside>

        {{-- ===== Main column ===== --}}
        <div class="flex-1 flex flex-col min-w-0">
            <header class="sticky top-0 z-30 pt-safe bg-[#07080a]/80 backdrop-blur-xl border-b border-white/5">
                <div class="h-14 md:h-16 flex items-center justify-between px-4 md:px-6">
                    <div class="min-w-0">
                        <h1 class="font-display text-xl md:text-2xl font-bold tracking-tight truncate">{{ $title }}</h1>
                        @if ($subtitle)
                            <p class="text-[11px] md:text-xs text-gray-500 truncate -mt-0.5">{{ $subtitle }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="hidden sm:block text-sm text-gray-400">{{ auth()->user()?->name }}</span>

                        {{-- ===== Notification bell + dropdown ===== --}}
                        <div class="relative" x-data="titanNotifications()" x-init="init()" @keydown.escape.window="open = false">
                            <button type="button" @click="toggle()" aria-label="Notifications"
                                    class="relative grid h-9 w-9 place-items-center rounded-full text-gray-400 hover:text-gray-100 hover:bg-white/5 transition">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                </svg>
                                <span x-show="unread > 0" x-cloak
                                      class="absolute -top-0.5 -right-0.5 min-w-[1.05rem] h-[1.05rem] px-1 rounded-full bg-indigo-500 text-[10px] font-bold leading-[1.05rem] text-center text-white ring-2 ring-[#07080a]"
                                      x-text="unread > 9 ? '9+' : unread"></span>
                            </button>

                            {{-- Backdrop (mobile tap-away) --}}
                            <div x-show="open" x-cloak @click="open = false" class="fixed inset-0 z-40"></div>

                            {{-- Dropdown --}}
                            <div x-show="open" x-cloak
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                 class="absolute right-0 z-50 mt-2 w-[20rem] max-w-[calc(100vw-1.5rem)] origin-top-right rounded-2xl border border-white/10 bg-[#0b0d10] shadow-2xl shadow-black/50 overflow-hidden">
                                <div class="flex items-center justify-between px-4 py-3 border-b border-white/5">
                                    <span class="font-display text-sm font-bold">Notifications</span>
                                    <button type="button" @click="markAllRead()" x-show="unread > 0"
                                            class="text-[11px] font-semibold text-indigo-300 hover:text-indigo-200">Mark all read</button>
                                </div>

                                <button type="button" @click="enablePush()" x-show="canPrompt" x-cloak
                                        class="w-full flex items-center gap-2 px-4 py-2.5 text-left text-[12px] text-indigo-200 bg-indigo-500/10 hover:bg-indigo-500/15 border-b border-white/5 transition">
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5"/></svg>
                                    Turn on push notifications
                                </button>

                                <div class="max-h-[60vh] overflow-y-auto divide-y divide-white/5">
                                    <template x-if="items.length === 0">
                                        <div class="px-4 py-10 text-center text-sm text-gray-500">No notifications yet</div>
                                    </template>
                                    <template x-for="n in items" :key="n.id">
                                        <a :href="n.url || '#'" @click="onItemClick(n, $event)"
                                           class="block px-4 py-3 transition hover:bg-white/[0.03]"
                                           :class="n.read ? '' : 'bg-indigo-500/[0.06]'">
                                            <div class="flex items-start gap-2.5">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : 'bg-indigo-400'"></span>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-baseline justify-between gap-2">
                                                        <p class="text-sm font-semibold text-gray-100 truncate" x-text="n.title"></p>
                                                        <span class="shrink-0 text-[10px] text-gray-500" x-text="n.ago"></span>
                                                    </div>
                                                    <p class="text-[12px] text-gray-400 mt-0.5 line-clamp-2" x-text="n.body"></p>
                                                </div>
                                            </div>
                                        </a>
                                    </template>
                                </div>

                                <a href="/notifications" class="block px-4 py-2.5 text-center text-[12px] font-semibold text-gray-400 hover:text-gray-200 border-t border-white/5">
                                    View all
                                </a>
                            </div>
                        </div>

                        <div class="h-9 w-9 rounded-full p-px bg-gradient-to-br from-indigo-500 to-cyan-400">
                            <div class="h-full w-full rounded-full bg-[#07080a] flex items-center justify-center text-xs font-bold font-display text-gray-100">
                                {{ strtoupper(substr(auth()->user()?->name ?? 'T', 0, 1)) }}
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            @if (session('status'))
                <div class="mx-4 md:mx-6 mt-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-2.5 text-sm text-emerald-300">
                    {{ session('status') }}
                </div>
            @endif

            {{-- pb leaves room for the mobile bottom bar --}}
            <main class="flex-1 px-4 md:px-6 pt-5 pb-28 md:pb-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- ===== Mobile bottom tab bar ===== --}}
    <nav class="md:hidden fixed inset-x-0 bottom-0 z-40 h-bottom-nav pb-safe
                bg-[#0b0d10]/90 backdrop-blur-xl border-t border-white/10">
        <div class="grid grid-cols-5 h-[4.25rem]">
            @foreach ($tabs as $tab)
                <a href="/{{ $tab['path'] }}"
                   class="flex flex-col items-center justify-center gap-1 text-[10px] font-medium transition
                          {{ $isActive($tab['path']) ? 'text-indigo-300' : 'text-gray-500' }}">
                    <span class="relative">
                        @if ($isActive($tab['path']))
                            <span class="absolute -inset-2 rounded-full bg-indigo-500/15 blur-sm"></span>
                        @endif
                        <svg class="relative h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}" /></svg>
                    </span>
                    {{ $tab['label'] }}
                </a>
            @endforeach
            {{-- More --}}
            <button type="button" @click="moreOpen = true"
                    class="flex flex-col items-center justify-center gap-1 text-[10px] font-medium text-gray-500">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                More
            </button>
        </div>
    </nav>

    {{-- ===== Mobile "More" sheet ===== --}}
    <div class="md:hidden" x-cloak>
        <div x-show="moreOpen" x-transition.opacity @click="moreOpen = false"
             class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm"></div>
        <div x-show="moreOpen"
             x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="fixed inset-x-0 bottom-0 z-50 rounded-t-3xl border-t border-white/10 bg-[#0b0d10] pb-safe">
            <div class="flex justify-center pt-3"><div class="h-1.5 w-10 rounded-full bg-white/20"></div></div>
            <div class="flex items-center justify-between px-5 pt-3 pb-1">
                <span class="font-display text-lg font-bold">All sections</span>
                <button @click="moreOpen = false" class="text-gray-500 p-2 -mr-2"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="grid grid-cols-4 gap-1 px-3 pb-3 pt-2">
                @foreach ($nav as $item)
                    <a href="/{{ $item['path'] }}"
                       class="flex flex-col items-center gap-2 rounded-2xl py-3 px-1 text-center transition active:bg-white/5
                              {{ $isActive($item['path']) ? 'text-indigo-300' : 'text-gray-300' }}">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl {{ $isActive($item['path']) ? 'bg-indigo-500/20' : 'bg-white/5' }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" /></svg>
                        </span>
                        <span class="text-[11px] leading-tight">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
            <form method="POST" action="{{ route('logout') }}" class="px-5 pb-4 pt-1">
                @csrf
                <button type="submit" class="w-full rounded-2xl bg-white/5 py-3 text-sm font-medium text-gray-400 active:bg-white/10">Log out</button>
            </form>
        </div>
    </div>

    {{-- ===== Notifications + Web Push client ===== --}}
    <script>
    (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const vapidPublicKey = document.querySelector('meta[name="vapid-public-key"]')?.content || '';

        // --- helpers ---
        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            const out = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
            return out;
        }
        async function postJson(url, body, method) {
            return fetch(url, {
                method: method || 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
            });
        }

        // Shared push-subscribe routine (used by the bell + the notifications page button).
        async function subscribeToPush() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                alert('Push notifications are not supported on this device/browser.');
                return false;
            }
            if (!vapidPublicKey) {
                console.warn('[push] no VAPID public key configured');
                return false;
            }
            // Politely ask — only ever on an explicit user action (button click).
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') return false;

            const reg = await navigator.serviceWorker.ready;
            let sub = await reg.pushManager.getSubscription();
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                });
            }
            const res = await postJson('/notifications/subscribe', sub.toJSON());
            return res.ok;
        }
        window.titanSubscribeToPush = subscribeToPush;

        // Should we still offer the "enable push" prompt?
        function pushPromptable() {
            return ('serviceWorker' in navigator) && ('PushManager' in window)
                && !!vapidPublicKey && 'Notification' in window
                && Notification.permission === 'default';
        }

        // --- Alpine: header bell ---
        window.titanNotifications = function () {
            return {
                open: false,
                unread: 0,
                items: [],
                canPrompt: false,
                init() {
                    this.canPrompt = pushPromptable();
                    this.refresh();
                    // Light polling so the badge stays roughly live.
                    setInterval(() => this.refresh(), 60000);
                },
                async refresh() {
                    try {
                        const res = await fetch('/notifications/feed', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        if (!res.ok) return;
                        const data = await res.json();
                        this.unread = data.unread || 0;
                        this.items = data.notifications || [];
                    } catch (e) { /* offline — leave last state */ }
                },
                toggle() {
                    this.open = !this.open;
                    if (this.open) this.refresh();
                },
                async markRead(id) {
                    const n = this.items.find((x) => x.id === id);
                    if (n && !n.read) { n.read = true; this.unread = Math.max(0, this.unread - 1); }
                    await postJson('/notifications/' + id + '/read');
                },
                onItemClick(n, ev) {
                    this.markRead(n.id);
                    if (!n.url) { ev.preventDefault(); }
                },
                async markAllRead() {
                    this.items.forEach((n) => (n.read = true));
                    this.unread = 0;
                    await postJson('/notifications/read-all');
                },
                async enablePush() {
                    const ok = await subscribeToPush();
                    if (ok) this.canPrompt = false;
                },
            };
        };

        // --- Alpine: full notifications page ---
        window.notificationCenter = function () {
            return {
                unread: {{ isset($unreadCount) ? (int) $unreadCount : 0 }},
                canPrompt: false,
                init() { this.canPrompt = pushPromptable(); },
                async markRead(id, el) {
                    if (el) {
                        const dot = el.querySelector('[data-dot]');
                        if (dot) dot.classList.remove('bg-indigo-400');
                        el.classList.remove('border-indigo-500/20', 'bg-indigo-500/[0.06]');
                        el.classList.add('border-white/5', 'bg-white/[0.02]');
                    }
                    this.unread = Math.max(0, this.unread - 1);
                    await postJson('/notifications/' + id + '/read');
                },
                async markAllRead() {
                    document.querySelectorAll('[data-notif]').forEach((el) => {
                        const dot = el.querySelector('[data-dot]');
                        if (dot) dot.classList.remove('bg-indigo-400');
                        el.classList.remove('border-indigo-500/20', 'bg-indigo-500/[0.06]');
                        el.classList.add('border-white/5', 'bg-white/[0.02]');
                    });
                    this.unread = 0;
                    await postJson('/notifications/read-all');
                },
                async enablePush() {
                    const ok = await window.titanSubscribeToPush();
                    if (ok) this.canPrompt = false;
                },
            };
        };
    })();
    </script>
</body>
</html>
