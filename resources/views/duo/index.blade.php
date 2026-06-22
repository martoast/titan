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
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-8 text-center">
            <div class="text-4xl mb-3">🏁</div>
            <h2 class="font-display text-lg font-semibold text-gray-100">The duo isn't set up yet</h2>
            <p class="text-gray-500 mt-1 text-sm">
                Both brothers need a profile to start racing. Once profile 1 (Alex) and
                profile 2 (Bro) exist, the head-to-head lights up here.
            </p>
        </div>
    @else
        <div class="space-y-4 md:space-y-5">
        {{-- ===== This week's winner banner (full-width) ===== --}}
        <div class="overflow-hidden rounded-2xl border border-white/5 bg-gradient-to-r from-amber-500/10 via-gray-900/50 to-gray-900/50 p-4 md:p-5">
            <div class="flex items-center gap-4">
                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-amber-500/15 text-3xl">
                    🏆
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] uppercase tracking-wide text-amber-300/80">This week · {{ $leaderboard['week_label'] ?? '' }}</p>
                    @if ($winner)
                        <h2 class="font-display text-xl font-bold text-gray-100 truncate">
                            {{ $winner['name'] }} is winning
                        </h2>
                        <p class="text-sm font-semibold text-amber-300 nums">{{ $winner['points'] }} pts ahead</p>
                    @elseif ($tie)
                        <h2 class="font-display text-xl font-bold text-gray-100">Dead heat — all tied up 🔥</h2>
                    @else
                        <h2 class="font-display text-xl font-bold text-gray-100">No points yet</h2>
                        <p class="text-sm text-gray-500">Log a workout or a meal to take the lead.</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===== Head-to-head comparison card (full-width, rows compare both brothers) ===== --}}
        @php
            $ac0 = $accents[0];
            $ac1 = $accents[1];
            $cmp = fn ($x, $y) => is_numeric($x) && is_numeric($y) ? ($x <=> $y) : 0;
        @endphp
        <div class="overflow-hidden rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            {{-- Competitor header: two columns flanking a VS divider --}}
            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white/5 font-display text-lg font-bold {{ $ac0['text'] }}">
                        {{ strtoupper(substr($a['name'], 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <h3 class="truncate font-display text-sm font-bold text-gray-100">
                            {{ $a['name'] }}@if (($meId ?? null) === $a['id'])<span class="ml-1 text-[10px] uppercase tracking-wide text-gray-500">(you)</span>@endif
                        </h3>
                        @if ($a['top_streak'])
                            <p class="truncate text-[11px] text-orange-300/90">🔥 {{ $a['top_streak']->current_count }}d {{ str_replace('_', ' ', $a['top_streak']->kind) }}</p>
                        @else
                            <p class="text-[11px] text-gray-600">no streak</p>
                        @endif
                    </div>
                </div>

                <div class="px-1 text-center text-[11px] font-bold uppercase tracking-wide text-gray-600">vs</div>

                <div class="flex min-w-0 flex-row-reverse items-center gap-2.5 text-right">
                    <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white/5 font-display text-lg font-bold {{ $ac1['text'] }}">
                        {{ strtoupper(substr($b['name'], 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <h3 class="truncate font-display text-sm font-bold text-gray-100">
                            {{ $b['name'] }}@if (($meId ?? null) === $b['id'])<span class="ml-1 text-[10px] uppercase tracking-wide text-gray-500">(you)</span>@endif
                        </h3>
                        @if ($b['top_streak'])
                            <p class="truncate text-[11px] text-orange-300/90">🔥 {{ $b['top_streak']->current_count }}d {{ str_replace('_', ' ', $b['top_streak']->kind) }}</p>
                        @else
                            <p class="text-[11px] text-gray-600">no streak</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Comparison rows: A value | LABEL | B value --}}
            <div class="mt-4 space-y-2">
                @php
                    $w  = $cmp($a['workouts'], $b['workouts']);
                    $m  = $cmp($a['meals'], $b['meals']);
                    $sl = $cmp($a['avg_sleep'], $b['avg_sleep']);
                @endphp
                <x-duo-stat label="Workouts" :a="$a['workouts']" :b="$b['workouts']" :aWin="$w > 0" :bWin="$w < 0" />
                <x-duo-stat label="Meals logged" :a="$a['meals']" :b="$b['meals']" :aWin="$m > 0" :bWin="$m < 0" />
                <x-duo-stat label="Avg sleep"
                    :a="$a['avg_sleep'] !== null ? $a['avg_sleep'].'h' : '—'"
                    :b="$b['avg_sleep'] !== null ? $b['avg_sleep'].'h' : '—'"
                    :aWin="$sl > 0" :bWin="$sl < 0" />
                @php $me = auth()->user()->ensureProfile(); @endphp
                <x-duo-stat label="Latest weight"
                    :a="\App\Support\Units::weight($a['latest_weight'], $me)"
                    :b="\App\Support\Units::weight($b['latest_weight'], $me)" />
            </div>

            {{-- Streak chips per brother — wrap, never overflow --}}
            @if ($a['streaks']->isNotEmpty() || $b['streaks']->isNotEmpty())
                <div class="mt-4 grid grid-cols-2 gap-3 border-t border-white/5 pt-4">
                    @foreach ([[$a, $ac0], [$b, $ac1]] as [$p, $ac])
                        <div class="min-w-0">
                            @if ($p['streaks']->isNotEmpty())
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($p['streaks'] as $s)
                                        <span class="inline-flex max-w-full items-center gap-1 truncate rounded-full border px-2 py-0.5 text-[11px] {{ $ac['chip'] }}">
                                            🔥 <span class="nums">{{ $s->current_count }}</span>
                                            <span class="truncate opacity-70">{{ str_replace('_', ' ', $s->kind) }}</span>
                                            @if ($s->freezes_available > 0)
                                                <span class="opacity-50">❄️{{ $s->freezes_available }}</span>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-[11px] text-gray-600">no streaks</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ===== Physique race (full-width) ===== --}}
        @if ($comparison['hasPhysiqueRace'] ?? false)
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                <div class="mb-4 flex items-baseline gap-2">
                    <h3 class="font-display text-base font-bold text-gray-100">🏁 Race to the dream physique</h3>
                </div>
                <div class="space-y-4">
                    @foreach ($profiles as $i => $p)
                        @php
                            $ac = $accents[$i] ?? $accents[0];
                            $pct = $p['pct_to_goal'];
                        @endphp
                        <div>
                            <div class="mb-1.5 flex items-baseline justify-between text-sm">
                                <span class="font-medium {{ $ac['text'] }}">{{ $p['name'] }}</span>
                                <span class="font-display font-bold text-gray-300 nums">{{ $pct !== null ? round($pct).'%' : 'no analysis yet' }}</span>
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

        {{-- ===== Weekly leaderboard (full-width rows) ===== --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="mb-1 flex items-baseline justify-between gap-2">
                <h3 class="font-display text-base font-bold text-gray-100">📊 This week's leaderboard</h3>
            </div>
            <p class="mb-4 text-[11px] text-gray-500">workouts ×3 + meals + sleep nights</p>

            @php $maxPts = collect($scores)->max('points') ?: 1; @endphp
            <div class="space-y-3">
                @foreach ($scores as $rank => $row)
                    @php $ac = $accents[$rank] ?? $accents[0]; @endphp
                    <div class="flex items-center gap-3">
                        <div class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/5 font-display text-sm font-bold text-gray-300">
                            {{ $rank === 0 ? '🥇' : ($rank === 1 ? '🥈' : ($rank + 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="mb-1.5 flex items-baseline justify-between gap-2">
                                <span class="truncate font-medium text-gray-200">{{ $row['name'] }}</span>
                                <span class="shrink-0 text-gray-400">
                                    <span class="font-display font-bold text-gray-100 nums">{{ $row['points'] }}</span>
                                    <span class="text-[11px] uppercase tracking-wide text-gray-500"> pts</span>
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full {{ $ac['bar'] }}"
                                     style="width: {{ max(2, round(($row['points'] / $maxPts) * 100)) }}%"></div>
                            </div>
                            <p class="mt-1 text-[11px] text-gray-600 nums">{{ $row['workouts'] }}w · {{ $row['meals'] }}m · {{ $row['sleep_nights'] }}s</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        </div>
    @endif
</x-titan-layout>
