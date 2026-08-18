<x-titan-layout title="You" subtitle="Profile, band & setup">
    {{-- Profile header --}}
    <x-card pad="p-5" class="mb-4">
        <div class="flex items-center gap-4">
            <div class="h-16 w-16 rounded-full p-px bg-gradient-to-br from-titan-indigo to-titan-cyan shrink-0">
                <div class="h-full w-full rounded-full bg-titan-bg grid place-items-center text-xl font-bold font-display text-gray-100 overflow-hidden">
                    @if ($profile->avatar_path)<img src="{{ asset('storage/'.$profile->avatar_path) }}" class="h-full w-full object-cover" alt="">@else{{ strtoupper(substr($user->name ?? 'T', 0, 1)) }}@endif
                </div>
            </div>
            <div class="min-w-0">
                <div class="font-display text-xl font-bold text-gray-100 truncate">{{ $user->name }}</div>
                <div class="text-sm text-gray-500 truncate">{{ $user->email }}</div>
                @if ($profile->username)<div class="text-xs text-titan-cyan mt-0.5">&commat;{{ $profile->username }}</div>@endif
            </div>
        </div>
    </x-card>

    {{-- Fold-in destinations (the iOS "You" rows) --}}
    @php
        $rows = [
            ['Your band', 'Pair, live signal, chest strap', '/devices', 'M9 17a2 2 0 11-4 0 2 2 0 014 0zm10 0a2 2 0 11-4 0 2 2 0 014 0zM5 9h14M7 9V6a1 1 0 011-1h8a1 1 0 011 1v3m-1 0v4a1 1 0 01-1 1H9a1 1 0 01-1-1V9', 'indigo'],
            ['Physique', 'Goal image & progress photos', '/photos', 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z', 'pink'],
            ['The Brain', 'What your coach remembers', '/brain', 'M9.5 4a2.5 2.5 0 00-2.45 3A2.5 2.5 0 005 9.5a2.5 2.5 0 001.5 2.29M9.5 4A2.5 2.5 0 0112 6.5m0 0v13m0-13A2.5 2.5 0 0114.5 4a2.5 2.5 0 012.45 3A2.5 2.5 0 0119 9.5a2.5 2.5 0 01-1.5 2.29', 'violet'],
            ['Research', 'Deep-dive briefs from your coach', '/research', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.247', 'cyan'],
            ['Community & sharing', 'Username, avatar, visibility', '/community', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z', 'mint'],
            ['Notifications', 'Coaching intensity & reminders', '/notifications', 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', 'amber'],
            ['Connect', 'API tokens for your agent', '/connect', 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 10-5.656-5.656l-1.1 1.1', 'indigo'],
            ['Account', 'Name, email, password', '/profile', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', 'cyan'],
        ];
        $hex = ['indigo'=>'#6D6BF6','cyan'=>'#22D3EE','mint'=>'#34E5C0','pink'=>'#FF4D8D','amber'=>'#FFB020','violet'=>'#A78BFA'];
    @endphp
    <div class="space-y-2">
        @foreach ($rows as [$label, $sub, $path, $icon, $color])
            <a href="{{ $path }}">
                <x-card pad="p-4" class="flex items-center gap-4 hover:bg-white/[0.02] transition">
                    <span class="grid h-10 w-10 place-items-center rounded-full shrink-0" style="background: {{ $hex[$color] }}26;">
                        <svg class="h-5 w-5" style="color: {{ $hex[$color] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold text-gray-100">{{ $label }}</div>
                        <div class="text-[12px] text-gray-500 truncate">{{ $sub }}</div>
                    </div>
                    <svg class="h-4 w-4 text-gray-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </x-card>
            </a>
        @endforeach
    </div>

    {{-- Open source + sign out --}}
    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="w-full rounded-card bg-white/5 py-3 text-sm font-semibold text-gray-400 hover:bg-white/10 transition">Sign out</button>
    </form>
</x-titan-layout>
