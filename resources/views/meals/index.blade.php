<x-titan-layout title="Meals" subtitle="Snap a photo — AI logs your calories & macros">
    @php
        $pct = fn ($v, $t) => $t > 0 ? min(100, round($v / $t * 100)) : 0;
        $isToday = $day->isToday();
    @endphp

    {{-- Day switcher — full-width on mobile, arrows pinned to the edges --}}
    <div class="flex items-center gap-3 mb-5">
        <a href="/meals?day={{ $day->copy()->subDay()->format('Y-m-d') }}"
           class="h-10 w-10 shrink-0 grid place-items-center rounded-xl border border-white/10 bg-white/[0.03] text-gray-300 active:bg-white/10">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div class="flex-1 text-center">
            <div class="font-display text-base font-bold text-gray-100">{{ $isToday ? 'Today' : $day->format('l') }}</div>
            <div class="text-xs text-gray-500 nums">{{ $day->format('M j, Y') }}</div>
        </div>
        <a href="/meals?day={{ $day->copy()->addDay()->format('Y-m-d') }}"
           class="h-10 w-10 shrink-0 grid place-items-center rounded-xl border border-white/10 bg-white/[0.03] text-gray-300 active:bg-white/10 {{ $isToday ? 'opacity-30 pointer-events-none' : '' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>
    @unless($isToday)
        <div class="-mt-3 mb-4 text-center">
            <a href="/meals" class="text-xs font-medium text-indigo-400 active:text-indigo-300">↩ Back to today</a>
        </div>
    @endunless

    {{-- Daily totals bar --}}
    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5 mb-6"
         x-data="{ open: false }">
        <div class="flex items-start justify-between mb-4">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500">Calories</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-gray-100">{{ number_format($totals['calories']) }}</span>
                    <span class="text-sm text-gray-500">/ {{ number_format($targets['calories']) }} kcal</span>
                </div>
            </div>
            <button @click="open = !open" class="text-xs text-gray-500 hover:text-gray-300">Edit targets</button>
        </div>

        {{-- Calorie bar --}}
        @php $calPct = $pct($totals['calories'], $targets['calories']); @endphp
        <div class="h-2.5 w-full rounded-full bg-white/5 overflow-hidden mb-5">
            <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $calPct }}%"></div>
        </div>

        {{-- Macro bars --}}
        <div class="grid grid-cols-3 gap-4">
            @php
                $macros = [
                    ['Protein', 'protein_g', 'from-rose-500 to-orange-400'],
                    ['Carbs',   'carbs_g',   'from-amber-500 to-yellow-400'],
                    ['Fat',     'fat_g',     'from-sky-500 to-indigo-400'],
                ];
            @endphp
            @foreach ($macros as [$label, $key, $grad])
                @php $mp = $pct($totals[$key], $targets[$key]); @endphp
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-0.5">{{ $label }}</div>
                    <div class="text-sm font-semibold text-gray-100 nums mb-1.5">
                        {{ rtrim(rtrim(number_format($totals[$key], 1), '0'), '.') }}<span class="text-gray-500 font-normal">/{{ $targets[$key] }}g</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r {{ $grad }}" style="width: {{ $mp }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Editable targets --}}
        <div x-show="open" x-cloak class="mt-5 pt-5 border-t border-white/5">
            <form method="POST" action="/meals/targets" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @csrf
                @foreach (['calories' => 'Calories', 'protein_g' => 'Protein (g)', 'carbs_g' => 'Carbs (g)', 'fat_g' => 'Fat (g)'] as $k => $lbl)
                    <label class="block">
                        <span class="text-xs text-gray-500">{{ $lbl }}</span>
                        <input type="number" step="1" min="0" name="{{ $k }}" value="{{ $targets[$k] }}"
                               class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                    </label>
                @endforeach
                <div class="col-span-2 sm:col-span-4 flex justify-end">
                    <button class="rounded-lg bg-indigo-500/90 hover:bg-indigo-500 px-4 py-2 text-sm font-medium text-white">Save targets</button>
                </div>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left: log + meals list --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Snap-a-meal + text entry --}}
            <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5" x-data="{ tab: 'photo' }">
                <div class="flex items-center gap-1 mb-4 text-sm">
                    <button @click="tab='photo'" :class="tab==='photo' ? 'bg-indigo-500/15 text-indigo-300' : 'text-gray-400 hover:text-gray-100'" class="rounded-lg px-3 py-1.5 font-medium transition">Snap a meal</button>
                    <button @click="tab='text'" :class="tab==='text' ? 'bg-indigo-500/15 text-indigo-300' : 'text-gray-400 hover:text-gray-100'" class="rounded-lg px-3 py-1.5 font-medium transition">Describe it</button>
                    <a href="/meals/add" class="ml-auto text-xs text-gray-500 active:text-gray-300">Manual</a>
                </div>

                {{-- Photo upload --}}
                <form x-show="tab==='photo'" method="POST" action="/meals/analyze" enctype="multipart/form-data"
                      x-data="{ name: '', loading: false }" @submit="loading = true">
                    @csrf
                    <input type="hidden" name="eaten_at" :value="new Date(Date.now() - new Date().getTimezoneOffset()*60000).toISOString().slice(0,16)">
                    <label class="group flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-white/10 hover:border-indigo-500/40 bg-gray-950/40 py-8 cursor-pointer transition">
                        <svg class="h-8 w-8 text-gray-600 group-hover:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span class="text-sm text-gray-400" x-text="name || 'Tap to upload a meal photo'"></span>
                        <span class="text-xs text-gray-600">AI estimates ingredients & macros — you confirm before saving</span>
                        <input type="file" name="photo" accept="image/*" capture="environment" class="hidden" @change="name = $event.target.files[0]?.name">
                    </label>
                    <button type="submit" :disabled="loading"
                            class="mt-3 w-full rounded-lg bg-indigo-500/90 hover:bg-indigo-500 disabled:opacity-50 px-4 py-2.5 text-sm font-medium text-white">
                        <span x-show="!loading">Analyze photo</span>
                        <span x-show="loading" x-cloak>Analyzing…</span>
                    </button>
                </form>

                {{-- Text entry --}}
                <form x-show="tab==='text'" x-cloak method="POST" action="/meals/parse-text"
                      x-data="{ loading: false }" @submit="loading = true">
                    @csrf
                    <input type="hidden" name="eaten_at" :value="new Date(Date.now() - new Date().getTimezoneOffset()*60000).toISOString().slice(0,16)">
                    <textarea name="text" rows="3" required placeholder="e.g. 2 scrambled eggs, a bowl of oatmeal with banana, black coffee"
                              class="w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2.5 text-sm text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:ring-0"></textarea>
                    <button type="submit" :disabled="loading"
                            class="mt-2 w-full rounded-lg bg-indigo-500/90 hover:bg-indigo-500 disabled:opacity-50 px-4 py-2.5 text-sm font-medium text-white">
                        <span x-show="!loading">Estimate macros</span>
                        <span x-show="loading" x-cloak>Thinking…</span>
                    </button>
                </form>
            </div>

            {{-- Meals list --}}
            <div class="space-y-3">
                @forelse ($meals as $meal)
                    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4 flex gap-4">
                        @if ($meal->photoUrl())
                            <img src="{{ $meal->photoUrl() }}" alt="" class="h-20 w-20 rounded-lg object-cover shrink-0 bg-gray-950">
                        @else
                            <div class="h-20 w-20 rounded-lg bg-gray-950 grid place-items-center shrink-0">
                                <svg class="h-7 w-7 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18M3 3v18M3 7h18M7 3v4m0 8a3 3 0 106 0 3 3 0 00-6 0z"/></svg>
                            </div>
                        @endif
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-100 truncate">{{ $meal->name ?: 'Meal' }}</div>
                                    <div class="text-xs text-gray-500">{{ $meal->eaten_at->format('g:i A') }} · {{ ucfirst($meal->source) }}</div>
                                </div>
                                <form method="POST" action="/meals/{{ $meal->id }}" onsubmit="return confirm('Delete this meal?')">
                                    @csrf @method('DELETE')
                                    <button class="text-gray-600 hover:text-rose-400" title="Delete">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                <span class="text-gray-100 font-medium">{{ number_format($meal->calories) }} kcal</span>
                                <span class="text-rose-300/80">P {{ rtrim(rtrim(number_format($meal->protein_g, 1), '0'), '.') }}g</span>
                                <span class="text-amber-300/80">C {{ rtrim(rtrim(number_format($meal->carbs_g, 1), '0'), '.') }}g</span>
                                <span class="text-sky-300/80">F {{ rtrim(rtrim(number_format($meal->fat_g, 1), '0'), '.') }}g</span>
                            </div>
                            @if ($meal->items->isNotEmpty())
                                <div class="mt-1.5 text-xs text-gray-500 truncate">
                                    {{ $meal->items->map(fn ($i) => trim(($i->quantity ? $i->quantity.' ' : '').$i->name))->implode(' · ') }}
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-white/10 bg-gray-900/30 p-8 text-center">
                        <p class="text-gray-400 text-sm">No meals logged {{ $isToday ? 'yet today' : 'this day' }}.</p>
                        <p class="text-gray-600 text-xs mt-1">Snap a photo or describe a meal above to get started.</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Right: 7-day trend --}}
        <div class="lg:col-span-1">
            <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
                <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">7-day calories</div>
                <div class="text-2xl font-bold text-gray-100 mb-4">
                    {{ number_format(round(collect($trend)->avg('calories'))) }}
                    <span class="text-sm font-normal text-gray-500">avg/day</span>
                </div>
                <div x-data="mealsTrend(@js($trend), {{ $targets['calories'] }})" x-init="render()">
                    <canvas x-ref="canvas" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Chart.js via jsDelivr CDN --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        function mealsTrend(trend, target) {
            return {
                render() {
                    if (!window.Chart) return;
                    new Chart(this.$refs.canvas, {
                        type: 'bar',
                        data: {
                            labels: trend.map(d => d.label),
                            datasets: [{
                                label: 'Calories',
                                data: trend.map(d => d.calories),
                                backgroundColor: trend.map(d => d.calories > target * 1.1 ? 'rgba(244,63,94,0.7)' : 'rgba(99,102,241,0.7)'),
                                borderRadius: 6,
                                borderSkipped: false,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: { label: c => c.parsed.y.toLocaleString() + ' kcal' } },
                            },
                            scales: {
                                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 11 } } },
                                y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#6b7280', font: { size: 11 } }, beginAtZero: true },
                            },
                        },
                    });
                },
            };
        }
    </script>
</x-titan-layout>
