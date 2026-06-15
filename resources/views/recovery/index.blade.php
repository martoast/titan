<x-titan-layout title="Recovery" subtitle="HRV, resting HR, stress, soreness & readiness">
    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-rose-500/10 border border-rose-500/20 px-4 py-3 text-sm text-rose-300">
            {{ $errors->first() }}
        </div>
    @endif

    @php
        $readinessTone = match (true) {
            $readiness === null => 'text-gray-400',
            $readiness >= 80 => 'text-emerald-300',
            $readiness >= 60 => 'text-cyan-300',
            $readiness >= 40 => 'text-amber-300',
            $readiness >= 20 => 'text-orange-300',
            default => 'text-rose-300',
        };
        $ringStroke = match (true) {
            $readiness === null => '#6b7280',
            $readiness >= 80 => '#6ee7b7',
            $readiness >= 60 => '#67e8f9',
            $readiness >= 40 => '#fcd34d',
            $readiness >= 20 => '#fdba74',
            default => '#fda4af',
        };
        $circ = 2 * M_PI * 52;
        $dash = $readiness !== null ? $circ * ($readiness / 100) : 0;
    @endphp

    <div class="space-y-4 md:space-y-5">
        {{-- Readiness hero --}}
        <div class="rounded-2xl border border-white/5 bg-gradient-to-br from-gray-900/80 to-gray-900/40 p-6">
            <div class="flex flex-col items-center text-center gap-5">
                <div class="relative h-40 w-40 shrink-0">
                    <svg viewBox="0 0 120 120" class="h-40 w-40 -rotate-90">
                        <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10" />
                        <circle cx="60" cy="60" r="52" fill="none" stroke="{{ $ringStroke }}" stroke-width="10"
                                stroke-linecap="round" stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $circ - $dash }}" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-5xl font-black nums {{ $readinessTone }} leading-none">{{ $readiness ?? '—' }}</span>
                        <span class="text-[10px] uppercase tracking-wider text-gray-500 mt-1">Readiness</span>
                    </div>
                </div>
                <div>
                    <p class="text-[11px] uppercase tracking-wider text-gray-500">Today's readiness</p>
                    <h2 class="font-display text-2xl font-bold {{ $readinessTone }} mt-0.5">{{ $readinessLabel }}</h2>
                    <p class="text-sm text-gray-400 mt-1.5 max-w-xs mx-auto">{{ $readinessNote }}</p>

                    {{-- Source indicator: whole-night sealed > per-window > manual --}}
                    @if ($fromWearable)
                        <div class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-medium text-emerald-300">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.55a11 11 0 0114 0M8.5 16.05a6 6 0 017 0M2 9.05a16 16 0 0120 0M12 20h.01"/></svg>
                            {{ $sealed ? 'From wearable · whole-night HRV' : 'From wearable · provisional (sealing tonight)' }}
                        </div>
                    @endif
                </div>

                {{-- Component breakdown chips (HRV / RHR / Sleep) --}}
                @if (!empty($readinessComponents))
                    <div class="flex flex-wrap justify-center gap-2">
                        @php
                            $compMeta = ['hrv' => 'HRV', 'rhr' => 'Resting HR', 'sleep' => 'Sleep'];
                        @endphp
                        @foreach ($readinessComponents as $key => $val)
                            <div class="rounded-xl border border-white/5 bg-white/[0.03] px-3 py-2 text-center min-w-[5rem]">
                                <div class="text-[10px] uppercase tracking-wide text-gray-500">{{ $compMeta[$key] ?? ucfirst($key) }}</div>
                                <div class="font-display text-lg font-bold nums text-gray-200 leading-none mt-0.5">{{ $val }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Objective signals — HRV (RMSSD) + RHR, lead with a vs-baseline read --}}
        @php
            // Whole-night HRV (RMSSD) vs the 14-day baseline. A positive delta = recovered.
            $hrvDelta = ($latest?->hrv_ms !== null && $hrvBaseline) ? $latest->hrv_ms - $hrvBaseline : null;
            // Lower resting HR is better, so an upward arrow on a drop reads as good.
            $rhrDelta = ($latest?->resting_hr !== null && $rhrBaseline) ? $latest->resting_hr - $rhrBaseline : null;
        @endphp
        <div class="grid grid-cols-2 gap-3 md:gap-4">
            {{-- HRV (RMSSD) --}}
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="flex items-center justify-between">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">HRV · RMSSD</div>
                    @if ($sealed)
                        <span class="text-[10px] text-emerald-400/80" title="Computed over the whole night">night</span>
                    @endif
                </div>
                <div class="font-display text-3xl font-bold nums text-indigo-300 mt-1 leading-none">
                    {{ $latest?->hrv_ms !== null ? $latest->hrv_ms : '—' }}<span class="text-gray-500 text-base font-normal">{{ $latest?->hrv_ms !== null ? 'ms' : '' }}</span>
                </div>
                @if ($hrvDelta !== null)
                    <div class="mt-1.5 text-xs nums {{ $hrvDelta >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ $hrvDelta >= 0 ? '▲' : '▼' }} {{ abs($hrvDelta) }} ms <span class="text-gray-600">vs 14d avg</span>
                    </div>
                @elseif ($hrvBaseline)
                    <div class="mt-1.5 text-xs text-gray-600 nums">14d avg {{ $hrvBaseline }} ms</div>
                @endif
            </div>

            {{-- Resting HR --}}
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Resting HR</div>
                <div class="font-display text-3xl font-bold nums text-cyan-300 mt-1 leading-none">
                    {{ $latest?->resting_hr !== null ? $latest->resting_hr : '—' }}<span class="text-gray-500 text-base font-normal">{{ $latest?->resting_hr !== null ? 'bpm' : '' }}</span>
                </div>
                @if ($rhrDelta !== null)
                    <div class="mt-1.5 text-xs nums {{ $rhrDelta <= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ $rhrDelta <= 0 ? '▼' : '▲' }} {{ abs($rhrDelta) }} bpm <span class="text-gray-600">vs 14d avg</span>
                    </div>
                @elseif ($rhrBaseline)
                    <div class="mt-1.5 text-xs text-gray-600 nums">14d avg {{ $rhrBaseline }} bpm</div>
                @endif
            </div>
        </div>

        {{-- Subjective self-ratings --}}
        @php
            $cards = [
                ['Stress',   $latest?->stress,   'text-amber-300'],
                ['Soreness', $latest?->soreness, 'text-orange-300'],
                ['Mood',     $latest?->mood,     'text-emerald-300'],
                ['Energy',   $latest?->energy,   'text-violet-300'],
            ];
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 md:gap-4">
            @foreach ($cards as [$label, $value, $tone])
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="font-display text-2xl font-bold nums {{ $tone }} mt-1">
                        {{ $value !== null ? $value : '—' }}<span class="text-gray-500 text-sm font-normal">{{ $value !== null ? '/10' : '' }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($latest)
            <p class="text-xs text-gray-600 -mt-1">
                Latest entry: {{ $latest->logged_at->format('D, M j') }}@if ($lastSleep) · last sleep {{ $lastSleep->durationLabel() }} @endif
                @if ($fromWearable) · <span class="text-emerald-400/70">wearable</span> @elseif ($latest->updated_via) · {{ ucfirst(explode(':', $latest->updated_via)[0]) }} @endif
            </p>
        @endif

        {{-- Trends --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-4">HRV (RMSSD) &amp; resting HR — 14 days</h3>
            @if ($trend->count())
                <div class="relative h-44 md:h-56"><canvas id="hrvTrend" class="w-full"></canvas></div>
            @else
                <p class="text-sm text-gray-500">Not enough data yet.</p>
            @endif
        </div>
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-4">Subjective signals — 14 days</h3>
            @if ($trend->count())
                <div class="relative h-44 md:h-56"><canvas id="subjTrend" class="w-full"></canvas></div>
            @else
                <p class="text-sm text-gray-500">Not enough data yet.</p>
            @endif
        </div>

        {{-- Manual log form --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-4">Log recovery</h3>
            <form method="POST" action="/recovery" class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                @csrf
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">Date</label>
                    <input type="date" name="logged_at" value="{{ old('logged_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">HRV (ms)</label>
                    <input type="number" name="hrv_ms" min="1" max="400" value="{{ old('hrv_ms') }}" placeholder="optional"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Resting HR (bpm)</label>
                    <input type="number" name="resting_hr" min="20" max="200" value="{{ old('resting_hr') }}" placeholder="optional"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stress (1-10)</label>
                    <input type="number" name="stress" min="1" max="10" value="{{ old('stress') }}" placeholder="optional"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Soreness (1-10)</label>
                    <input type="number" name="soreness" min="1" max="10" value="{{ old('soreness') }}" placeholder="optional"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Mood (1-10)</label>
                    <input type="number" name="mood" min="1" max="10" value="{{ old('mood') }}" placeholder="optional"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Energy (1-10)</label>
                    <input type="number" name="energy" min="1" max="10" value="{{ old('energy') }}" placeholder="optional"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 nums placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Felt drained, big leg day yesterday…"
                           class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="w-full md:w-auto h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-6 font-semibold text-white active:opacity-90 transition">
                        Save recovery
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($trend->count())
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (!window.Chart) return;
                const data = @json($trend);
                const labels = data.map(d => d.date);
                const legend = { labels: { color: '#9ca3af', boxWidth: 10, font: { size: 11 } } };
                const xAxis = { ticks: { color: '#6b7280', font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 }, grid: { display: false } };
                const yAxis = (extra = {}) => Object.assign({ ticks: { color: '#6b7280', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } }, extra);

                const hrv = document.getElementById('hrvTrend');
                if (hrv) new Chart(hrv, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            { label: 'HRV (ms)', data: data.map(d => d.hrv), borderColor: 'rgb(129,140,248)', backgroundColor: 'rgba(129,140,248,0.15)', tension: 0.3, spanGaps: true, yAxisID: 'y', fill: true, pointRadius: 2 },
                            { label: 'Resting HR (bpm)', data: data.map(d => d.rhr), borderColor: 'rgb(34,211,238)', backgroundColor: 'rgb(34,211,238)', tension: 0.3, spanGaps: true, yAxisID: 'y1', pointRadius: 2 },
                        ],
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend },
                        scales: {
                            x: xAxis,
                            y: yAxis({ position: 'left' }),
                            y1: { position: 'right', ticks: { color: '#6b7280', font: { size: 10 } }, grid: { drawOnChartArea: false } },
                        },
                    },
                });

                const subj = document.getElementById('subjTrend');
                if (subj) new Chart(subj, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            { label: 'Stress', data: data.map(d => d.stress), borderColor: 'rgb(252,211,77)', tension: 0.3, spanGaps: true, pointRadius: 2 },
                            { label: 'Soreness', data: data.map(d => d.soreness), borderColor: 'rgb(253,186,116)', tension: 0.3, spanGaps: true, pointRadius: 2 },
                            { label: 'Mood', data: data.map(d => d.mood), borderColor: 'rgb(110,231,183)', tension: 0.3, spanGaps: true, pointRadius: 2 },
                            { label: 'Energy', data: data.map(d => d.energy), borderColor: 'rgb(167,139,250)', tension: 0.3, spanGaps: true, pointRadius: 2 },
                        ],
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend },
                        scales: { x: xAxis, y: yAxis({ min: 0, max: 10 }) },
                    },
                });
            });
        </script>
    @endif
</x-titan-layout>
