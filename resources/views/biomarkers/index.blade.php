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

    {{-- Biological-age clock — turns a blood upload into the mortality-validated PhenoAge --}}
    @php $total = count($phenoStatus); $pa = $bioAge['pheno_age'] ?? null; @endphp
    <div class="rounded-2xl border border-cyan-500/20 bg-gradient-to-b from-cyan-500/[0.07] to-transparent p-4 md:p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[11px] uppercase tracking-wider text-cyan-300/80 font-semibold">Biological age clock · PhenoAge</p>
                @if ($pa !== null)
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="font-display text-4xl font-black nums text-cyan-200 leading-none">{{ number_format($pa, 0) }}</span>
                        <span class="text-sm text-gray-500">blood biological age</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-300">Mortality-validated from your 9 markers. See the full picture on <a href="{{ route('recovery.index') }}" class="text-cyan-300 underline decoration-cyan-500/40">Recovery</a>.</p>
                @else
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="font-display text-4xl font-black nums text-cyan-200 leading-none">{{ $phenoHave }}<span class="text-gray-600 text-2xl">/{{ $total }}</span></span>
                        <span class="text-sm text-gray-500">markers to unlock</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-300">Add the missing markers below (one blood panel covers them all) to unlock a mortality-validated biological age.</p>
                @endif
            </div>
            <div class="shrink-0 text-right">
                <div class="text-[11px] text-gray-500">{{ round($phenoHave / max($total, 1) * 100) }}% complete</div>
                <div class="mt-1.5 h-1.5 w-24 rounded-full bg-white/10 overflow-hidden">
                    <div class="h-full rounded-full bg-cyan-400" style="width: {{ round($phenoHave / max($total, 1) * 100) }}%"></div>
                </div>
            </div>
        </div>
        {{-- 9-marker checklist --}}
        <div class="mt-3 flex flex-wrap gap-1.5">
            @foreach ($phenoStatus as $m)
                <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] {{ $m['have'] ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-200' : 'border-white/10 bg-white/[0.02] text-gray-500' }}">
                    @if ($m['have'])<span class="text-emerald-400">✓</span>@else<span class="text-gray-600">＋</span>@endif
                    {{ $m['label'] }}@if ($m['have'])<span class="text-emerald-400/60 nums"> {{ $m['value'] }}</span>@endif
                </span>
            @endforeach
        </div>
        <p class="mt-2.5 text-[10px] text-gray-600 leading-relaxed">PhenoAge (Levine 2018) — a wellness estimate from routine labs, not a diagnosis. Fasting draw, away from acute illness, gives the truest read.</p>
    </div>

    {{-- Coach assessment — "what to work on" after a scan --}}
    @php $assessment = session('assessment'); @endphp
    @if ($assessment)
        <div class="rounded-2xl border border-indigo-500/25 bg-gradient-to-b from-indigo-500/[0.08] to-transparent p-4 md:p-6">
            <div class="flex items-center gap-2 mb-3">
                <span class="grid place-items-center h-8 w-8 rounded-xl bg-indigo-500/20 text-indigo-300">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <h3 class="font-display font-bold text-indigo-100 text-lg">Your scan — what to work on</h3>
            </div>
            <p class="text-[15px] leading-relaxed text-gray-100 font-medium">{{ $assessment['headline'] }}</p>

            @if (! empty($assessment['priorities']))
                <div class="mt-4 rounded-xl bg-gray-950/50 border border-white/5 p-3.5">
                    <p class="text-[11px] uppercase tracking-wide text-indigo-300/80 font-semibold mb-2">Attack first</p>
                    <ol class="space-y-1.5">
                        @foreach ($assessment['priorities'] as $i => $p)
                            <li class="flex gap-2.5 text-sm text-gray-200">
                                <span class="shrink-0 grid place-items-center h-5 w-5 rounded-full bg-indigo-500 text-[11px] font-bold text-white nums">{{ $i + 1 }}</span>
                                <span>{{ $p }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @if (! empty($assessment['concerns']))
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-2.5">
                    @foreach ($assessment['concerns'] as $c)
                        <div class="rounded-xl border border-rose-500/15 bg-rose-500/[0.05] p-3.5">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-semibold text-gray-100">{{ $c['marker'] ?? '' }}</span>
                                @if (! empty($c['reading']))<span class="text-xs text-rose-300/90 nums shrink-0">{{ $c['reading'] }}</span>@endif
                            </div>
                            @if (! empty($c['why']))<p class="text-[13px] text-gray-400 mt-1 leading-snug">{{ $c['why'] }}</p>@endif
                            @if (! empty($c['action']))
                                <p class="text-[13px] text-emerald-300/90 mt-2 flex gap-1.5 leading-snug">
                                    <svg class="h-4 w-4 shrink-0 mt-px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                    <span>{{ $c['action'] }}</span>
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! empty($assessment['wins']))
                <div class="mt-4">
                    <p class="text-[11px] uppercase tracking-wide text-emerald-300/80 font-semibold mb-2">Dialled in</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($assessment['wins'] as $w)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 ring-1 ring-emerald-500/25 px-3 py-1 text-[13px] text-emerald-200">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                {{ $w }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="mt-5 text-[11px] text-gray-600 border-t border-white/5 pt-3">Titan is a coach, not a doctor. This is guidance to inform a conversation with your physician — not a diagnosis or prescription.</p>
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
