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
        <div class="mb-4 rounded-xl bg-rose-500/10 border border-rose-500/20 px-4 py-3 text-sm text-rose-300">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="space-y-4 md:space-y-5">
        {{-- Last-night summary --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-display font-bold text-gray-100">Last night</h3>
                @if ($latest)
                    <div class="flex items-center gap-2">
                        @if ($fromWearable)
                            <span class="inline-flex items-center gap-1 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-300">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.55a11 11 0 0114 0M8.5 16.05a6 6 0 017 0M2 9.05a16 16 0 0120 0M12 20h.01"/></svg>
                                Wearable
                            </span>
                        @endif
                        <span class="text-xs text-gray-500 nums">{{ $latest->slept_at->format('D, M j') }}</span>
                    </div>
                @endif
            </div>

            @if ($latest)
                {{-- Hero duration --}}
                <div class="mb-5">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Duration</div>
                    <div class="font-display text-5xl font-bold nums text-gray-100 leading-none mt-1">{{ $latest->durationLabel() }}</div>
                </div>

                {{-- Secondary stats --}}
                <div class="grid grid-cols-3 gap-3 md:gap-4">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Quality</div>
                        <div class="font-display text-xl font-bold nums text-gray-100">{{ $latest->quality !== null ? $latest->quality : '—' }}<span class="text-gray-500 text-sm font-normal">{{ $latest->quality !== null ? '/100' : '' }}</span></div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Bedtime</div>
                        <div class="font-display text-xl font-bold nums text-gray-100">{{ $latest->bedtime ? \Illuminate\Support\Carbon::parse($latest->bedtime)->format('g:i A') : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Wake</div>
                        <div class="font-display text-xl font-bold nums text-gray-100">{{ $latest->wake_time ? \Illuminate\Support\Carbon::parse($latest->wake_time)->format('g:i A') : '—' }}</div>
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
                        <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-2">Sleep stages</div>
                        <div class="flex h-3 w-full overflow-hidden rounded-full bg-white/5">
                            @foreach ($stages as $key => $min)
                                @if ($min > 0)
                                    <div class="{{ $stageColors[$key]['class'] }}" style="width: {{ round($min / $total * 100, 2) }}%" title="{{ $stageColors[$key]['label'] }}: {{ $min }}m"></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-gray-400">
                            @foreach ($stages as $key => $min)
                                @if ($min > 0)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full {{ $stageColors[$key]['class'] }}"></span>
                                        {{ $stageColors[$key]['label'] }} <span class="nums text-gray-300">{{ intdiv($min, 60) }}h {{ $min % 60 }}m</span>
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
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-4">7-day average</h3>
            <div class="grid grid-cols-2 gap-3 md:gap-4">
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Avg. duration</div>
                    <div class="font-display text-2xl font-bold nums text-indigo-300">{{ $avgDurationLabel ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Avg. quality</div>
                    <div class="font-display text-2xl font-bold nums text-cyan-300">{{ $avgQuality !== null ? $avgQuality.'/100' : '—' }}</div>
                </div>
            </div>
            <p class="text-xs text-gray-600 mt-3">Based on your {{ $count7 }} most recent night{{ $count7 === 1 ? '' : 's' }}.</p>
        </div>

        {{-- Sleep regularity (SRI) — consistency of timing; predicts mortality more than duration --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-display font-bold text-gray-100">Sleep regularity</h3>
                    <p class="text-xs text-gray-500 mt-0.5">How consistent your sleep & wake times are — one of the strongest sleep-longevity signals.</p>
                </div>
                @if ($regularity)
                    @php
                        $sriTone = match ($regularity['band']) {
                            'excellent' => 'text-emerald-300', 'good' => 'text-cyan-300',
                            'fair' => 'text-amber-300', default => 'text-orange-300',
                        };
                    @endphp
                    <div class="text-right shrink-0">
                        <div class="font-display text-4xl font-black nums {{ $sriTone }} leading-none">{{ $regularity['sri'] }}</div>
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 mt-1">SRI / 100</div>
                    </div>
                @endif
            </div>

            @if ($regularity)
                <div class="mt-4">
                    {{-- 0..100 scale bar (SRI can be negative; clamp the marker for display) --}}
                    <div class="relative h-2 rounded-full bg-gradient-to-r from-orange-500/40 via-amber-400/40 to-emerald-400/60">
                        <div class="absolute -top-1 h-4 w-1 rounded-full bg-white shadow" style="left: {{ max(0, min(100, $regularity['sri'])) }}%"></div>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-sm font-semibold {{ $sriTone }}">{{ $regularity['label'] }}</span>
                        <span class="text-xs text-gray-600 nums">{{ $regularity['nights'] }} timed nights</span>
                    </div>
                    @if ($regularity['sri'] < 70)
                        <p class="mt-2 text-xs text-gray-400">Going to bed and waking within a tighter window — even on weekends — is the single biggest lever here.</p>
                    @endif
                </div>
            @else
                <p class="mt-3 text-sm text-gray-500">Log (or sync) at least {{ \App\Support\SleepRegularity::MIN_NIGHTS }} nights <span class="text-gray-600">with bedtime + wake time</span> to see your regularity score.</p>
            @endif
        </div>

        {{-- Circadian rest-activity rhythm — day/night contrast; blunted rhythm predicts mortality --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-display font-bold text-gray-100">Circadian rhythm</h3>
                    <p class="text-xs text-gray-500 mt-0.5">How clearly your active days separate from your restful nights.</p>
                </div>
                @if ($circadian)
                    @php
                        $raTone = match ($circadian['band']) {
                            'excellent' => 'text-emerald-300', 'good' => 'text-cyan-300',
                            'fair' => 'text-amber-300', default => 'text-orange-300',
                        };
                    @endphp
                    <div class="text-right shrink-0">
                        <div class="font-display text-4xl font-black nums {{ $raTone }} leading-none">{{ $circadian['ra'] }}</div>
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 mt-1">rhythm / 100</div>
                    </div>
                @endif
            </div>

            @if ($circadian)
                <div class="mt-4">
                    <div class="relative h-2 rounded-full bg-gradient-to-r from-orange-500/40 via-amber-400/40 to-emerald-400/60">
                        <div class="absolute -top-1 h-4 w-1 rounded-full bg-white shadow" style="left: {{ max(0, min(100, $circadian['ra'])) }}%"></div>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-sm font-semibold {{ $raTone }}">{{ $circadian['label'] }}</span>
                        <span class="text-xs text-gray-600 nums">{{ $circadian['days'] }} days</span>
                    </div>
                    @php
                        $fmtHour = fn ($h) => \Illuminate\Support\Carbon::today()->setTime($h, 0)->format('g A');
                    @endphp
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl border border-white/5 bg-white/[0.02] px-2 py-2">
                            <div class="text-[10px] uppercase tracking-wide text-gray-500">Stability</div>
                            <div class="font-display text-lg font-bold nums text-gray-200 leading-none mt-0.5">{{ number_format($circadian['is'], 2) }}</div>
                        </div>
                        <div class="rounded-xl border border-white/5 bg-white/[0.02] px-2 py-2">
                            <div class="text-[10px] uppercase tracking-wide text-gray-500">Most active</div>
                            <div class="font-display text-lg font-bold nums text-gray-200 leading-none mt-0.5">{{ $fmtHour($circadian['m10_onset']) }}</div>
                        </div>
                        <div class="rounded-xl border border-white/5 bg-white/[0.02] px-2 py-2">
                            <div class="text-[10px] uppercase tracking-wide text-gray-500">Deep rest</div>
                            <div class="font-display text-lg font-bold nums text-gray-200 leading-none mt-0.5">{{ $fmtHour($circadian['l5_onset']) }}</div>
                        </div>
                    </div>
                    @if ($circadian['ra'] < 75)
                        <p class="mt-3 text-xs text-gray-400">Brighter, more active days and darker, stiller nights sharpen this rhythm — a strong day/night contrast is linked to longer, healthier life.</p>
                    @endif
                </div>
            @else
                <p class="mt-3 text-sm text-gray-500">Wear your band across {{ \App\Support\CircadianRhythm::MIN_DAYS }}+ full days to see your rest-activity rhythm.</p>
            @endif
        </div>

        {{-- 14-day trend --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-4">14-day trend</h3>
            @if ($trend->count())
                <div class="relative h-44 md:h-56">
                    <canvas id="sleepTrend" class="w-full"></canvas>
                </div>
            @else
                <p class="text-sm text-gray-500">Not enough data yet — log a few nights to see your trend.</p>
            @endif
        </div>

        {{-- Manual log form — deferred behind a tap; the band logs nights automatically --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5" x-data="{ logOpen: {{ $errors->any() ? 'true' : 'false' }} }">
            <button type="button" @click="logOpen = !logOpen" class="flex w-full items-center justify-between gap-3 text-left">
                <span class="font-display font-bold text-gray-100">Log sleep manually</span>
                <svg class="h-5 w-5 shrink-0 text-gray-500 transition" :class="logOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <form method="POST" action="/sleep" x-show="logOpen" x-collapse x-cloak class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                @csrf
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">Night of</label>
                    <input type="date" name="slept_at" value="{{ old('slept_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Hours</label>
                    <input type="number" name="hours" min="0" max="24" value="{{ old('hours', 7) }}" required
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Minutes</label>
                    <input type="number" name="minutes" min="0" max="59" value="{{ old('minutes', 0) }}"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Quality (1-100)</label>
                    <input type="number" name="quality" min="1" max="100" value="{{ old('quality') }}" placeholder="optional"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Bedtime</label>
                    <input type="time" name="bedtime" value="{{ old('bedtime') }}"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Wake time</label>
                    <input type="time" name="wake_time" value="{{ old('wake_time') }}"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </div>

                <div x-data="{ open: false }" class="sm:col-span-2">
                    <button type="button" @click="open = !open" class="text-xs font-medium text-indigo-400 active:text-indigo-300">
                        <span x-show="!open">+ Add sleep stages (optional)</span>
                        <span x-show="open" x-cloak>− Hide sleep stages</span>
                    </button>
                    <div x-show="open" x-cloak class="grid grid-cols-2 gap-3 md:gap-4 mt-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Deep (min)</label>
                            <input type="number" name="deep_min" min="0" value="{{ old('deep_min') }}"
                                   class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums focus:border-indigo-500 focus:ring-0">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">REM (min)</label>
                            <input type="number" name="rem_min" min="0" value="{{ old('rem_min') }}"
                                   class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums focus:border-indigo-500 focus:ring-0">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Light (min)</label>
                            <input type="number" name="light_min" min="0" value="{{ old('light_min') }}"
                                   class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums focus:border-indigo-500 focus:ring-0">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Awake (min)</label>
                            <input type="number" name="awake_min" min="0" value="{{ old('awake_min') }}"
                                   class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums focus:border-indigo-500 focus:ring-0">
                        </div>
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Woke up twice, late caffeine…"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="w-full md:w-auto h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-6 font-semibold text-white active:opacity-90 transition">
                        Save sleep
                    </button>
                </div>
            </form>
        </div>
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
                                pointRadius: 2,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { labels: { color: '#9ca3af', boxWidth: 10, font: { size: 11 } } } },
                        scales: {
                            x: { ticks: { color: '#6b7280', font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 }, grid: { display: false } },
                            y: { position: 'left', ticks: { color: '#6b7280', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' }, suggestedMin: 0, suggestedMax: 12 },
                            y1: { position: 'right', ticks: { color: '#6b7280', font: { size: 10 } }, grid: { drawOnChartArea: false }, min: 0, max: 100 },
                        },
                    },
                });
            });
        </script>
    @endif
</x-titan-layout>
