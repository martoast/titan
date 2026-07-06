<x-titan-layout title="Community" subtitle="Your athletes — feed & leaderboards">
    @php $glow = 'rgba(52,229,192,0.22)'; @endphp

    @unless ($enabled)
        {{-- Opt-in gate — mirrors the iOS CommunityOptInCard --}}
        <x-card pad="p-6" class="text-center max-w-md mx-auto mt-6">
            <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-titan-mint/15">
                <svg class="h-7 w-7 text-titan-mint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
            </div>
            <h2 class="font-display text-xl font-bold text-gray-100">Join the community</h2>
            <p class="mt-2 text-sm text-gray-400 leading-relaxed">Share workouts, follow friends, and climb the leaderboards. Opt in — you control who sees what.</p>
            <a href="/profile" class="mt-5 inline-flex items-center gap-2 rounded-chip bg-titan-indigo px-5 py-2.5 text-sm font-semibold text-white active:opacity-90">
                Set up your profile
            </a>
        </x-card>
    @else
        {{-- Feed / Leaders segmented control (iOS PillSwitch) --}}
        <div class="flex rounded-chip bg-white/5 p-1 mb-5 max-w-xs">
            @foreach (['feed' => 'Feed', 'leaders' => 'Leaders'] as $k => $lbl)
                <a href="/community?view={{ $k }}"
                   class="flex-1 text-center rounded-[10px] px-4 py-1.5 text-sm font-semibold transition
                          {{ $tab === $k ? 'bg-titan-indigo text-white' : 'text-gray-400 hover:text-gray-200' }}">{{ $lbl }}</a>
            @endforeach
        </div>

        @if ($tab === 'leaders')
            {{-- Leaderboard --}}
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach (['effort' => 'Effort', 'distance' => 'Distance', 'points' => 'Points'] as $k => $lbl)
                    <a href="/community?view=leaders&metric={{ $k }}&window={{ $window }}"
                       class="rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $metric === $k ? 'bg-titan-cyan/15 text-titan-cyan' : 'bg-white/5 text-gray-400' }}">{{ $lbl }}</a>
                @endforeach
                <span class="mx-1 w-px bg-white/10"></span>
                @foreach (['week' => 'Week', 'month' => 'Month', 'all' => 'All time'] as $k => $lbl)
                    <a href="/community?view=leaders&metric={{ $metric }}&window={{ $k }}"
                       class="rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $window === $k ? 'bg-titan-cyan/15 text-titan-cyan' : 'bg-white/5 text-gray-400' }}">{{ $lbl }}</a>
                @endforeach
            </div>

            <x-card pad="p-2">
                @forelse ($leaderboard['athletes'] as $a)
                    <div class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ $a['is_you'] ? 'bg-titan-indigo/10' : '' }}">
                        <span class="w-6 text-center font-display font-bold nums {{ $a['rank'] <= 3 ? 'text-titan-amber' : 'text-gray-500' }}">{{ $a['rank'] }}</span>
                        <div class="h-9 w-9 rounded-full p-px bg-gradient-to-br from-titan-indigo to-titan-cyan shrink-0">
                            <div class="h-full w-full rounded-full bg-titan-bg grid place-items-center text-xs font-bold text-gray-100 overflow-hidden">
                                @if ($a['avatar_url'])<img src="{{ $a['avatar_url'] }}" class="h-full w-full object-cover" alt="">@else{{ strtoupper(substr($a['name'], 0, 1)) }}@endif
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-gray-100 truncate">{{ $a['name'] }}{{ $a['is_you'] ? ' · You' : '' }}</div>
                            <div class="text-[11px] text-gray-500 nums">{{ $a['activity_count'] }} activities</div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="font-display font-bold text-gray-100 nums">{{ $a['value'] }}</div>
                            <div class="text-[10px] uppercase tracking-wide text-gray-500">{{ $leaderboard['unit'] }}</div>
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-10 text-center text-sm text-gray-500">No ranked activities yet this {{ $window === 'all' ? 'period' : $window }}.</p>
                @endforelse
            </x-card>
        @else
            {{-- Feed --}}
            <div class="space-y-4">
                @forelse ($feed as $item)
                    <x-card pad="p-0" class="overflow-hidden">
                        <div class="flex items-center gap-3 p-4">
                            <div class="h-10 w-10 rounded-full p-px bg-gradient-to-br from-titan-indigo to-titan-cyan shrink-0">
                                <div class="h-full w-full rounded-full bg-titan-bg grid place-items-center text-sm font-bold text-gray-100 overflow-hidden">
                                    @if ($item['athlete']['avatar_url'])<img src="{{ $item['athlete']['avatar_url'] }}" class="h-full w-full object-cover" alt="">@else{{ strtoupper(substr($item['athlete']['name'], 0, 1)) }}@endif
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-gray-100 truncate">{{ $item['athlete']['name'] }}</div>
                                <div class="text-[11px] text-gray-500">{{ $item['started_at'] ? \Carbon\Carbon::parse($item['started_at'])->diffForHumans() : '' }}</div>
                            </div>
                        </div>
                        @if ($item['map_thumb_url'])
                            <img src="{{ $item['map_thumb_url'] }}" class="w-full aspect-[16/9] object-cover" alt="route" loading="lazy">
                        @endif
                        <div class="p-4">
                            <div class="font-display font-bold text-gray-100">{{ $item['title'] }}</div>
                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                                @if ($item['distance_km'] !== null)<span class="text-gray-300 nums">{{ number_format($item['distance_km'], 2) }} <span class="text-gray-500">km</span></span>@endif
                                @if ($item['duration_min'])<span class="text-gray-300 nums">{{ $item['duration_min'] }} <span class="text-gray-500">min</span></span>@endif
                                @if ($item['relative_effort'])<span class="text-titan-cyan nums">{{ $item['relative_effort'] }} <span class="text-gray-500">effort</span></span>@endif
                            </div>
                            <div class="mt-3 flex items-center gap-4 text-[12px] text-gray-500">
                                <span class="inline-flex items-center gap-1">❤ {{ $item['kudos_count'] }}</span>
                                <span class="inline-flex items-center gap-1">💬 {{ $item['comment_count'] }}</span>
                            </div>
                        </div>
                    </x-card>
                @empty
                    <x-card pad="p-8" class="text-center">
                        <p class="text-sm text-gray-400">No activity yet. Follow some athletes, or log a workout to start your feed.</p>
                    </x-card>
                @endforelse
            </div>
        @endif
    @endunless
</x-titan-layout>
