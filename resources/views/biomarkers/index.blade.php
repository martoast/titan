<x-titan-layout title="Bloodwork" subtitle="Track your markers over time and catch problems before they're problems">

    <div class="space-y-4 md:space-y-5">

    {{-- Flash + validation feedback --}}
    @if (session('status'))
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-300">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Parsed-upload confirmation --}}
    @if ($parsed)
        <div class="rounded-2xl border border-indigo-500/30 bg-indigo-500/5 p-4 md:p-5">
            <h3 class="font-display font-bold text-indigo-200 mb-3">Imported from your lab report</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach ($parsed as $row)
                    <div class="flex items-center justify-between gap-2 rounded-xl bg-gray-900/60 px-3 py-2 text-sm">
                        <span class="text-gray-300 min-w-0 truncate">{{ $row['label'] }}</span>
                        <span class="flex items-center gap-2 shrink-0">
                            <span class="text-gray-100 font-medium nums">{{ rtrim(rtrim(number_format($row['value'], 2, '.', ''), '0'), '.') }} {{ $row['unit'] }}</span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide {{ \App\Support\Biomarkers::flagClasses($row['flag']) }}">{{ $row['flag'] }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-3">Saved to your record. The cards below now reflect these values.</p>
        </div>
    @endif

    {{-- ===================== Action panels ===================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Upload a lab PDF --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-indigo-500/15 p-2 text-indigo-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.9A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                </div>
                <div class="min-w-0">
                    <h3 class="font-display font-bold text-gray-100">Upload a lab report</h3>
                    <p class="text-sm text-gray-400 mt-0.5">Drop a bloodwork PDF — AI extracts every marker, flags what's off, and files a summary in your brain.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('biomarkers.upload') }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf
                <x-upload-zone kind="file" name="report" required accept=".pdf,.png,.jpg,.jpeg,.txt"
                               label="Tap to add your lab report" hint="PDF, image, or text · max 20 MB" />
                <button type="submit"
                        class="w-full h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-4 text-base font-semibold text-white active:opacity-90 transition">
                    Extract markers
                </button>
                <p class="text-xs text-gray-600">PDF, image, or text · max 20 MB. Text-based PDFs parse best.</p>
            </form>
        </div>

        {{-- Manual add --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-cyan-500/15 p-2 text-cyan-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                </div>
                <div class="min-w-0">
                    <h3 class="font-display font-bold text-gray-100">Add a reading</h3>
                    <p class="text-sm text-gray-400 mt-0.5">Log a single marker by hand.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('biomarkers.store') }}" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3"
                  x-data="{ marker: '{{ old('marker', array_key_first($catalog)) }}' }">
                @csrf
                <label class="sm:col-span-2 block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Marker</span>
                    <select name="marker" x-model="marker"
                            class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                        @foreach ($catalog as $key => $def)
                            <option value="{{ $key }}">{{ $def['label'] }} ({{ $def['unit'] }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Value</span>
                    <input type="number" step="any" name="value" value="{{ old('value') }}" required
                           class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </label>
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Date</span>
                    <input type="date" name="taken_at" value="{{ old('taken_at', now()->toDateString()) }}" required
                           class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </label>
                <label class="sm:col-span-2 block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Note (optional)</span>
                    <input type="text" name="note" value="{{ old('note') }}" maxlength="500"
                           class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </label>
                <button type="submit"
                        class="sm:col-span-2 w-full h-12 rounded-xl bg-cyan-500/90 hover:bg-cyan-400 px-4 text-base font-semibold text-white active:opacity-90 transition">
                    Save reading
                </button>
            </form>
        </div>
    </div>

    {{-- ===================== Marker cards ===================== --}}
    <div>
        <h3 class="font-display text-sm font-bold text-gray-400 uppercase tracking-wide mb-3">Your markers</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
            @foreach ($cards as $card)
                @php
                    $latest = $card['latest'];
                    $def = $card['def'];
                    $points = $card['points'];
                @endphp
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 flex flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h4 class="font-medium text-gray-100 truncate">{{ $def['label'] }}</h4>
                            <p class="text-[11px] uppercase tracking-wide text-gray-500 mt-0.5">Optimal {{ $card['range'] ?: '—' }}</p>
                        </div>
                        @if ($latest)
                            <span class="shrink-0 px-2 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide {{ \App\Support\Biomarkers::flagClasses($latest->flag) }}">{{ $latest->flag }}</span>
                        @endif
                    </div>

                    <div class="mt-3 flex items-baseline gap-1.5">
                        @if ($latest)
                            <span class="font-display text-2xl font-bold text-gray-100 nums leading-none">{{ rtrim(rtrim(number_format((float) $latest->value, 2, '.', ''), '0'), '.') }}</span>
                            <span class="text-xs text-gray-500">{{ $latest->unit ?: $def['unit'] }}</span>
                        @else
                            <span class="text-sm text-gray-600">No readings yet</span>
                        @endif
                    </div>

                    @if ($latest)
                        <p class="text-[11px] text-gray-500 mt-1 nums">{{ $latest->taken_at->format('M j, Y') }} · {{ $latest->source === 'lab_upload' ? 'lab upload' : 'manual' }}</p>
                    @endif

                    {{-- Trend: Chart.js sparkline when ≥2 points --}}
                    @if (count($points) >= 2)
                        <div class="mt-3 h-24" x-data x-init="$nextTick(() => window.titanSpark && window.titanSpark($refs.c, @js($points)))">
                            <canvas x-ref="c" class="w-full"></canvas>
                        </div>
                    @elseif (count($points) === 1)
                        <p class="text-[11px] text-gray-500 mt-3">Add another reading to see a trend.</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    </div>

    {{-- Chart.js via CDN, plus a tiny helper that draws a flag-coloured trend line --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        window.titanSpark = function (canvas, points) {
            if (!canvas || !window.Chart || !points || points.length < 2) return;
            const last = points[points.length - 1];
            const color = ({ optimal: '#34d399', normal: '#38bdf8', high: '#fb7185', low: '#fbbf24' })[last.flag] || '#818cf8';
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: points.map(p => p.date),
                    datasets: [{
                        data: points.map(p => p.value),
                        borderColor: color,
                        backgroundColor: color + '22',
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        tension: 0.35,
                        fill: true,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: {
                        displayColors: false,
                        callbacks: { title: i => i[0].label, label: i => i.formattedValue },
                    } },
                    scales: { x: { display: false }, y: { display: false } },
                    elements: { line: { capBezierPoints: true } },
                },
            });
        };
    </script>
</x-titan-layout>
