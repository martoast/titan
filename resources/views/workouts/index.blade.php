<x-titan-layout title="Workouts" subtitle="Log sessions with adaptive progressive overload">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold">Training log</h2>
            <p class="text-gray-400 mt-1 text-sm">{{ $workouts->count() }} session{{ $workouts->count() === 1 ? '' : 's' }} recorded.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/workouts/live"
               class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-500 hover:from-indigo-400 hover:to-cyan-400 text-white text-sm font-semibold px-4 py-2 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Live session
            </a>
            <a href="/workouts/create"
               class="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 text-gray-100 text-sm font-semibold px-4 py-2 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Log manually
            </a>
        </div>
    </div>

    {{-- Weekly per-muscle-group volume summary --}}
    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-100">Weekly volume by muscle group</h3>
            <span class="text-xs text-gray-500">Working sets · last 7 days</span>
        </div>

        @if (count($weeklyVolume) === 0)
            <p class="text-sm text-gray-500">No working sets in the last 7 days. Log a workout to see your split.</p>
        @else
            @php $maxSets = max(array_map(fn ($r) => $r['sets'], $weeklyVolume)); @endphp
            {{-- Inline SVG horizontal bars — no external deps needed --}}
            <div class="space-y-3">
                @foreach ($weeklyVolume as $row)
                    <div class="flex items-center gap-3">
                        <span class="w-28 shrink-0 text-sm text-gray-400 capitalize">{{ $row['muscle_group'] }}</span>
                        <div class="flex-1 h-3 rounded-full bg-white/5 overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400"
                                 style="width: {{ $maxSets > 0 ? max(6, round($row['sets'] / $maxSets * 100)) : 0 }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-sm font-medium text-gray-200">{{ $row['sets'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- History --}}
    @if ($workouts->isEmpty())
        <div class="rounded-xl border border-dashed border-white/10 bg-gray-900/30 p-10 text-center">
            <p class="text-gray-400">No workouts logged yet.</p>
            <a href="/workouts/create" class="inline-block mt-3 text-indigo-400 hover:text-indigo-300 text-sm font-medium">Log your first session →</a>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($workouts as $workout)
                @php
                    $volume = $workout->totalVolume();
                    $tops = $workout->topSets();
                @endphp
                <a href="/workouts/{{ $workout->id }}"
                   class="group block rounded-xl border border-white/5 bg-gray-900/50 p-5 hover:border-indigo-500/40 hover:bg-gray-900 transition">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-gray-100 truncate">{{ $workout->name }}</h3>
                                @if ($workout->duration_min)
                                    <span class="text-xs text-gray-500">· {{ $workout->duration_min }} min</span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">{{ $workout->performed_at->format('D, M j Y · g:i A') }}</p>

                            @if ($tops->isNotEmpty())
                                <div class="flex flex-wrap gap-1.5 mt-3">
                                    @foreach ($tops as $t)
                                        <span class="inline-flex items-center rounded-md bg-white/5 px-2 py-0.5 text-xs text-gray-300">
                                            {{ $t['exercise'] }}: {{ $t['reps'] }} × {{ rtrim(rtrim(number_format($t['weight_kg'], 1), '0'), '.') }}kg
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-lg font-bold text-cyan-300">{{ number_format($volume) }}</p>
                            <p class="text-[11px] uppercase tracking-wide text-gray-500">kg volume</p>
                            <p class="text-xs text-gray-500 mt-1">{{ $workout->workingSetCount() }} sets</p>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-titan-layout>
