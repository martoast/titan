<x-titan-layout title="Recovery" subtitle="HRV, resting HR, stress, soreness & readiness">
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-500/10 border border-rose-500/20 px-4 py-2 text-sm text-rose-300">
            {{ $errors->first() }}
        </div>
    @endif

    @php
        $readinessRing = $readiness ?? 0;
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

    {{-- Readiness hero --}}
    <div class="rounded-xl border border-white/5 bg-gradient-to-br from-gray-900/80 to-gray-900/40 p-6 mb-4">
        <div class="flex flex-col sm:flex-row items-center gap-6">
            <div class="relative h-36 w-36 shrink-0">
                <svg viewBox="0 0 120 120" class="h-36 w-36 -rotate-90">
                    <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10" />
                    <circle cx="60" cy="60" r="52" fill="none" stroke="{{ $ringStroke }}" stroke-width="10"
                            stroke-linecap="round" stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $circ - $dash }}" />
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-4xl font-black {{ $readinessTone }}">{{ $readiness ?? '—' }}</span>
                    <span class="text-[10px] uppercase tracking-wider text-gray-500">Readiness</span>
                </div>
            </div>
            <div class="text-center sm:text-left">
                <p class="text-sm uppercase tracking-wider text-gray-500">Today's readiness</p>
                <h2 class="text-2xl font-bold {{ $readinessTone }}">{{ $readinessLabel }}</h2>
                <p class="text-sm text-gray-400 mt-1 max-w-md">{{ $readinessNote }}</p>
            </div>
        </div>
    </div>

    {{-- Latest signal cards --}}
    @php
        $cards = [
            ['HRV',        $latest?->hrv_ms,     'ms',   'text-indigo-300'],
            ['Resting HR', $latest?->resting_hr, 'bpm',  'text-cyan-300'],
            ['Stress',     $latest?->stress,     '/10',  'text-amber-300'],
            ['Soreness',   $latest?->soreness,   '/10',  'text-orange-300'],
            ['Mood',       $latest?->mood,       '/10',  'text-emerald-300'],
            ['Energy',     $latest?->energy,     '/10',  'text-violet-300'],
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-4">
        @foreach ($cards as [$label, $value, $unit, $tone])
            <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="text-2xl font-bold {{ $tone }} mt-1">
                    {{ $value !== null ? $value : '—' }}<span class="text-xs text-gray-500">{{ $value !== null ? $unit : '' }}</span>
                </p>
            </div>
        @endforeach
    </div>

    @if ($latest)
        <p class="text-xs text-gray-600 mb-4">Latest entry: {{ $latest->logged_at->format('D, M j') }}@if ($lastSleep) · last sleep {{ $lastSleep->durationLabel() }} @endif</p>
    @endif

    {{-- Trends --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <h3 class="font-semibold text-gray-100 mb-4">HRV &amp; resting HR — 14 days</h3>
            @if ($trend->count())
                <div class="relative h-64"><canvas id="hrvTrend"></canvas></div>
            @else
                <p class="text-sm text-gray-500">Not enough data yet.</p>
            @endif
        </div>
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <h3 class="font-semibold text-gray-100 mb-4">Subjective signals — 14 days</h3>
            @if ($trend->count())
                <div class="relative h-64"><canvas id="subjTrend"></canvas></div>
            @else
                <p class="text-sm text-gray-500">Not enough data yet.</p>
            @endif
        </div>
    </div>

    {{-- Manual log form --}}
    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
        <h3 class="font-semibold text-gray-100 mb-4">Log recovery</h3>
        <form method="POST" action="/recovery" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">Date</label>
                <input type="date" name="logged_at" value="{{ old('logged_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">HRV (ms)</label>
                <input type="number" name="hrv_ms" min="1" max="400" value="{{ old('hrv_ms') }}" placeholder="optional"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Resting HR (bpm)</label>
                <input type="number" name="resting_hr" min="20" max="200" value="{{ old('resting_hr') }}" placeholder="optional"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Stress (1-10)</label>
                <input type="number" name="stress" min="1" max="10" value="{{ old('stress') }}" placeholder="optional"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Soreness (1-10)</label>
                <input type="number" name="soreness" min="1" max="10" value="{{ old('soreness') }}" placeholder="optional"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Mood (1-10)</label>
                <input type="number" name="mood" min="1" max="10" value="{{ old('mood') }}" placeholder="optional"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Energy (1-10)</label>
                <input type="number" name="energy" min="1" max="10" value="{{ old('energy') }}" placeholder="optional"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <label class="block text-xs text-gray-500 mb-1">Notes</label>
                <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Felt drained, big leg day yesterday…"
                       class="w-full rounded-lg bg-gray-800 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div class="lg:col-span-4">
                <button type="submit" class="rounded-lg bg-indigo-500 hover:bg-indigo-400 px-5 py-2 text-sm font-semibold text-white transition">
                    Save recovery
                </button>
            </div>
        </form>
    </div>

    @if ($trend->count())
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (!window.Chart) return;
                const data = @json($trend);
                const labels = data.map(d => d.date);
                const axis = (extra = {}) => Object.assign({ ticks: { color: '#6b7280' }, grid: { color: 'rgba(255,255,255,0.04)' } }, extra);

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
                        plugins: { legend: { labels: { color: '#9ca3af' } } },
                        scales: {
                            x: axis(),
                            y: axis({ position: 'left' }),
                            y1: { position: 'right', ticks: { color: '#6b7280' }, grid: { drawOnChartArea: false } },
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
                        plugins: { legend: { labels: { color: '#9ca3af' } } },
                        scales: { x: axis(), y: axis({ min: 0, max: 10 }) },
                    },
                });
            });
        </script>
    @endif
</x-titan-layout>
