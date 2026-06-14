<x-titan-layout title="Sleep" subtitle="Duration, quality and stage trends">
    @php
        $stageColors = [
            'deep'  => ['label' => 'Deep',  'class' => 'bg-indigo-500'],
            'rem'   => ['label' => 'REM',   'class' => 'bg-cyan-400'],
            'light' => ['label' => 'Light', 'class' => 'bg-indigo-300/60'],
            'awake' => ['label' => 'Awake', 'class' => 'bg-gray-600'],
        ];
    @endphp

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-500/10 border border-rose-500/20 px-4 py-2 text-sm text-rose-300">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Top row: last-night summary + 7-day averages --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
        {{-- Last night --}}
        <div class="lg:col-span-2 rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-100">Last night</h3>
                @if ($latest)
                    <span class="text-xs text-gray-500">{{ $latest->slept_at->format('D, M j') }}</span>
                @endif
            </div>

            @if ($latest)
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <p class="text-xs text-gray-500">Duration</p>
                        <p class="text-2xl font-bold text-gray-100">{{ $latest->durationLabel() }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Quality</p>
                        <p class="text-2xl font-bold text-gray-100">{{ $latest->quality !== null ? $latest->quality : '—' }}<span class="text-sm text-gray-500">{{ $latest->quality !== null ? '/100' : '' }}</span></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Bedtime</p>
                        <p class="text-2xl font-bold text-gray-100">{{ $latest->bedtime ? \Illuminate\Support\Carbon::parse($latest->bedtime)->format('g:i A') : '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Wake</p>
                        <p class="text-2xl font-bold text-gray-100">{{ $latest->wake_time ? \Illuminate\Support\Carbon::parse($latest->wake_time)->format('g:i A') : '—' }}</p>
                    </div>
                </div>

                @if ($latest->hasStages())
                    @php
                        $stages = [
                            'deep'  => $latest->deep_min ?? 0,
                            'rem'   => $latest->rem_min ?? 0,
                            'light' => $latest->light_min ?? 0,
                            'awake' => $latest->awake_min ?? 0,
                        ];
                        $total = array_sum($stages) ?: 1;
                    @endphp
                    <div class="mt-5">
                        <p class="text-xs text-gray-500 mb-2">Sleep stages</p>
                        <div class="flex h-3 w-full overflow-hidden rounded-full bg-gray-800">
                            @foreach ($stages as $key => $min)
                                @if ($min > 0)
                                    <div class="{{ $stageColors[$key]['class'] }}" style="width: {{ round($min / $total * 100, 2) }}%" title="{{ $stageColors[$key]['label'] }}: {{ $min }}m"></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400">
                            @foreach ($stages as $key => $min)
                                @if ($min > 0)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full {{ $stageColors[$key]['class'] }}"></span>
                                        {{ $stageColors[$key]['label'] }} · {{ intdiv($min, 60) }}h {{ $min % 60 }}m
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500">No sleep logged yet. Add your first night below.</p>
            @endif
        </div>

        {{-- 7-day averages --}}
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <h3 class="font-semibold text-gray-100 mb-4">7-day average</h3>
            <div class="space-y-4">
                <div>
                    <p class="text-xs text-gray-500">Avg. duration</p>
                    <p class="text-2xl font-bold text-indigo-300">{{ $avgDurationLabel ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Avg. quality</p>
                    <p class="text-2xl font-bold text-cyan-300">{{ $avgQuality !== null ? $avgQuality.'/100' : '—' }}</p>
                </div>
                <p class="text-xs text-gray-600 pt-1">Based on your {{ $count7 }} most recent night{{ $count7 === 1 ? '' : 's' }}.</p>
            </div>
        </div>
    </div>

    {{-- 14-day trend --}}
    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5 mb-4">
        <h3 class="font-semibold text-gray-100 mb-4">14-day trend</h3>
        @if ($trend->count())
            <div class="relative h-72">
                <canvas id="sleepTrend"></canvas>
            </div>
        @else
            <p class="text-sm text-gray-500">Not enough data yet — log a few nights to see your trend.</p>
        @endif
    </div>

    {{-- Manual log form --}}
    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
        <h3 class="font-semibold text-gray-100 mb-4">Log sleep</h3>
        <form method="POST" action="/sleep" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">Night of</label>
                <input type="date" name="slept_at" value="{{ old('slept_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Hours</label>
                <input type="number" name="hours" min="0" max="24" value="{{ old('hours', 7) }}" required
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Minutes</label>
                <input type="number" name="minutes" min="0" max="59" value="{{ old('minutes', 0) }}"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Quality (1-100)</label>
                <input type="number" name="quality" min="1" max="100" value="{{ old('quality') }}" placeholder="optional"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Bedtime</label>
                <input type="time" name="bedtime" value="{{ old('bedtime') }}"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Wake time</label>
                <input type="time" name="wake_time" value="{{ old('wake_time') }}"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>

            <div x-data="{ open: false }" class="lg:col-span-4">
                <button type="button" @click="open = !open" class="text-xs text-indigo-400 hover:text-indigo-300">
                    <span x-show="!open">+ Add sleep stages (optional)</span>
                    <span x-show="open" x-cloak>− Hide sleep stages</span>
                </button>
                <div x-show="open" x-cloak class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Deep (min)</label>
                        <input type="number" name="deep_min" min="0" value="{{ old('deep_min') }}"
                               class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">REM (min)</label>
                        <input type="number" name="rem_min" min="0" value="{{ old('rem_min') }}"
                               class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Light (min)</label>
                        <input type="number" name="light_min" min="0" value="{{ old('light_min') }}"
                               class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Awake (min)</label>
                        <input type="number" name="awake_min" min="0" value="{{ old('awake_min') }}"
                               class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                    </div>
                </div>
            </div>

            <div class="lg:col-span-4">
                <label class="block text-xs text-gray-500 mb-1">Notes</label>
                <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Woke up twice, late caffeine…"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>

            <div class="lg:col-span-4">
                <button type="submit" class="rounded-lg bg-indigo-500 hover:bg-indigo-400 px-5 py-2 text-sm font-semibold text-white transition">
                    Save sleep
                </button>
            </div>
        </form>
    </div>

    @if ($trend->count())
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const ctx = document.getElementById('sleepTrend');
                if (!ctx || !window.Chart) return;
                const data = @json($trend);
                new Chart(ctx, {
                    data: {
                        labels: data.map(d => d.date),
                        datasets: [
                            {
                                type: 'bar',
                                label: 'Hours',
                                data: data.map(d => d.hours),
                                backgroundColor: 'rgba(99, 102, 241, 0.55)',
                                borderRadius: 4,
                                yAxisID: 'y',
                            },
                            {
                                type: 'line',
                                label: 'Quality',
                                data: data.map(d => d.quality),
                                borderColor: 'rgb(34, 211, 238)',
                                backgroundColor: 'rgb(34, 211, 238)',
                                tension: 0.3,
                                spanGaps: true,
                                yAxisID: 'y1',
                                pointRadius: 3,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { labels: { color: '#9ca3af' } } },
                        scales: {
                            x: { ticks: { color: '#6b7280' }, grid: { color: 'rgba(255,255,255,0.04)' } },
                            y: { position: 'left', title: { display: true, text: 'Hours', color: '#6b7280' }, ticks: { color: '#6b7280' }, grid: { color: 'rgba(255,255,255,0.04)' }, suggestedMin: 0, suggestedMax: 12 },
                            y1: { position: 'right', title: { display: true, text: 'Quality', color: '#6b7280' }, ticks: { color: '#6b7280' }, grid: { drawOnChartArea: false }, min: 0, max: 100 },
                        },
                    },
                });
            });
        </script>
    @endif
</x-titan-layout>
