<x-titan-layout title="Duo" subtitle="Brother vs brother — race your future self">
    @php
        $profiles = $comparison['profiles'] ?? [];
        $a = $profiles[0] ?? null;
        $b = $profiles[1] ?? null;
        $scores = $leaderboard['scores'] ?? [];
        $winner = $leaderboard['winner'] ?? null;
        $tie = $leaderboard['tie'] ?? false;

        // Accent each brother differently so the rivalry reads at a glance.
        $accents = [
            0 => ['ring' => 'ring-indigo-500/40', 'bar' => 'bg-indigo-500', 'text' => 'text-indigo-300', 'glow' => 'from-indigo-500/20', 'chip' => 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30'],
            1 => ['ring' => 'ring-cyan-500/40',  'bar' => 'bg-cyan-500',   'text' => 'text-cyan-300',   'glow' => 'from-cyan-500/20',   'chip' => 'bg-cyan-500/10 text-cyan-300 border-cyan-500/30'],
        ];
    @endphp

    @if (count($profiles) < 2)
        {{-- Defensive: the duo needs two profiles. Don't crash, explain. --}}
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-8 text-center">
            <div class="text-4xl mb-3">🏁</div>
            <h2 class="text-lg font-semibold text-gray-100">The duo isn't set up yet</h2>
            <p class="text-gray-500 mt-1 text-sm">
                Both brothers need a profile to start racing. Once profile 1 (Alex) and
                profile 2 (Bro) exist, the head-to-head lights up here.
            </p>
        </div>
    @else
        {{-- ===== This week's winner banner ===== --}}
        <div class="mb-6 overflow-hidden rounded-2xl border border-white/5 bg-gradient-to-r from-amber-500/10 via-gray-900/50 to-gray-900/50 p-5">
            <div class="flex items-center gap-4">
                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-amber-500/15 text-3xl">
                    🏆
                </div>
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wider text-amber-300/80">This week · {{ $leaderboard['week_label'] ?? '' }}</p>
                    @if ($winner)
                        <h2 class="text-xl font-bold text-gray-100 truncate">
                            {{ $winner['name'] }} is winning
                            <span class="text-amber-300">{{ $winner['points'] }} pts</span>
                        </h2>
                    @elseif ($tie)
                        <h2 class="text-xl font-bold text-gray-100">Dead heat — it's all tied up 🔥</h2>
                    @else
                        <h2 class="text-xl font-bold text-gray-100">No points on the board yet</h2>
                        <p class="text-sm text-gray-500">Log a workout or a meal to take the lead.</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===== Head-to-head stat cards ===== --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($profiles as $i => $p)
                @php $ac = $accents[$i] ?? $accents[0]; @endphp
                <div class="relative overflow-hidden rounded-2xl border border-white/5 bg-gray-900/50 p-5 ring-1 {{ $ac['ring'] }}">
                    <div class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-gradient-to-br {{ $ac['glow'] }} to-transparent blur-2xl"></div>

                    <div class="relative flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="grid h-11 w-11 place-items-center rounded-xl bg-white/5 text-lg font-bold {{ $ac['text'] }}">
                                {{ strtoupper(substr($p['name'], 0, 1)) }}
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-100">
                                    {{ $p['name'] }}
                                    @if (($meId ?? null) === $p['id'])
                                        <span class="ml-1 text-[10px] uppercase tracking-wide text-gray-500">(you)</span>
                                    @endif
                                </h3>
                                @if ($p['top_streak'])
                                    <p class="text-xs text-orange-300/90">
                                        🔥 {{ $p['top_streak']->current_count }}-day {{ str_replace('_', ' ', $p['top_streak']->kind) }} streak
                                    </p>
                                @else
                                    <p class="text-xs text-gray-600">no active streak</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- This-week activity grid --}}
                    <div class="relative mt-5 grid grid-cols-2 gap-3">
                        <x-duo-stat label="Workouts" :value="$p['workouts']" />
                        <x-duo-stat label="Meals logged" :value="$p['meals']" />
                        <x-duo-stat label="Avg sleep" :value="$p['avg_sleep'] !== null ? $p['avg_sleep'].'h' : '—'" />
                        <x-duo-stat label="Latest weight" :value="$p['latest_weight'] !== null ? $p['latest_weight'].' kg' : '—'" />
                    </div>

                    {{-- All streaks --}}
                    @if ($p['streaks']->isNotEmpty())
                        <div class="relative mt-4 flex flex-wrap gap-2">
                            @foreach ($p['streaks'] as $s)
                                <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs {{ $ac['chip'] }}">
                                    🔥 {{ $s->current_count }}
                                    <span class="opacity-70">{{ str_replace('_', ' ', $s->kind) }}</span>
                                    @if ($s->freezes_available > 0)
                                        <span class="opacity-50">· ❄️{{ $s->freezes_available }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ===== Physique race ===== --}}
        @if ($comparison['hasPhysiqueRace'] ?? false)
            <div class="mt-6 rounded-2xl border border-white/5 bg-gray-900/50 p-5">
                <div class="mb-4 flex items-center gap-2">
                    <span class="text-lg">🏁</span>
                    <h3 class="font-semibold text-gray-100">Race to the dream physique</h3>
                    <span class="text-xs text-gray-500">who's closer to their goal</span>
                </div>
                <div class="space-y-4">
                    @foreach ($profiles as $i => $p)
                        @php
                            $ac = $accents[$i] ?? $accents[0];
                            $pct = $p['pct_to_goal'];
                        @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="font-medium {{ $ac['text'] }}">{{ $p['name'] }}</span>
                                <span class="text-gray-400">{{ $pct !== null ? round($pct).'%' : 'no analysis yet' }}</span>
                            </div>
                            <div class="h-3 w-full overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full {{ $ac['bar'] }} transition-all"
                                     style="width: {{ $pct !== null ? max(2, min(100, $pct)) : 0 }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ===== Weekly leaderboard ===== --}}
        <div class="mt-6 rounded-2xl border border-white/5 bg-gray-900/50 p-5">
            <div class="mb-4 flex items-center gap-2">
                <span class="text-lg">📊</span>
                <h3 class="font-semibold text-gray-100">This week's leaderboard</h3>
                <span class="text-xs text-gray-500">workouts ×3 + meals + sleep nights</span>
            </div>

            @php $maxPts = collect($scores)->max('points') ?: 1; @endphp
            <div class="space-y-3">
                @foreach ($scores as $rank => $row)
                    @php $ac = $accents[$rank] ?? $accents[0]; @endphp
                    <div class="flex items-center gap-3">
                        <div class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-white/5 text-sm font-semibold text-gray-300">
                            {{ $rank === 0 ? '🥇' : ($rank === 1 ? '🥈' : ($rank + 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="font-medium text-gray-200">{{ $row['name'] }}</span>
                                <span class="text-gray-400">
                                    <span class="font-semibold text-gray-100">{{ $row['points'] }}</span> pts
                                    <span class="ml-1 text-xs text-gray-600">
                                        ({{ $row['workouts'] }}w · {{ $row['meals'] }}m · {{ $row['sleep_nights'] }}s)
                                    </span>
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full {{ $ac['bar'] }}"
                                     style="width: {{ max(2, round(($row['points'] / $maxPts) * 100)) }}%"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-titan-layout>
