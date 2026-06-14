<x-titan-layout title="Body" subtitle="Weight, body-fat, and measurements trending over time">
    @if (session('status'))
        <div class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-300">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- ===================== Latest snapshot ===================== --}}
    @php
        $stats = [
            ['Weight', $latest['weight_kg'], 'kg'],
            ['Body fat', $latest['body_fat_pct'], '%'],
            ['Waist', $latest['waist_cm'], 'cm'],
            ['Chest', $latest['chest_cm'], 'cm'],
            ['Arm', $latest['arm_cm'], 'cm'],
            ['Thigh', $latest['thigh_cm'], 'cm'],
        ];
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
        @foreach ($stats as [$label, $val, $unit])
            <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold text-gray-100">
                    @if ($val !== null)
                        {{ rtrim(rtrim(number_format((float) $val, 2, '.', ''), '0'), '.') }}<span class="text-xs text-gray-500 font-normal ml-1">{{ $unit }}</span>
                    @else
                        <span class="text-sm text-gray-600 font-normal">—</span>
                    @endif
                </p>
            </div>
        @endforeach
    </div>

    {{-- ===================== Trend charts ===================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <h3 class="font-semibold text-gray-100 mb-3">Weight</h3>
            @if (count($series['weight']) >= 2)
                <div class="h-56"><canvas x-data x-init="$nextTick(() => window.titanLine($el, @js($series['weight']), '#818cf8', 'kg'))"></canvas></div>
            @else
                <p class="text-sm text-gray-600">Log at least two weigh-ins to see your trend.</p>
            @endif
        </div>
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <h3 class="font-semibold text-gray-100 mb-3">Body fat %</h3>
            @if (count($series['bodyfat']) >= 2)
                <div class="h-56"><canvas x-data x-init="$nextTick(() => window.titanLine($el, @js($series['bodyfat']), '#22d3ee', '%'))"></canvas></div>
            @else
                <p class="text-sm text-gray-600">Log at least two body-fat readings to see your trend.</p>
            @endif
        </div>
    </div>

    {{-- ===================== Add form ===================== --}}
    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5 mb-8">
        <h3 class="font-semibold text-gray-100 mb-1">Add a measurement</h3>
        <p class="text-sm text-gray-500 mb-4">Fill in whatever you measured today — every field is optional.</p>
        <form method="POST" action="{{ route('body.store') }}" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @csrf
            @php
                $fields = [
                    ['weight_kg', 'Weight (kg)', 'any'],
                    ['body_fat_pct', 'Body fat (%)', 'any'],
                    ['waist_cm', 'Waist (cm)', 'any'],
                    ['chest_cm', 'Chest (cm)', 'any'],
                    ['arm_cm', 'Arm (cm)', 'any'],
                    ['thigh_cm', 'Thigh (cm)', 'any'],
                ];
            @endphp
            @foreach ($fields as [$name, $label, $step])
                <label class="block">
                    <span class="text-xs text-gray-500">{{ $label }}</span>
                    <input type="number" step="{{ $step }}" name="{{ $name }}" value="{{ old($name) }}"
                           class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                </label>
            @endforeach
            <label class="block">
                <span class="text-xs text-gray-500">Date</span>
                <input type="date" name="taken_at" value="{{ old('taken_at', now()->toDateString()) }}" required
                       class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </label>
            <label class="block sm:col-span-3">
                <span class="text-xs text-gray-500">Note (optional)</span>
                <input type="text" name="note" value="{{ old('note') }}" maxlength="500"
                       class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </label>
            <div class="col-span-2 sm:col-span-4">
                <button type="submit"
                        class="rounded-lg bg-indigo-500 hover:bg-indigo-400 px-5 py-2 text-sm font-semibold text-white transition">
                    Save measurement
                </button>
            </div>
        </form>
    </div>

    {{-- ===================== History ===================== --}}
    @if ($metrics->isNotEmpty())
        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">History</h3>
        <div class="overflow-x-auto rounded-xl border border-white/5">
            <table class="w-full text-sm">
                <thead class="bg-gray-900/60 text-gray-500">
                    <tr class="text-left">
                        <th class="px-4 py-2 font-medium">Date</th>
                        <th class="px-4 py-2 font-medium text-right">Weight</th>
                        <th class="px-4 py-2 font-medium text-right">BF%</th>
                        <th class="px-4 py-2 font-medium text-right">Waist</th>
                        <th class="px-4 py-2 font-medium text-right">Chest</th>
                        <th class="px-4 py-2 font-medium text-right">Arm</th>
                        <th class="px-4 py-2 font-medium text-right">Thigh</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach ($metrics as $m)
                        @php $fmt = fn ($v) => $v !== null ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : '—'; @endphp
                        <tr class="text-gray-300 hover:bg-white/[0.02]">
                            <td class="px-4 py-2">{{ $m->taken_at->format('M j, Y') }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmt($m->weight_kg) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmt($m->body_fat_pct) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmt($m->waist_cm) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmt($m->chest_cm) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmt($m->arm_cm) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmt($m->thigh_cm) }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('body.destroy', $m) }}"
                                      onsubmit="return confirm('Delete this measurement?')">
                                    @csrf @method('DELETE')
                                    <button class="text-gray-600 hover:text-rose-400 transition" title="Delete">
                                        <svg class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

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
