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
        ['label' => 'Physique',  'path' => 'photos',     'icon' => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ['label' => 'Coach',     'path' => 'coach',      'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-4A7.97 7.97 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
        ['label' => 'Duo',       'path' => 'duo',        'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-2a4 4 0 10-4-4 4 4 0 004 4zm6 0a3.5 3.5 0 00-1-.2'],
    ];
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
    <title>{{ $title }} · Titan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
</body>
</html>
