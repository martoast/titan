@props(['title' => 'Titan'])

@php
    // Tools remain reachable from the account menu, but the chat is the centre of the app.
    $tools = [
        ['label' => 'Dashboard', 'path' => 'dashboard'],
        ['label' => 'Progress', 'path' => 'progress'],
        ['label' => 'The Brain', 'path' => 'brain'],
        ['label' => 'Bloodwork', 'path' => 'biomarkers'],
        ['label' => 'Meals', 'path' => 'meals'],
        ['label' => 'Workouts', 'path' => 'workouts'],
        ['label' => 'Sleep', 'path' => 'sleep'],
        ['label' => 'Recovery', 'path' => 'recovery'],
        ['label' => 'Fitness', 'path' => 'fitness'],
        ['label' => 'Physique', 'path' => 'photos'],
        ['label' => 'Cycle', 'path' => 'cycle'],
        ['label' => 'Devices', 'path' => 'devices'],
    ];
    $u = auth()->user();
    $name = $u?->ensureProfile()->display_name ?: ($u?->name ?? 'You');
    $initial = strtoupper(mb_substr($name, 0, 1));
    $showCycle = \App\Support\Cycle::available($u->ensureProfile());
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

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.svg">
    <link rel="icon" type="image/svg+xml" href="/icons/icon.svg">
    <meta name="apple-mobile-web-app-title" content="Titan">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function () {});
            });
        }
    </script>
</head>
<body class="bg-[#07080a] text-gray-100 antialiased">
    <div class="flex h-[100dvh] flex-col">
        {{-- Slim top bar — wordmark + (optional) page controls + account --}}
        <header class="relative z-30 flex h-14 shrink-0 items-center gap-2 border-b border-white/5 px-3 pt-[env(safe-area-inset-top)]">
            <a href="/coach" class="flex items-center gap-2 pl-1" aria-label="Titan home">
                <span class="grid h-7 w-7 place-items-center rounded-lg bg-gradient-to-br from-indigo-500 to-cyan-400">
                    <svg class="h-4 w-4 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </span>
                <span class="font-display text-base font-extrabold tracking-tight">TITAN</span>
            </a>

            {{-- Page-supplied controls (e.g. conversation toggle) --}}
            <div class="min-w-0 flex-1">{{ $bar ?? '' }}</div>

            {{-- Account menu --}}
            <div x-data="{ open: false, tools: false }" @click.outside="open = false" class="relative shrink-0">
                <button @click="open = !open" class="grid h-9 w-9 place-items-center rounded-full bg-indigo-500/20 font-display text-sm font-bold text-indigo-200 ring-1 ring-white/10 active:bg-indigo-500/30">{{ $initial }}</button>
                <div x-show="open" x-cloak x-transition.origin.top.right
                     class="absolute right-0 mt-2 w-60 overflow-hidden rounded-2xl border border-white/10 bg-[#0c0e12] shadow-xl shadow-black/50">
                    <div class="border-b border-white/5 px-4 py-3">
                        <p class="truncate font-display text-sm font-bold text-gray-100">{{ $name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ $u?->email }}</p>
                    </div>
                    <div class="p-1.5">
                        <a href="/profile" class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm text-gray-200 hover:bg-white/5">
                            <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Account settings
                        </a>
                        <a href="/notifications/settings" class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm text-gray-200 hover:bg-white/5">
                            <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            Notifications
                        </a>
                        <button @click="tools = !tools" class="flex w-full items-center justify-between gap-2.5 rounded-xl px-3 py-2.5 text-sm text-gray-200 hover:bg-white/5">
                            <span class="flex items-center gap-2.5">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                                All tools
                            </span>
                            <svg class="h-4 w-4 text-gray-500 transition" :class="tools ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="tools" x-cloak class="mb-1 grid grid-cols-2 gap-1 px-1 pb-1">
                            @foreach ($tools as $t)
                                @if ($t['path'] !== 'cycle' || $showCycle)
                                    <a href="/{{ $t['path'] }}" class="truncate rounded-lg px-2.5 py-2 text-xs text-gray-400 hover:bg-white/5 hover:text-gray-100">{{ $t['label'] }}</a>
                                @endif
                            @endforeach
                        </div>
                        <form method="POST" action="/logout" class="border-t border-white/5 pt-1">
                            @csrf
                            <button class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm text-rose-300 hover:bg-rose-500/10">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="min-h-0 flex-1">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
