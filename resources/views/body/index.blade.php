<x-titan-layout title="Body" subtitle="Weight, body-fat, and measurements trending over time">

    <div class="space-y-4 md:space-y-5">

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

    {{-- ===================== Latest snapshot ===================== --}}
    @php
        $stats = [
            ['Weight', $latest['weight_kg'], $weightUnit],
            ['Body fat', $latest['body_fat_pct'], '%'],
            ['Waist', $latest['waist_cm'], $lengthUnit],
            ['Chest', $latest['chest_cm'], $lengthUnit],
            ['Arm', $latest['arm_cm'], $lengthUnit],
            ['Thigh', $latest['thigh_cm'], $lengthUnit],
        ];
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 md:gap-4">
        @foreach ($stats as [$label, $val, $unit])
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $label }}</div>
                <div class="mt-1 font-display text-xl font-bold text-gray-100 nums">
                    @if ($val !== null)
                        {{ rtrim(rtrim(number_format((float) $val, 2, '.', ''), '0'), '.') }}<span class="text-xs text-gray-500 font-normal ml-1">{{ $unit }}</span>
                    @else
                        <span class="text-sm text-gray-600 font-normal">—</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- ===================== Trend charts ===================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-4">
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-3">Weight</h3>
            @if (count($series['weight']) >= 2)
                <div class="h-48 md:h-56"><canvas x-data x-init="$nextTick(() => window.titanLine($el, @js($series['weight']), '#818cf8', '{{ $weightUnit }}'))"></canvas></div>
            @else
                <p class="text-sm text-gray-500">Log at least two weigh-ins to see your trend.</p>
            @endif
        </div>
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-3">Body fat %</h3>
            @if (count($series['bodyfat']) >= 2)
                <div class="h-48 md:h-56"><canvas x-data x-init="$nextTick(() => window.titanLine($el, @js($series['bodyfat']), '#22d3ee', '%'))"></canvas></div>
            @else
                <p class="text-sm text-gray-500">Log at least two body-fat readings to see your trend.</p>
            @endif
        </div>
    </div>

    {{-- ===================== Add form (deferred behind a tap) ===================== --}}
    <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5" x-data="{ logOpen: {{ $errors->any() ? 'true' : 'false' }} }">
        <button type="button" @click="logOpen = !logOpen" class="flex w-full items-center justify-between gap-3 text-left">
            <span class="font-display font-bold text-gray-100">Add a measurement</span>
            <svg class="h-5 w-5 shrink-0 text-gray-500 transition" :class="logOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="logOpen" x-collapse x-cloak>
            <p class="text-sm text-gray-400 mt-3 mb-4">Fill in whatever you measured today — every field is optional.</p>
            <form method="POST" action="{{ route('body.store') }}" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @csrf
            @php
                $fields = [
                    ['weight_kg', 'Weight ('.$weightUnit.')', 'any'],
                    ['body_fat_pct', 'Body fat (%)', 'any'],
                    ['waist_cm', 'Waist ('.$lengthUnit.')', 'any'],
                    ['chest_cm', 'Chest ('.$lengthUnit.')', 'any'],
                    ['arm_cm', 'Arm ('.$lengthUnit.')', 'any'],
                    ['thigh_cm', 'Thigh ('.$lengthUnit.')', 'any'],
                ];
            @endphp
            @foreach ($fields as [$name, $label, $step])
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">{{ $label }}</span>
                    <input type="number" step="{{ $step }}" name="{{ $name }}" value="{{ old($name) }}"
                           class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                </label>
            @endforeach
            <label class="block col-span-2 sm:col-span-1">
                <span class="text-[11px] uppercase tracking-wide text-gray-500">Date</span>
                <input type="date" name="taken_at" value="{{ old('taken_at', now()->toDateString()) }}" required
                       class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
            </label>
            <label class="block col-span-2 sm:col-span-3">
                <span class="text-[11px] uppercase tracking-wide text-gray-500">Note (optional)</span>
                <input type="text" name="note" value="{{ old('note') }}" maxlength="500"
                       class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
            </label>
            <div class="col-span-2 sm:col-span-4">
                <button type="submit"
                        class="w-full md:w-auto h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-6 text-base font-semibold text-white active:opacity-90 transition">
                    Save measurement
                </button>
            </div>
            </form>
        </div>
    </div>

    {{-- ===================== History ===================== --}}
    @if ($history->isNotEmpty())
        <div>
            <h3 class="font-display text-sm font-bold text-gray-400 uppercase tracking-wide mb-3">History</h3>
            <div class="overflow-x-auto no-scrollbar rounded-2xl border border-white/5">
                <table class="w-full text-sm whitespace-nowrap">
                    <thead class="bg-gray-900/60 text-gray-500">
                        <tr class="text-left">
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium text-right">Weight ({{ $weightUnit }})</th>
                            <th class="px-4 py-2 font-medium text-right">BF%</th>
                            <th class="px-4 py-2 font-medium text-right">Waist ({{ $lengthUnit }})</th>
                            <th class="px-4 py-2 font-medium text-right">Chest ({{ $lengthUnit }})</th>
                            <th class="px-4 py-2 font-medium text-right">Arm ({{ $lengthUnit }})</th>
                            <th class="px-4 py-2 font-medium text-right">Thigh ({{ $lengthUnit }})</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($history as $h)
                            @php $fmt = fn ($v) => $v !== null ? \App\Support\Units::num((float) $v) : '—'; @endphp
                            <tr class="text-gray-300 hover:bg-white/[0.02]">
                                <td class="px-4 py-2 nums">{{ $h['date'] }}</td>
                                <td class="px-4 py-2 text-right nums">{{ $fmt($h['weight']) }}</td>
                                <td class="px-4 py-2 text-right nums">{{ $fmt($h['body_fat_pct']) }}</td>
                                <td class="px-4 py-2 text-right nums">{{ $fmt($h['waist']) }}</td>
                                <td class="px-4 py-2 text-right nums">{{ $fmt($h['chest']) }}</td>
                                <td class="px-4 py-2 text-right nums">{{ $fmt($h['arm']) }}</td>
                                <td class="px-4 py-2 text-right nums">{{ $fmt($h['thigh']) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <form method="POST" action="{{ route('body.destroy', $h['id']) }}"
                                          onsubmit="return confirm('Delete this measurement?')">
                                        @csrf @method('DELETE')
                                        <button class="text-gray-600 hover:text-rose-400 active:text-rose-400 transition" title="Delete">
                                            <svg class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        window.titanLine = function (canvas, points, color, unit) {
            if (!canvas || !window.Chart || !points || points.length < 2) return;
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: points.map(p => p.date),
                    datasets: [{
                        data: points.map(p => p.value),
                        borderColor: color,
                        backgroundColor: color + '20',
                        borderWidth: 2,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: true,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: {
                        displayColors: false,
                        callbacks: { label: i => i.formattedValue + ' ' + (unit || '') },
                    } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#6b7280', maxTicksLimit: 6, font: { size: 10 } } },
                        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#6b7280', font: { size: 10 } } },
                    },
                },
            });
        };
    </script>
</x-titan-layout>
