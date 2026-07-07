<x-titan-layout title="Today" subtitle="How you are, and what's next">
    <div class="space-y-4 md:space-y-5">

        {{-- ============ FUTURE SELF — compact dream-physique panel ============ --}}
        <a href="/photos" class="block group">
            <div class="rounded-card border border-titan-indigo/20 bg-gradient-to-br from-titan-indigo/15 via-titan-bg2 to-titan-cyan/10 p-4 shadow-card">
                @if ($futureSelf['image'])
                    <div class="flex items-center gap-4">
                        {{-- Now → Future thumbnails (fixed small height) --}}
                        <div class="flex shrink-0 gap-1.5">
                            <div class="relative h-24 aspect-[3/4] overflow-hidden rounded-xl bg-gray-950 sm:h-28">
                                @if ($futureSelf['now_image'])
                                    <img src="{{ $futureSelf['now_image'] }}" alt="Now" class="h-full w-full object-cover opacity-90">
                                @else
                                    <div class="grid h-full place-items-center px-1 text-center text-[10px] text-gray-600">Add a photo</div>
                                @endif
                                <span class="absolute top-1 left-1 rounded bg-black/55 px-1.5 py-px text-[9px] uppercase tracking-wide text-gray-300">Now</span>
                            </div>
                            <div class="relative h-24 aspect-[3/4] overflow-hidden rounded-xl bg-gray-950 ring-1 ring-indigo-400/30 sm:h-28">
                                <img src="{{ $futureSelf['image'] }}" alt="Your future self" class="h-full w-full object-cover">
                                <span class="absolute top-1 right-1 rounded bg-indigo-500/85 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-white">Future</span>
                            </div>
                        </div>
                        {{-- Progress --}}
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-titan-indigo/90">Toward your dream physique</div>
                            <div class="mt-0.5 font-display text-2xl font-bold leading-none text-gray-100">
                                @if ($futureSelf['pct'] !== null){{ $futureSelf['pct'] }}%<span class="ml-1 text-sm font-normal text-gray-500">there</span>@else Tracking @endif
                            </div>
                            @if ($futureSelf['pct'] !== null)
                                <div class="mt-2.5 h-2 rounded-full bg-white/10 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-titan-indigo to-titan-cyan" style="width: {{ max(3, min(100, $futureSelf['pct'])) }}%"></div>
                                </div>
                            @endif
                            <div class="mt-2 flex items-center gap-1.5 text-[11px] text-gray-500">
                                @if ($futureSelf['adherence'] !== null)
                                    <span class="font-semibold text-titan-cyan">{{ $futureSelf['adherence'] }}%</span> consistent ·
                                @endif
                                <span class="text-titan-indigo/80 group-active:text-titan-indigo">open Physique →</span>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- No goal yet → compact CTA --}}
                    <div class="flex items-center gap-4">
                        <div class="grid h-16 w-16 shrink-0 place-items-center rounded-chip bg-titan-indigo/15">
                            <svg class="h-8 w-8 text-titan-indigo" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.5-4.5a2 2 0 012.8 0L16 16m-2-2l1.5-1.5a2 2 0 012.8 0L20 14M4 6h16v12H4z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-display text-lg font-bold text-gray-100">Meet your future self</h2>
                            <p class="mt-0.5 text-[13px] leading-snug text-gray-400">Upload a photo and Titan renders your dream physique — it advances as you stay consistent.</p>
                        </div>
                        <span class="hidden shrink-0 rounded-chip bg-titan-indigo px-3.5 py-2 text-sm font-semibold text-white group-active:opacity-90 sm:inline-block">Create →</span>
                    </div>
                @endif
            </div>
        </a>

        {{-- ============ TODAY'S FOCUS — the one thing to work on ============ --}}
        @php
            $focusTone = match ($focus['focus']) {
                'recover' => ['border-titan-amber/25', 'from-titan-amber/[0.10]', 'text-titan-amber'],
                'sleep' => ['border-titan-indigo/25', 'from-titan-indigo/[0.10]', 'text-titan-indigo'],
                'push' => ['border-titan-mint/25', 'from-titan-mint/[0.10]', 'text-titan-mint'],
                default => ['border-titan-cyan/20', 'from-titan-cyan/[0.08]', 'text-titan-cyan'],
            };
        @endphp
        <div class="rounded-card border {{ $focusTone[0] }} bg-gradient-to-b {{ $focusTone[1] }} to-transparent p-4 md:p-5 shadow-card">
            <div class="flex items-center justify-between">
                <div class="text-[11px] uppercase tracking-wider {{ $focusTone[2] }} font-semibold">Today's focus</div>
                @if ($today['from_wearable'])
                    <span class="inline-flex items-center gap-1 text-[10px] font-medium text-titan-mint/90">
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
                $today['readiness'] >= 67 => 'text-titan-mint', $today['readiness'] >= 34 => 'text-titan-amber',
                default => 'text-titan-pink',
            };
            $sTone = match ($strain['band']) {
                'all_out', 'high' => 'text-titan-cyan', 'moderate' => 'text-titan-indigo', default => 'text-gray-300',
            };
            $perf = $sleepCoach['performance_pct'] ?? null;
            $slTone = $perf === null ? 'text-gray-400' : ($perf >= 88 ? 'text-titan-mint' : ($perf >= 78 ? 'text-titan-indigo' : 'text-titan-amber'));
        @endphp
        <div class="grid grid-cols-3 gap-3 md:gap-4">
            {{-- Recovery --}}
            <a href="/recovery" class="glass-card p-3.5 md:p-4 active:bg-white/[0.07]">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Recovery</div>
                <div class="font-display text-2xl md:text-3xl font-bold nums {{ $rTone }} mt-0.5 leading-none">{{ $today['readiness'] ?? '—' }}<span class="text-gray-600 text-sm font-normal">{{ $today['readiness'] !== null ? '%' : '' }}</span></div>
                <div class="text-[11px] text-gray-500 mt-1 truncate">{{ $today['readiness_label'] ?: 'connect a device' }}</div>
            </a>
            {{-- Strain (vs recovery-driven target) --}}
            <a href="/fitness" class="glass-card p-3.5 md:p-4 active:bg-white/[0.07]">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Strain</div>
                <div class="font-display text-2xl md:text-3xl font-bold nums {{ $sTone }} mt-0.5 leading-none">{{ number_format($strain['strain'], 1) }}<span class="text-gray-600 text-sm font-normal nums"> / {{ number_format($strain['target']['high'], 0) }}</span></div>
                <div class="text-[11px] text-gray-500 mt-1 truncate">{{ $strain['target']['label'] }}</div>
            </a>
            {{-- Sleep (performance vs need) --}}
            <a href="/sleep" class="glass-card p-3.5 md:p-4 active:bg-white/[0.07]">
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
            <a href="/cycle" class="glass-card block p-4 active:bg-white/[0.07] transition">
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
            // Overdue is a warm nudge, not an alarm — amber, never red. (Red reads as "something's wrong".)
            $mTone = match ($meal['status']) {
                'overdue' => ['border-titan-amber/30', 'from-titan-amber/[0.10]', 'text-titan-amber', 'bg-titan-amber'],
                'soon' => ['border-titan-amber/25', 'from-titan-amber/[0.09]', 'text-titan-amber', 'bg-titan-amber'],
                'done' => ['border-titan-mint/20', 'from-titan-mint/[0.06]', 'text-titan-mint', 'bg-white/10'],
                default => ['border-titan-indigo/20', 'from-titan-indigo/[0.07]', 'text-titan-indigo', 'bg-titan-indigo'],
            };
            $proteinPct = $meal['target']['protein_g'] > 0 ? min(100, round($meal['consumed']['protein_g'] / $meal['target']['protein_g'] * 100)) : 0;
        @endphp
        <div class="rounded-card border {{ $mTone[0] }} bg-gradient-to-b {{ $mTone[1] }} to-transparent p-4 md:p-5 shadow-card"
             x-data="mealCountdown(@js($meal['next_at']), @js($meal['status']))">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[11px] uppercase tracking-wider {{ $mTone[2] }} font-semibold flex items-center gap-1.5">
                        <span>🍽️</span> {{ $meal['label'] }}
                    </div>
                    {{-- A live "in 25m" countdown is genuinely useful; a giant "15h overdue" clock just nags.
                         So show the countdown only while a meal is upcoming/soon, and let the supportive
                         copy + protein target lead once it's overdue. --}}
                    @if ($meal['status'] === 'done')
                        <div class="mt-1 font-display text-2xl font-bold text-titan-mint leading-none">{{ $meal['meals_logged'] }}/{{ $meal['meals_planned'] }} meals</div>
                    @elseif (in_array($meal['status'], ['upcoming', 'soon'], true))
                        <div class="mt-1 font-display text-2xl font-bold nums {{ $mTone[2] }} leading-none" x-text="display"></div>
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
                    <div class="h-full rounded-full bg-gradient-to-r from-titan-indigo to-titan-cyan" style="width: {{ $proteinPct }}%"></div>
                </div>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <a href="/meals/add" class="inline-flex items-center gap-1.5 rounded-chip {{ $mTone[3] }} px-4 py-2 text-sm font-semibold {{ $meal['status'] === 'done' ? 'text-gray-200' : 'text-gray-950' }} active:opacity-90">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Log a meal
                </a>
                <a href="/meals" class="inline-flex items-center gap-1.5 rounded-chip border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-gray-200 active:bg-white/10">
                    <span>🍽️</span> Meal ideas
                </a>
            </div>
        </div>

        {{-- ============ THE LONG GAME — one calm doorway to the full review on /progress ============ --}}
        @php
            $aTone = $bioAge ? match ($bioAge['band']) {
                'much_younger', 'younger' => 'text-emerald-300', 'on_par' => 'text-cyan-300',
                'older' => 'text-amber-300', default => 'text-orange-300',
            } : 'text-gray-300';
        @endphp
        <a href="/progress" class="glass-card block p-4 active:bg-white/[0.07] transition">
            <div class="flex items-center gap-4">
                @if ($bioAge)
                    <div class="shrink-0">
                        <div class="font-display text-3xl font-bold nums {{ $aTone }} leading-none">{{ number_format($bioAge['biological_age'], 0) }}</div>
                        <div class="mt-1 text-[10px] uppercase tracking-wide text-gray-500">bio age</div>
                    </div>
                    <div class="h-10 w-px bg-white/10"></div>
                @endif
                <div class="min-w-0 flex-1">
                    <div class="font-display font-bold text-gray-100">Your progress</div>
                    <p class="mt-0.5 text-[13px] leading-snug text-gray-400">
                        @if ($bioAge){{ $bioAge['delta'] <= 0 ? abs($bioAge['delta']).' yrs younger than your age — ' : '' }}@endif
                        weight, body fat &amp; physique trends
                    </p>
                </div>
                <span class="shrink-0 text-titan-violet/80 group-active:text-titan-violet">→</span>
            </div>
        </a>
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
