<x-titan-layout title="Dashboard" subtitle="Your trajectory toward the strongest version of yourself">
    <div class="space-y-4 md:space-y-5">

        {{-- ============ FUTURE SELF — the living dream-physique render ============ --}}
        <a href="/photos" class="block group">
            <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-indigo-500/15 via-gray-900 to-cyan-400/10">
                @if ($futureSelf['image'])
                    <div class="grid grid-cols-2">
                        {{-- Now --}}
                        <div class="relative aspect-[3/4] bg-gray-950">
                            @if ($futureSelf['now_image'])
                                <img src="{{ $futureSelf['now_image'] }}" alt="Now" class="absolute inset-0 h-full w-full object-cover opacity-90">
                            @else
                                <div class="absolute inset-0 grid place-items-center text-xs text-gray-600">Add a progress photo</div>
                            @endif
                            <span class="absolute top-2 left-2 rounded-full bg-black/50 backdrop-blur px-2 py-0.5 text-[10px] uppercase tracking-wide text-gray-300">Now</span>
                        </div>
                        {{-- Future self --}}
                        <div class="relative aspect-[3/4] bg-gray-950">
                            <img src="{{ $futureSelf['image'] }}" alt="Your future self" class="absolute inset-0 h-full w-full object-cover">
                            <span class="absolute top-2 right-2 rounded-full bg-indigo-500/80 backdrop-blur px-2 py-0.5 text-[10px] uppercase tracking-wide font-semibold text-white">Future self</span>
                        </div>
                    </div>
                    {{-- Progress toward the dream physique --}}
                    <div class="p-4 md:p-5">
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <div class="text-[11px] uppercase tracking-wider text-indigo-300/80 font-semibold">Toward your dream physique</div>
                                <div class="mt-0.5 font-display text-2xl font-bold text-gray-100">
                                    {{ $futureSelf['pct'] !== null ? $futureSelf['pct'].'%' : 'Tracking' }}
                                    <span class="text-sm font-normal text-gray-500">there</span>
                                </div>
                            </div>
                            @if ($futureSelf['adherence'] !== null)
                                <div class="text-right shrink-0">
                                    <div class="text-[11px] text-gray-500">consistency</div>
                                    <div class="font-display text-lg font-bold nums text-cyan-300">{{ $futureSelf['adherence'] }}%</div>
                                </div>
                            @endif
                        </div>
                        @if ($futureSelf['pct'] !== null)
                            <div class="mt-3 h-2 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ max(3, min(100, $futureSelf['pct'])) }}%"></div>
                            </div>
                        @endif
                        <p class="mt-2.5 text-[11px] text-gray-500 leading-relaxed">Your future self advances as you stay consistent. Keep showing up and the gap closes.</p>
                    </div>
                @else
                    {{-- No goal yet → the emotional CTA --}}
                    <div class="p-6 md:p-8 text-center">
                        <h2 class="font-display text-xl md:text-2xl font-bold text-gray-100">Meet your future self</h2>
                        <p class="mt-2 text-sm text-gray-400 max-w-md mx-auto leading-relaxed">Upload a photo and Titan renders your dream physique — a living image that advances toward the goal as you stay consistent. It's the whole point.</p>
                        <span class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-indigo-500/90 px-4 py-2 text-sm font-semibold text-white group-active:bg-indigo-400">Create your dream physique →</span>
                    </div>
                @endif
            </div>
        </a>

        {{-- ============ TODAY'S FOCUS — the one thing to work on ============ --}}
        @php
            $focusTone = match ($focus['focus']) {
                'recover' => ['border-orange-500/25', 'from-orange-500/[0.10]', 'text-orange-300'],
                'sleep' => ['border-indigo-500/25', 'from-indigo-500/[0.10]', 'text-indigo-300'],
                'push' => ['border-emerald-500/25', 'from-emerald-500/[0.10]', 'text-emerald-300'],
                default => ['border-cyan-500/20', 'from-cyan-500/[0.08]', 'text-cyan-300'],
            };
        @endphp
        <div class="rounded-2xl border {{ $focusTone[0] }} bg-gradient-to-b {{ $focusTone[1] }} to-transparent p-4 md:p-5">
            <div class="flex items-center justify-between">
                <div class="text-[11px] uppercase tracking-wider {{ $focusTone[2] }} font-semibold">Today's focus</div>
                @if ($today['from_wearable'])
                    <span class="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-400/80">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.55a11 11 0 0114 0M8.5 16.05a6 6 0 017 0M2 9.05a16 16 0 0120 0M12 20h.01"/></svg>
                        Wearable
                    </span>
                @endif
            </div>
            <h2 class="mt-1 font-display text-xl font-bold {{ $focusTone[2] }}">{{ $focus['headline'] }}</h2>
            <p class="mt-1 text-sm text-gray-300 leading-relaxed">{{ $focus['detail'] }}</p>
        </div>

        {{-- ============ THE DAILY LOOP — Recovery · Strain · Sleep ============ --}}
        @php
            $rTone = match (true) {
                $today['readiness'] === null => 'text-gray-400',
                $today['readiness'] >= 67 => 'text-emerald-300', $today['readiness'] >= 34 => 'text-amber-300',
                default => 'text-orange-300',
            };
            $sTone = match ($strain['band']) {
                'all_out', 'high' => 'text-cyan-300', 'moderate' => 'text-indigo-300', default => 'text-gray-300',
            };
            $perf = $sleepCoach['performance_pct'] ?? null;
            $slTone = $perf === null ? 'text-gray-400' : ($perf >= 88 ? 'text-emerald-300' : ($perf >= 78 ? 'text-cyan-300' : 'text-amber-300'));
        @endphp
        <div class="grid grid-cols-3 gap-3 md:gap-4">
            {{-- Recovery --}}
            <a href="/recovery" class="rounded-2xl border border-white/5 bg-white/[0.03] p-3.5 md:p-4 active:bg-white/[0.05]">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Recovery</div>
                <div class="font-display text-2xl md:text-3xl font-bold nums {{ $rTone }} mt-0.5 leading-none">{{ $today['readiness'] ?? '—' }}<span class="text-gray-600 text-sm font-normal">{{ $today['readiness'] !== null ? '%' : '' }}</span></div>
                <div class="text-[11px] text-gray-500 mt-1 truncate">{{ $today['readiness_label'] ?: 'connect a device' }}</div>
            </a>
            {{-- Strain (vs recovery-driven target) --}}
            <a href="/fitness" class="rounded-2xl border border-white/5 bg-white/[0.03] p-3.5 md:p-4 active:bg-white/[0.05]">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Strain</div>
                <div class="font-display text-2xl md:text-3xl font-bold nums {{ $sTone }} mt-0.5 leading-none">{{ number_format($strain['strain'], 1) }}<span class="text-gray-600 text-sm font-normal nums"> / {{ number_format($strain['target']['high'], 0) }}</span></div>
                <div class="text-[11px] text-gray-500 mt-1 truncate">{{ $strain['target']['label'] }}</div>
            </a>
            {{-- Sleep (performance vs need) --}}
            <a href="/sleep" class="rounded-2xl border border-white/5 bg-white/[0.03] p-3.5 md:p-4 active:bg-white/[0.05]">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Sleep</div>
                <div class="font-display text-2xl md:text-3xl font-bold nums {{ $slTone }} mt-0.5 leading-none">{{ $perf !== null ? $perf : '—' }}<span class="text-gray-600 text-sm font-normal">{{ $perf !== null ? '%' : '' }}</span></div>
                <div class="text-[11px] text-gray-500 mt-1 truncate">{{ $sleepCoach ? 'need '.number_format($sleepCoach['need_h'], 1).'h' : 'no nights yet' }}</div>
            </a>
        </div>

        {{-- ============ CYCLE — phase-aware context (women) ============ --}}
        @if (! empty($cycle))
            @php
                $cycPhase = $cycle['phase'];
                $cycColors = ['menstrual'=>'#fb7185','follicular'=>'#34d399','fertile'=>'#22d3ee','ovulation'=>'#a78bfa','luteal'=>'#fbbf24','unknown'=>'#9ca3af'];
                $cycC = $cycColors[$cycPhase] ?? '#9ca3af';
                $cycNext = $cycle['next_period']['in_days'] ?? null;
            @endphp
            <a href="/cycle" class="block rounded-2xl border border-white/5 bg-white/[0.03] p-4 active:bg-white/[0.05] transition">
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full font-display font-bold text-gray-900" style="background:{{ $cycC }}">{{ $cycle['cycle_day'] }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-display font-bold text-gray-100">{{ $cycle['phase_label'] }} phase</span>
                            <span class="text-[11px] text-gray-500 shrink-0">
                                @if ($cycle['late']) <span class="text-rose-300">{{ $cycle['next_period']['late_days'] }}d late</span>
                                @elseif ($cycNext === 0) period today
                                @else period in {{ $cycNext }}d @endif
                            </span>
                        </div>
                        <p class="text-xs text-gray-400 mt-0.5 truncate">{{ $cycle['note'] }}</p>
                    </div>
                </div>
            </a>
        @endif

        {{-- ============ NEXT MEAL — fuel before you're hungry ============ --}}
        @php
            $mTone = match ($meal['status']) {
                'overdue' => ['border-rose-500/30', 'from-rose-500/[0.10]', 'text-rose-300', 'bg-rose-500/90'],
                'soon' => ['border-amber-500/25', 'from-amber-500/[0.09]', 'text-amber-300', 'bg-amber-500/90'],
                'done' => ['border-emerald-500/20', 'from-emerald-500/[0.06]', 'text-emerald-300', 'bg-white/10'],
                default => ['border-indigo-500/20', 'from-indigo-500/[0.07]', 'text-indigo-300', 'bg-indigo-500/90'],
            };
            $proteinPct = $meal['target']['protein_g'] > 0 ? min(100, round($meal['consumed']['protein_g'] / $meal['target']['protein_g'] * 100)) : 0;
        @endphp
        <div class="rounded-2xl border {{ $mTone[0] }} bg-gradient-to-b {{ $mTone[1] }} to-transparent p-4 md:p-5"
             x-data="mealCountdown(@js($meal['next_at']), @js($meal['status']))">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[11px] uppercase tracking-wider {{ $mTone[2] }} font-semibold flex items-center gap-1.5">
                        <span>🍽️</span> {{ $meal['label'] }}
                    </div>
                    {{-- live countdown / overdue timer --}}
                    @if ($meal['status'] === 'done')
                        <div class="mt-1 font-display text-2xl font-bold text-emerald-300 leading-none">{{ $meal['meals_logged'] }}/{{ $meal['meals_planned'] }} meals</div>
                    @else
                        <div class="mt-1 font-display text-3xl font-bold nums {{ $mTone[2] }} leading-none" x-text="display"></div>
                    @endif
                    <p class="mt-1.5 text-sm text-gray-300 leading-relaxed">{{ $meal['advice'] }}</p>
                    @if (! empty($meal['cycle_note']))
                        <p class="mt-1.5 text-xs text-rose-200/80 leading-relaxed">🌙 {{ $meal['cycle_note'] }}</p>
                    @endif
                </div>
                @if ($meal['status'] !== 'done')
                    <div class="shrink-0 text-right">
                        <div class="text-[11px] text-gray-500">this meal</div>
                        <div class="font-display text-lg font-bold nums {{ $mTone[2] }}">{{ $meal['this_meal']['protein_g'] }}g</div>
                        <div class="text-[10px] text-gray-500 nums">protein · {{ number_format($meal['this_meal']['calories']) }} kcal</div>
                    </div>
                @endif
            </div>

            {{-- today's protein progress --}}
            <div class="mt-3">
                <div class="flex items-center justify-between text-[11px] text-gray-500 mb-1">
                    <span>Protein today</span>
                    <span class="nums">{{ $meal['consumed']['protein_g'] }} / {{ $meal['target']['protein_g'] }} g</span>
                </div>
                <div class="h-1.5 rounded-full bg-white/10 overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $proteinPct }}%"></div>
                </div>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <a href="/meals/add" class="inline-flex items-center gap-1.5 rounded-xl {{ $mTone[3] }} px-4 py-2 text-sm font-semibold {{ $meal['status'] === 'done' ? 'text-gray-200' : 'text-gray-950' }} active:opacity-90">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Log a meal
                </a>
                <a href="/meals" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-gray-200 active:bg-white/10">
                    <span>🍽️</span> Meal ideas
                </a>
            </div>
        </div>

        {{-- ============ TRAJECTORY — the graphs that show you're improving ============ --}}
        @if (count($trajectories) || $bioAge)
            <div>
                <h2 class="text-[11px] uppercase tracking-wider text-gray-500 mb-2 px-1">Your trajectory</h2>
                <div class="grid grid-cols-2 gap-3 md:gap-4">

                    @foreach ($trajectories as $t)
                        @php
                            $vals = $t['values']; $min = min($vals); $max = max($vals); $span = max($max - $min, 0.0001);
                            $n = count($vals); $w = 100; $h = 34;
                            $pts = collect($vals)->map(fn ($v, $i) => round($i / max($n - 1, 1) * $w, 1).','.round($h - ($v - $min) / $span * $h, 1))->implode(' ');
                            $tone = $t['improving'] === true ? 'text-emerald-300' : ($t['improving'] === false ? 'text-rose-300' : 'text-indigo-300');
                            $stroke = $t['improving'] === true ? '#6ee7b7' : ($t['improving'] === false ? '#fda4af' : '#a5b4fc');
                        @endphp
                        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $t['label'] }}</div>
                                    <div class="mt-0.5 font-display text-2xl font-bold nums text-gray-100 leading-none">{{ $t['current'] }}<span class="text-gray-500 text-sm font-normal">{{ $t['unit'] ? ' '.$t['unit'] : '' }}</span></div>
                                </div>
                                <div class="text-right shrink-0 text-xs nums {{ $tone }}">{{ $t['delta'] }}{{ $t['unit'] ? ' '.$t['unit'] : '' }}</div>
                            </div>
                            <svg viewBox="0 0 100 34" preserveAspectRatio="none" class="mt-3 w-full h-9">
                                <polyline points="{{ $pts }}" fill="none" stroke="{{ $stroke }}" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                            </svg>
                        </div>
                    @endforeach

                    {{-- Biological age — the "are you winning the long game" number --}}
                    @if ($bioAge)
                        @php
                            $aTone = match ($bioAge['band']) {
                                'much_younger', 'younger' => 'text-emerald-300', 'on_par' => 'text-cyan-300',
                                'older' => 'text-amber-300', default => 'text-orange-300',
                            };
                        @endphp
                        <a href="/recovery" class="block rounded-2xl border border-white/5 bg-white/[0.03] p-4 active:bg-white/[0.05]">
                            <div class="text-[11px] uppercase tracking-wide text-gray-500">Biological age</div>
                            <div class="mt-0.5 flex items-baseline gap-2">
                                <span class="font-display text-2xl font-bold nums {{ $aTone }} leading-none">{{ number_format($bioAge['biological_age'], 0) }}</span>
                                <span class="text-xs text-gray-500">vs {{ number_format($bioAge['chronological_age'], 0) }} actual</span>
                            </div>
                            <p class="mt-2 text-xs {{ $aTone }}">{{ $bioAge['delta'] <= 0 ? abs($bioAge['delta']).' yr younger' : '+'.$bioAge['delta'].' yr' }}<span class="text-gray-600"> · {{ $bioAge['confidence'] }} confidence</span></p>
                        </a>
                    @endif
                </div>
            </div>
        @endif

        {{-- ============ PROGRESS PHOTOS — the visual journey ============ --}}
        @if ($photos->count())
            <div>
                <div class="flex items-center justify-between mb-2 px-1">
                    <h2 class="text-[11px] uppercase tracking-wider text-gray-500">Your journey</h2>
                    <a href="/photos" class="text-[11px] font-medium text-indigo-400 active:text-indigo-300">All photos →</a>
                </div>
                <div class="flex gap-3 overflow-x-auto no-scrollbar pb-1 -mx-4 px-4 md:-mx-1 md:px-1 snap-x">
                    @foreach ($photos as $ph)
                        <a href="/photos" class="shrink-0 snap-start active:opacity-80">
                            <div class="relative h-40 w-28 overflow-hidden rounded-xl border border-white/10 bg-gray-950">
                                <img src="{{ $ph['url'] }}" alt="Progress {{ $ph['date'] }}" class="absolute inset-0 h-full w-full object-cover">
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-1.5">
                                    <div class="text-[10px] font-semibold text-gray-200 nums">{{ $ph['date'] }}</div>
                                    @if ($ph['weight'])<div class="text-[9px] text-gray-400 nums">{{ $ph['weight'] }}</div>@endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============ EXPLORE — slim links, the detail lives in each domain ============ --}}
        <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
            @foreach ([
                ['Coach', 'coach'], ['Meals', 'meals'], ['Workouts', 'workouts'],
                ['Bloodwork', 'biomarkers'], ['Brain', 'brain'], ['Duo', 'duo'],
            ] as [$label, $path])
                <a href="/{{ $path }}" class="rounded-xl border border-white/5 bg-white/[0.02] px-3 py-3 text-center text-xs font-semibold text-gray-300 transition hover:border-indigo-500/40 hover:bg-gray-900 active:bg-white/[0.06]">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    {{-- Live meal countdown (counts down to the next meal, then counts up while overdue) --}}
    <script>
        function mealCountdown(nextAt, status) {
            return {
                display: '',
                _t: null,
                init() {
                    if (!nextAt) return;
                    this.tick();
                    this._t = setInterval(() => this.tick(), 1000);
                },
                destroy() { if (this._t) clearInterval(this._t); },
                tick() {
                    const diffMs = new Date(nextAt).getTime() - Date.now();
                    const overdue = diffMs < 0;
                    let s = Math.floor(Math.abs(diffMs) / 1000);
                    const h = Math.floor(s / 3600); s -= h * 3600;
                    const m = Math.floor(s / 60); s -= m * 60;
                    let str = h > 0 ? `${h}h ${m}m` : (m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`);
                    this.display = overdue ? `${str} overdue` : `in ${str}`;
                },
            };
        }
    </script>
</x-titan-layout>
