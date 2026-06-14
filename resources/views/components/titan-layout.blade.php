@props(['title' => 'Titan'])

@php
    // Central nav registry. Domains link by PATH (not route names) so the shell never
    // breaks if a section's routes aren't wired yet. Each build owns its own page at
    // its path. Keep this list as the single source of truth for the sidebar.
    $nav = [
        ['label' => 'Dashboard',  'path' => 'dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['label' => 'The Brain',  'path' => 'brain',      'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
        ['label' => 'Bloodwork',  'path' => 'biomarkers', 'icon' => 'M19 14a7 7 0 11-14 0c0-3 2.5-6.5 7-11 4.5 4.5 7 8 7 11z'],
        ['label' => 'Meals',      'path' => 'meals',      'icon' => 'M3 3h18M3 3v18M3 7h18M7 3v4m0 8a3 3 0 106 0 3 3 0 00-6 0z'],
        ['label' => 'Workouts',   'path' => 'workouts',   'icon' => 'M6 6l12 12M4 8l2-2 2 2-2 2-2-2zm14 6l2-2 2 2-2 2-2-2z'],
        ['label' => 'Sleep',      'path' => 'sleep',      'icon' => 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z'],
        ['label' => 'Recovery',   'path' => 'recovery',   'icon' => 'M13 10V3L4 14h7v7l9-11h-7z'],
        ['label' => 'Physique',   'path' => 'photos',     'icon' => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ['label' => 'Coach',      'path' => 'coach',      'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-4A7.97 7.97 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
        ['label' => 'Duo',        'path' => 'duo',        'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-2a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-3-1.5'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Titan</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-950 text-gray-100">
    <div class="min-h-screen flex">
        {{-- Sidebar --}}
        <aside class="hidden md:flex md:flex-col w-60 shrink-0 border-r border-white/5 bg-gray-900/60 backdrop-blur">
            <div class="h-16 flex items-center gap-2 px-5 border-b border-white/5">
                <span class="text-xl font-black tracking-tight bg-gradient-to-r from-indigo-400 to-cyan-300 bg-clip-text text-transparent">TITAN</span>
            </div>
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                @foreach ($nav as $item)
                    @php $active = request()->is($item['path']) || request()->is($item['path'].'/*'); @endphp
                    <a href="/{{ $item['path'] }}"
                       class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                              {{ $active ? 'bg-indigo-500/15 text-indigo-300' : 'text-gray-400 hover:text-gray-100 hover:bg-white/5' }}">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                        </svg>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="px-3 py-4 border-t border-white/5">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left text-sm text-gray-500 hover:text-gray-200 px-3 py-2 rounded-lg hover:bg-white/5">Log out</button>
                </form>
            </div>
        </aside>

        {{-- Main --}}
        <div class="flex-1 flex flex-col min-w-0">
            <header class="h-16 flex items-center justify-between px-6 border-b border-white/5 bg-gray-900/40">
                <div>
                    <h1 class="text-lg font-semibold">{{ $title }}</h1>
                    @isset($subtitle)
                        <p class="text-xs text-gray-500">{{ $subtitle }}</p>
                    @endisset
                </div>
                <div class="flex items-center gap-3 text-sm text-gray-400">
                    <span>{{ auth()->user()?->name }}</span>
                    <div class="h-8 w-8 rounded-full bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center text-xs font-bold text-gray-900">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'T', 0, 1)) }}
                    </div>
                </div>
            </header>

            @if (session('status'))
                <div class="mx-6 mt-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 px-4 py-2 text-sm text-emerald-300">
                    {{ session('status') }}
                </div>
            @endif

            <main class="flex-1 p-6">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
