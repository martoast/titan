<x-titan-layout title="Fitness" subtitle="VO₂max, cardio sessions & heart-rate recovery">
    @php
        $vo2 = $latestVo2?->vo2max;
        $vo2Tone = match (true) {
            $vo2 === null => 'text-gray-400',
            $vo2 >= 52 => 'text-emerald-300',
            $vo2 >= 44 => 'text-cyan-300',
            $vo2 >= 36 => 'text-amber-300',
            default => 'text-orange-300',
        };
        $levelLabel = $latestVo2?->fitness_level ? ucfirst($latestVo2->fitness_level) : null;
        $icon = fn ($t) => match ($t) {
            'run' => 'M13 4a1 1 0 100 2 1 1 0 000-2zM7 21l3-6 3 2 2 4M9 11l3-2 3 1 2-2',
            'cycle' => 'M5 18a3 3 0 100-6 3 3 0 000 6zm14 0a3 3 0 100-6 3 3 0 000 6zM9 15l3-7h3l-2 7M12 8l-1-3h3',
            'walk' => 'M13 4a1 1 0 100 2 1 1 0 000-2zM8 21l2-5 2 1 1 4M10 12l2-3 3 2',
            'strength' => 'M6.5 6.5l11 11M3 9l1.5-1.5M21 15l-1.5 1.5M5 7l-2 2 3 3M19 17l2-2-3-3',
            default => 'M3 12h3l2-7 4 14 2-7h7',
        };
    @endphp

    @if (session('status'))
        <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    {{-- Daily movement: today's steps vs the evidence-based personalized target --}}
    @php
        $stepTone = match ($stepGoal['band']) {
            'excellent' => 'text-emerald-300', 'good' => 'text-cyan-300',
            'fair' => 'text-amber-300', default => 'text-orange-300',
        };
        $stepStroke = match ($stepGoal['band']) {
            'excellent' => '#6ee7b7', 'good' => '#67e8f9', 'fair' => '#fcd34d', default => '#fb923c',
        };
        $r = 52; $circ = 2 * pi() * $r; $dash = $circ * $stepGoal['pct'] / 100;
    @endphp
    <div class="rounded-card border border-white/5 bg-gradient-to-b from-white/[0.06] to-white/[0.02] p-5 md:p-6 mb-4 shadow-card"
         x-data="{ edit: {{ $steps === 0 ? 'true' : 'false' }} }">
        <div class="flex items-center gap-5">
            {{-- Progress ring --}}
            <div class="relative shrink-0" style="width:128px;height:128px">
                <svg viewBox="0 0 128 128" class="w-32 h-32 -rotate-90">
                    <circle cx="64" cy="64" r="{{ $r }}" fill="none" stroke="rgba(255,255,255,0.07)" stroke-width="10"/>
                    <circle cx="64" cy="64" r="{{ $r }}" fill="none" stroke="{{ $stepStroke }}" stroke-width="10" stroke-linecap="round"
                            stroke-dasharray="{{ round($dash, 1) }} {{ round($circ, 1) }}"/>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="font-display text-2xl font-black nums {{ $stepTone }} leading-none">{{ number_format($steps) }}</span>
                    <span class="text-[10px] uppercase tracking-wider text-gray-500 mt-0.5">steps</span>
                </div>
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-[11px] uppercase tracking-wider text-gray-500">Today's movement</p>
                <h2 class="font-display text-2xl font-bold {{ $stepTone }} mt-0.5">{{ $stepGoal['label'] }}</h2>
                <p class="text-sm text-gray-400 mt-1 nums">
                    @if ($stepGoal['to_go'] > 0)
                        {{ number_format($stepGoal['to_go']) }} to your {{ number_format($stepGoal['target']) }} goal
                    @else
                        You passed your {{ number_format($stepGoal['target']) }} goal 🎉
                    @endif
                </p>
                <button type="button" @click="edit = !edit" x-show="!edit"
                        class="mt-3 rounded-chip border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-200 active:bg-white/10">Update steps</button>
                <form method="POST" action="{{ route('fitness.steps') }}" x-show="edit" x-cloak class="mt-3 flex items-center gap-2">
                    @csrf
                    <input type="number" name="steps" min="0" max="200000" value="{{ $steps ?: '' }}" inputmode="numeric" placeholder="steps today"
                           class="min-w-0 flex-1 h-10 rounded-chip bg-gray-900 border border-white/10 px-3 text-sm text-gray-100 nums focus:border-titan-cyan/50 focus:outline-none">
                    <button type="submit" class="h-10 rounded-chip bg-titan-cyan px-3 text-sm font-semibold text-gray-950 active:opacity-90">Save</button>
                </form>
            </div>
        </div>

        {{-- Honest, evidence-based framing --}}
        <p class="mt-4 text-[11px] text-gray-500 leading-relaxed">
            Your goal is age-personalized to where the science shows the mortality benefit plateaus — <span class="text-gray-400">~{{ number_format($stepGoal['target']) }} steps</span>, not the "10,000" myth. Each extra ~1,000 steps/day is linked to roughly <span class="text-gray-400">15% lower all-cause mortality</span>.
            @if ($weekAvgSteps !== null)<span class="text-gray-600"> · 7-day avg {{ number_format($weekAvgSteps) }}.</span>@endif
        </p>

        {{-- Guided sit-to-stand test — lower-body function / frailty screen (Rikli & Jones 30CST) --}}
        @php $cs = session('chairStand'); @endphp
        <div class="mt-3 glass-card p-4" x-data="{ open: {{ $cs ? 'true' : 'false' }} }">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Functional fitness</div>
                    <h3 class="font-display font-bold text-gray-100 mt-0.5">30-second chair-stand test</h3>
                </div>
                <button type="button" @click="open = !open" class="shrink-0 rounded-chip border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-200 active:bg-white/10" x-text="open ? 'Hide' : 'Take the test'"></button>
            </div>
            @if ($cs)
                @php $csTone = match ($cs['band']) { 'good' => 'text-titan-mint', 'average' => 'text-titan-cyan', default => 'text-titan-amber' }; @endphp
                <div class="mt-3 rounded-chip bg-gray-950/50 border border-white/5 p-3.5">
                    <div class="flex items-baseline gap-2">
                        <span class="font-display text-3xl font-black nums {{ $csTone }} leading-none">{{ $cs['reps'] }}</span>
                        <span class="text-sm text-gray-500">stands in 30 s</span>
                    </div>
                    <p class="mt-1 text-sm {{ $csTone }}">{{ $cs['label'] }}</p>
                    <p class="mt-1 text-[11px] text-gray-600">Below-average for your age is under {{ $cs['age_below_cut'] }}; strong is {{ $cs['good_at'] }}+.</p>
                </div>
            @endif
            <div x-show="open" x-cloak class="mt-3">
                <p class="text-sm text-gray-400 leading-relaxed">Sit in a sturdy chair, arms crossed over your chest. Stand up fully and sit back down as many times as you can in <span class="text-gray-200">30 seconds</span>. Count each full stand, then enter it.</p>
                <form method="POST" action="{{ route('fitness.chair-stand') }}" class="mt-3 flex items-center gap-2">
                    @csrf
                    <input type="number" name="reps" min="0" max="60" inputmode="numeric" placeholder="stands in 30s" required
                           class="w-36 h-10 rounded-chip bg-gray-900 border border-white/10 px-3 text-sm text-gray-100 nums focus:border-titan-cyan/50 focus:outline-none">
                    <button type="submit" class="h-10 rounded-chip bg-titan-cyan px-4 text-sm font-semibold text-gray-950 active:opacity-90">Score it</button>
                </form>
                <p class="mt-2 text-[10px] text-gray-600">Gait speed is the "sixth vital sign" (Studenski 2011). A wellness screen, not a diagnosis.</p>
            </div>
        </div>

        {{-- Floors climbed (barometer) — ≥35/wk → all-cause mortality HR 0.84 (Harvard Alumni) --}}
        @if ($floorsToday > 0)
            <div class="mt-4 flex items-center gap-3 glass-card p-4">
                <div class="font-display text-2xl font-black nums text-titan-mint leading-none">{{ $floorsToday }}</div>
                <div class="text-sm text-gray-400">floors climbed today<span class="block text-[11px] text-gray-600">from the barometer — stairs are one of the cheapest longevity wins.</span></div>
            </div>
        @endif

        {{-- Movement breaks: how many waking hours had real movement (don't sit too long) --}}
        @if ($movement)
            @php $mvTone = $movement['met'] ? 'text-titan-mint' : ($movement['longest_sit'] >= 4 ? 'text-orange-300' : 'text-titan-amber'); @endphp
            <div class="mt-4 glass-card p-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Active hours</div>
                        <div class="font-display text-xl font-bold nums {{ $mvTone }} leading-none mt-0.5">{{ $movement['active'] }}<span class="text-gray-500 text-sm font-normal">/{{ $movement['waking'] }} waking hrs moved</span></div>
                    </div>
                    @if ($movement['longest_sit'] >= 3)
                        <div class="text-right shrink-0">
                            <div class="text-[11px] uppercase tracking-wide text-gray-500">Longest sit</div>
                            <div class="font-display text-xl font-bold nums text-gray-300 leading-none mt-0.5">{{ $movement['longest_sit'] }}h</div>
                        </div>
                    @endif
                </div>
                <p class="mt-3 text-[11px] text-gray-500 leading-relaxed">
                    Breaking up long sits — a 2-minute walk after meals or every half-hour — cuts post-meal blood-sugar spikes by ~25%. It's about <span class="text-gray-400">when</span> you move, not just how much.
                </p>
            </div>
        @endif
    </div>

    {{-- VO2max hero --}}
    <div class="rounded-card border border-white/5 bg-gradient-to-b from-white/[0.06] to-white/[0.02] p-6 text-center shadow-card">
        <p class="text-[11px] uppercase tracking-wider text-gray-500">Estimated VO₂max</p>
        <div class="mt-1 flex items-end justify-center gap-2">
            <span class="font-display text-6xl font-black nums {{ $vo2Tone }} leading-none">{{ $vo2 !== null ? number_format($vo2, 1) : '—' }}</span>
            <span class="mb-1 text-sm text-gray-500">ml/kg/min</span>
        </div>
        @if ($vo2 !== null)
            <p class="mt-2 text-sm {{ $vo2Tone }} font-semibold">{{ $levelLabel }} <span class="text-gray-500 font-normal">· ± {{ $latestVo2->plusminus ?? '5.6' }} — track the trend, not one reading</span></p>

            {{-- Lightweight VO2max trend sparkline --}}
            @if ($vo2Trend->count() >= 2)
                @php
                    $vals = $vo2Trend->pluck('vo2max');
                    $min = $vals->min() - 1; $max = $vals->max() + 1; $span = max($max - $min, 0.1);
                    $w = 280; $h = 48; $n = $vals->count();
                    $pts = $vo2Trend->values()->map(function ($p, $i) use ($w, $h, $n, $min, $span) {
                        $x = $n > 1 ? ($i / ($n - 1)) * $w : 0;
                        $y = $h - (($p['vo2max'] - $min) / $span) * $h;
                        return round($x, 1).','.round($y, 1);
                    })->implode(' ');
                @endphp
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mx-auto mt-4 w-full max-w-xs" preserveAspectRatio="none">
                    <polyline points="{{ $pts }}" fill="none" stroke="currentColor" stroke-width="2" class="{{ $vo2Tone }}" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <p class="text-[10px] uppercase tracking-wider text-gray-600">VO₂max over your last {{ $vo2Trend->count() }} runs</p>
            @endif
        @else
            <p class="mt-2 text-sm text-gray-400">Log a GPS-paced run with the band to calibrate your VO₂max.</p>
        @endif
    </div>

    {{-- This-week load --}}
    <div class="mt-3 grid grid-cols-2 gap-3">
        <div class="glass-card p-4">
            <div class="text-[11px] uppercase tracking-wide text-gray-500">This week · load</div>
            <div class="font-display text-3xl font-bold nums text-titan-indigo mt-1 leading-none">{{ $weekTrimp > 0 ? number_format($weekTrimp, 0) : '—' }}<span class="text-gray-500 text-base font-normal"> TRIMP</span></div>
        </div>
        <div class="glass-card p-4">
            <div class="text-[11px] uppercase tracking-wide text-gray-500">Latest recovery</div>
            <div class="font-display text-3xl font-bold nums text-titan-cyan mt-1 leading-none">{{ $latestHrr !== null ? number_format($latestHrr, 0) : '—' }}<span class="text-gray-500 text-base font-normal"> HRR</span></div>
        </div>
    </div>

    {{-- Training-load guardrail: acute:chronic workload ratio (the "don't ramp too fast" check) --}}
    @if ($trainingLoad)
        @php
            $tl = $trainingLoad;
            $tone = match ($tl['band']) {
                'optimal' => ['border-emerald-500/20', 'bg-emerald-500/[0.07]', 'text-emerald-300'],
                'caution' => ['border-amber-500/25', 'bg-amber-500/[0.07]', 'text-amber-300'],
                'high' => ['border-rose-500/25', 'bg-rose-500/[0.07]', 'text-rose-300'],
                'detraining' => ['border-sky-500/20', 'bg-sky-500/[0.06]', 'text-sky-300'],
                default => ['border-white/10', 'bg-white/[0.03]', 'text-gray-300'],
            };
            // Balance bar: where ACWR sits across 0.5–2.0, with the 0.8–1.3 sweet spot shaded.
            $pos = $tl['acwr'] !== null ? max(0, min(100, (($tl['acwr'] - 0.5) / 1.5) * 100)) : null;
        @endphp
        <div class="mt-3 rounded-card border {{ $tone[0] }} {{ $tone[1] }} p-4 shadow-card">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Training load · {{ $tl['label'] }}</div>
                    <div class="mt-1 font-display text-3xl font-bold nums {{ $tone[2] }} leading-none">
                        {{ $tl['acwr'] !== null ? number_format($tl['acwr'], 2) : '—' }}<span class="text-gray-500 text-base font-normal"> ACWR</span>
                    </div>
                </div>
                <div class="text-right text-[11px] text-gray-500 nums shrink-0">
                    <div>acute {{ number_format($tl['acute'], 0) }}</div>
                    <div>chronic {{ number_format($tl['chronic'], 0) }}</div>
                </div>
            </div>
            @if ($pos !== null)
                <div class="relative mt-3 h-1.5 rounded-full bg-white/5">
                    {{-- sweet spot 0.8–1.3 → (0.3/1.5)..(0.8/1.5) of the 0.5–2.0 track --}}
                    <div class="absolute inset-y-0 rounded-full bg-emerald-500/20" style="left:20%;right:46.7%"></div>
                    <div class="absolute -top-0.5 h-2.5 w-2.5 rounded-full {{ $tone[2] }} bg-current -translate-x-1/2" style="left:{{ $pos }}%"></div>
                </div>
            @endif
            <p class="mt-2.5 text-xs text-gray-400 leading-relaxed">{{ $tl['advice'] }}</p>
            @if ($tl['sufficient'])
                <p class="mt-1.5 text-[10px] text-gray-600">Acute (7-day) vs chronic (28-day) load, EWMA. A guide to progress gradually — not a medical prediction.</p>
            @endif
        </div>
    @endif

    {{-- Sessions --}}
    <div class="mt-6"><x-section-header title="Recent sessions" /></div>
    @if ($sessions->isEmpty())
        <div class="rounded-card border border-dashed border-white/10 bg-white/[0.02] p-8 text-center text-sm text-gray-400">
            No cardio sessions yet. Start a workout on your band — runs, rides and walks land here automatically.
        </div>
    @else
        <div class="space-y-2">
            @foreach ($sessions as $s)
                @php $hasRoute = $s->hasRoute(); $tag = $hasRoute ? 'a' : 'div'; @endphp
                <{{ $tag }} @if ($hasRoute) href="{{ route('fitness.run', $s) }}" @endif
                    class="flex items-center gap-3 glass-card p-3.5 @if ($hasRoute) hover:bg-white/[0.07] transition @endif">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-chip bg-titan-cyan/10 text-titan-cyan">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($s->activity_type) }}" /></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="font-semibold text-gray-100 truncate">{{ $s->title() }}</span>
                            <span class="text-[11px] text-gray-500 shrink-0">{{ $s->started_at->diffForHumans() }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[12px] text-gray-400 nums">
                            @if ($s->duration_min)<span>{{ $s->duration_min }} min</span>@endif
                            @if ($s->distance_km)<span>{{ number_format($s->distance_km, 1) }} km</span>@endif
                            @if ($s->max_hr)<span><span class="text-titan-pink">{{ $s->max_hr }} peak</span><span class="text-gray-600">@if ($s->avg_hr) · {{ $s->avg_hr }} avg @endif</span> bpm</span>@elseif ($s->avg_hr)<span>{{ $s->avg_hr }} avg bpm</span>@endif
                            @if ($s->hardZoneMin() >= 0.5)<span class="text-rose-400/80" title="Minutes at ≥80% of your max HR — the hard zones">{{ rtrim(rtrim(number_format($s->hardZoneMin(), 1), '0'), '.') }} min hard</span>@endif
                            @if ($s->avg_hr && $s->hrSourceLabel())<span class="{{ $s->hr_source === 'onchip' ? 'text-gray-600' : 'text-titan-mint/80' }}" title="How this session's heart rate was measured">{{ $s->hrSourceLabel() }}</span>@endif
                            @if ($s->trimp)<span>TRIMP {{ number_format($s->trimp, 0) }}</span>@endif
                            @if ($s->calories_kcal)<span>{{ $s->calories_kcal }} kcal</span>@endif
                            @if ($s->hrr_bpm)<span class="text-titan-cyan/80">HRR {{ number_format($s->hrr_bpm, 0) }}</span>@endif
                            @if ($s->vo2max)<span class="text-titan-mint/80">VO₂ {{ number_format($s->vo2max, 1) }}</span>@endif
                        </div>
                    </div>
                    @if ($hasRoute)
                        <svg class="h-4 w-4 shrink-0 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                    @endif
                </{{ $tag }}>
            @endforeach
        </div>
    @endif
</x-titan-layout>
