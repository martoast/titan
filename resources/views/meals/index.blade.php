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

    @if ($isToday)
        {{-- Your kitchen — what you have on hand, so suggestions are makeable right now --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 mb-3" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="w-full flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="text-base">🧊</span>
                    <div class="text-left min-w-0">
                        <div class="font-display font-bold text-gray-100">Your kitchen</div>
                        <div class="text-[11px] text-gray-500">{{ count($pantry) ? count($pantry).' items — suggestions cook from these' : 'Tell Titan what you bought' }}</div>
                    </div>
                </div>
                <svg class="h-5 w-5 text-gray-500 shrink-0 transition" :class="open && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
            <div x-show="open" x-collapse class="mt-3">
                @if (count($pantry))
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        @foreach ($pantry as $item)
                            <form method="POST" action="{{ route('meals.pantry') }}" class="inline">
                                @csrf
                                <input type="hidden" name="remove" value="{{ $item }}">
                                <button type="submit" class="group inline-flex items-center gap-1 rounded-full border border-white/10 bg-white/[0.04] pl-2.5 pr-1.5 py-1 text-xs text-gray-200 active:bg-white/10">
                                    {{ $item }}
                                    <span class="grid place-items-center h-4 w-4 rounded-full text-gray-500 group-hover:text-rose-300">×</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400 mb-3 leading-relaxed">Just went shopping? List what you got — Titan will only suggest meals you can actually make. You can also just tell your agent: <span class="text-gray-300">"I bought ground beef, eggs, milk, tuna."</span></p>
                @endif
                <form method="POST" action="{{ route('meals.pantry') }}" class="flex items-center gap-2">
                    @csrf
                    <input type="text" name="items" placeholder="ground beef, eggs, milk, tuna…" required
                           class="flex-1 h-10 rounded-xl bg-gray-900 border border-white/10 px-3 text-sm text-gray-100 focus:border-cyan-500/50 focus:outline-none">
                    <button type="submit" class="h-10 shrink-0 rounded-xl bg-white/5 border border-white/10 px-4 text-sm font-semibold text-gray-200 active:bg-white/10">Add</button>
                </form>
            </div>
        </div>

        {{-- What should I eat? — next-meal timing + AI suggestions with generated photos --}}
        @php
            $mc = $mealCoach;
            $mcTone = match ($mc['status']) {
                'overdue' => 'text-amber-300', 'soon' => 'text-amber-300', 'done' => 'text-emerald-300', default => 'text-indigo-300',
            };
        @endphp
        <div class="rounded-2xl border border-indigo-500/20 bg-gradient-to-b from-indigo-500/[0.07] to-transparent p-4 md:p-5 mb-5">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[11px] uppercase tracking-wider text-indigo-300/80 font-semibold">🍽️ {{ $mc['label'] }}</div>
                    <p class="text-sm text-gray-300 mt-0.5 leading-snug">{{ $mc['advice'] }}</p>
                </div>
                <form method="POST" action="{{ route('meals.suggest') }}" class="shrink-0" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button type="submit" :disabled="busy"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-500/90 px-3.5 py-2 text-xs font-semibold text-white active:bg-indigo-400 disabled:opacity-60">
                        <svg x-show="!busy" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                        <svg x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                        <span x-text="busy ? 'Cooking up ideas…' : '{{ count($pantry) ? 'Cook from my kitchen' : 'Suggest meals' }}'"></span>
                    </button>
                </form>
            </div>
            @error('suggest')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror

            @if ($suggestions->isNotEmpty())
                <div class="mt-4 -mx-4 px-4 md:mx-0 md:px-0 flex gap-3 overflow-x-auto no-scrollbar snap-x">
                    @foreach ($suggestions as $s)
                        <a href="{{ route('meals.recipe', $s) }}" class="shrink-0 w-44 snap-start active:opacity-80">
                            <div class="rounded-2xl border border-white/5 bg-white/[0.03] overflow-hidden">
                                <div class="relative aspect-[4/3] bg-gray-950">
                                    @if ($s->imageUrl())
                                        <img src="{{ $s->imageUrl() }}" alt="{{ $s->name }}" class="absolute inset-0 h-full w-full object-cover">
                                    @else
                                        <div class="absolute inset-0 grid place-items-center text-2xl">🍲</div>
                                    @endif
                                    @if ($s->protein_g)<span class="absolute top-2 left-2 rounded-full bg-black/55 backdrop-blur px-2 py-0.5 text-[10px] font-semibold text-emerald-200 nums">{{ (int) $s->protein_g }}g protein</span>@endif
                                </div>
                                <div class="p-2.5">
                                    <div class="font-semibold text-gray-100 text-[13px] leading-tight line-clamp-2">{{ $s->name }}</div>
                                    <div class="text-[11px] text-gray-500 nums mt-1">{{ $s->calories ? number_format($s->calories).' kcal' : '' }} · recipe →</div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

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

    {{-- Hydration — bodyweight-based daily target + quick-add, mirroring the iOS Fuel hydration card --}}
    @php
        $hyd = $hydration;
        $hydL = number_format($hyd['total_ml'] / 1000, 1);
        $hydTargetL = number_format($hyd['target_ml'] / 1000, 1);
        $hydCirc = 226.2;                                  // 2πr for r=36
        $hydOffset = $hydCirc * (1 - min(100, $hyd['pct']) / 100);
    @endphp
    <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Hydration</div>
            <div class="text-xs font-semibold text-cyan-300 nums">{{ $hyd['pct'] }}%</div>
        </div>
        <div class="flex items-center gap-5">
            {{-- Progress ring --}}
            <div class="relative shrink-0 h-24 w-24">
                <svg class="h-24 w-24 -rotate-90" viewBox="0 0 84 84">
                    <circle cx="42" cy="42" r="36" fill="none" stroke="currentColor" stroke-width="7" class="text-white/[0.06]"/>
                    <circle cx="42" cy="42" r="36" fill="none" stroke="url(#hydGrad)" stroke-width="7" stroke-linecap="round"
                            stroke-dasharray="{{ $hydCirc }}" stroke-dashoffset="{{ $hydOffset }}"/>
                    <defs>
                        <linearGradient id="hydGrad" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#22d3ee"/><stop offset="100%" stop-color="#38bdf8"/>
                        </linearGradient>
                    </defs>
                </svg>
                <div class="absolute inset-0 grid place-items-center text-center">
                    <div>
                        <div class="text-xl font-bold text-gray-100 nums leading-none">{{ $hydL }}</div>
                        <div class="text-[10px] text-gray-500 nums mt-0.5">of {{ $hydTargetL }}L</div>
                    </div>
                </div>
            </div>
            {{-- Quick-add buttons --}}
            <div class="flex-1 space-y-2">
                @foreach ([['Glass', 250], ['Bottle', 500], ['Large', 750]] as [$label, $ml])
                    <form method="POST" action="{{ route('meals.water') }}">
                        @csrf
                        <input type="hidden" name="ml" value="{{ $ml }}">
                        <button type="submit"
                                class="w-full flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-3.5 py-2 text-sm text-gray-200 active:bg-white/10 hover:bg-white/[0.07] transition">
                            <svg class="h-3.5 w-3.5 text-cyan-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2s6 6.4 6 11a6 6 0 11-12 0c0-4.6 6-11 6-11z"/></svg>
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="ml-auto text-xs text-gray-500 nums">+{{ $ml }} ml</span>
                        </button>
                    </form>
                @endforeach
            </div>
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
                      x-data="{ loading: false }" @submit="loading = true">
                    @csrf
                    <input type="hidden" name="eaten_at" :value="new Date(Date.now() - new Date().getTimezoneOffset()*60000).toISOString().slice(0,16)">
                    <x-upload-zone kind="image" name="photo" required accept="image/*"
                                   label="Tap to add a meal photo" hint="AI estimates ingredients & macros — you confirm before saving" />
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
