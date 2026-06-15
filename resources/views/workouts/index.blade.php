<x-titan-layout title="Workouts" subtitle="Log sessions with adaptive progressive overload">
    {{-- Primary actions — 2-up on mobile, no overflow --}}
    <div class="grid grid-cols-2 gap-3 mb-5">
        <a href="/workouts/live"
           class="flex items-center justify-center gap-2 h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 active:from-indigo-400 active:to-cyan-300 text-white text-sm font-semibold transition">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Live session
        </a>
        <a href="/workouts/create"
           class="flex items-center justify-center gap-2 h-12 rounded-xl bg-white/10 active:bg-white/20 text-gray-100 text-sm font-semibold transition">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Log manually
        </a>
    </div>
    <p class="text-gray-500 text-xs mb-5 nums">{{ $workouts->count() }} session{{ $workouts->count() === 1 ? '' : 's' }} recorded</p>

    @php
        // A band-detected session needs weights: reps came from the wrist, but every set is still 0 kg.
        $needsWeights = function ($w) {
            if (! str_starts_with((string) $w->updated_via, 'biosignal')) {
                return false;
            }
            $sets = $w->exercises->flatMap->sets;
            return $sets->isNotEmpty() && $sets->every(fn ($s) => (float) $s->weight_kg === 0.0);
        };
        $pending = $workouts->filter($needsWeights)->values();
    @endphp

    {{-- Nudge: band-detected sessions waiting on the load --}}
    @if ($pending->isNotEmpty())
        <a href="/workouts/{{ $pending->first()->id }}"
           class="flex items-center gap-3 rounded-2xl border border-cyan-500/20 bg-cyan-500/10 px-4 py-3 mb-5 active:bg-cyan-500/15 transition">
            <svg class="h-5 w-5 shrink-0 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5l11 11M5 9l2-2m10 10l2-2M3 11l2 2m14-2l-2 2"/></svg>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-cyan-100">{{ $pending->count() }} {{ $pending->count() === 1 ? 'session needs' : 'sessions need' }} weights</p>
                <p class="text-xs text-cyan-200/70">Your band counted the reps — add the load you lifted to track volume.</p>
            </div>
            <span class="text-cyan-300 text-sm shrink-0">→</span>
        </a>
    @endif

    {{-- Weekly per-muscle-group volume summary --}}
    <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 mb-5">
        <div class="flex items-start justify-between gap-3 mb-4">
            <h3 class="font-display font-bold text-gray-100">Weekly volume</h3>
            <span class="text-[11px] uppercase tracking-wide text-gray-500 shrink-0 text-right">Working sets<br>last 7 days</span>
        </div>

        @if (count($weeklyVolume) === 0)
            <p class="text-sm text-gray-500">No working sets in the last 7 days. Log a workout to see your split.</p>
        @else
            @php $maxSets = max(array_map(fn ($r) => $r['sets'], $weeklyVolume)); @endphp
            {{-- Inline horizontal bars — no external deps needed --}}
            <div class="space-y-3">
                @foreach ($weeklyVolume as $row)
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="w-20 sm:w-28 shrink-0 text-sm text-gray-400 capitalize truncate">{{ $row['muscle_group'] }}</span>
                        <div class="flex-1 min-w-0 h-3 rounded-full bg-white/5 overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400"
                                 style="width: {{ $maxSets > 0 ? max(6, round($row['sets'] / $maxSets * 100)) : 0 }}%"></div>
                        </div>
                        <span class="w-8 shrink-0 text-right font-display text-sm font-bold text-gray-100 nums">{{ $row['sets'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- History --}}
    @if ($workouts->isEmpty())
        <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.02] p-8 text-center">
            <p class="text-gray-400 text-sm">No workouts logged yet.</p>
            <a href="/workouts/create" class="inline-block mt-3 text-indigo-400 active:text-indigo-300 text-sm font-medium">Log your first session →</a>
        </div>
    @else
        <div class="space-y-3 md:space-y-4">
            @foreach ($workouts as $workout)
                @php
                    $volume = $workout->totalVolume();
                    $tops = $workout->topSets();
                @endphp
                <a href="/workouts/{{ $workout->id }}"
                   class="group block rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 active:border-indigo-500/40 active:bg-white/[0.05] transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <h3 class="font-semibold text-gray-100 truncate">{{ $workout->name }}</h3>
                                @if ($workout->duration_min)
                                    <span class="text-xs text-gray-500 shrink-0 nums">· {{ $workout->duration_min }} min</span>
                                @endif
                                @if ($needsWeights($workout))
                                    <span class="shrink-0 inline-flex items-center rounded-md bg-cyan-500/15 border border-cyan-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-cyan-300">Needs weights</span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5 nums">{{ $workout->performed_at->format('D, M j Y · g:i A') }}</p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-display text-xl font-bold text-cyan-300 nums leading-none">{{ number_format($volume) }}</p>
                            <p class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">kg volume</p>
                            <p class="text-xs text-gray-500 mt-1 nums">{{ $workout->workingSetCount() }} sets</p>
                        </div>
                    </div>

                    @if ($tops->isNotEmpty())
                        <div class="flex flex-wrap gap-1.5 mt-3">
                            @foreach ($tops as $t)
                                <span class="inline-flex items-center rounded-md bg-white/5 px-2 py-0.5 text-xs text-gray-300 nums">
                                    {{ $t['exercise'] }}: {{ $t['reps'] }} × {{ rtrim(rtrim(number_format($t['weight_kg'], 1), '0'), '.') }}kg
                                </span>
                            @endforeach
                        </div>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</x-titan-layout>
