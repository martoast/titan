<x-titan-layout title="Today" subtitle="How you are, and what's next">
    @php
        // ── Recovery (the hero read) ─────────────────────────────────────────────
        $rScore = $today['readiness'];
        [$rHex, $rWord] = match (true) {
            $rScore === null       => ['#9ca3af', 'text-gray-400'],
            $rScore >= 67          => ['#34E5C0', 'text-titan-mint'],
            $rScore >= 34          => ['#FFB020', 'text-titan-amber'],
            default                => ['#FF4D8D', 'text-titan-pink'],
        };

        // ── Focus tone (hero tint + eyebrow) ─────────────────────────────────────
        $focusTone = match ($focus['focus']) {
            'recover' => ['border-titan-amber/25',  'from-titan-amber/[0.10]',  'text-titan-amber'],
            'sleep'   => ['border-titan-indigo/25', 'from-titan-indigo/[0.10]', 'text-titan-indigo'],
            'push'    => ['border-titan-mint/25',   'from-titan-mint/[0.10]',   'text-titan-mint'],
            default   => ['border-titan-cyan/20',   'from-titan-cyan/[0.08]',   'text-titan-cyan'],
        };

        // ── The day's four pillars ───────────────────────────────────────────────
        $slPerf = $sleepCoach['performance_pct'] ?? null;
        $slLastH = $sleepCoach['last_h'] ?? null;

        $stStrain = $strain['strain'];
        $stHigh   = max(1, $strain['target']['high']);

        $cal   = $macros['calories'];
        $pro   = $macros['protein'];
        $carb  = $macros['carbs'];
        $fat   = $macros['fat'];
        $calPct = $cal['target'] > 0 ? min(100, (int) round($cal['value'] / $cal['target'] * 100)) : 0;

        $hydL   = number_format($hydration['total_ml'] / 1000, 1);
        $hydTgt = number_format($hydration['target_ml'] / 1000, 1);

        // A single ring geometry, reused by the hero + hydration bespoke rings.
        $ringPath = function (float $pct, float $r = 46.0) {
            $circ = 2 * M_PI * $r;
            return ['circ' => $circ, 'offset' => $circ * (1 - max(0.0, min(1.0, $pct)))];
        };
        $rec = $ringPath($rScore !== null ? $rScore / 100 : 0);
        $hyd = $ringPath(min(100, $hydration['pct']) / 100);
    @endphp

    <div class="space-y-5">

        {{-- ═══════════════ HERO — how you are today (Recovery + Focus) ═══════════════ --}}
        <div class="relative overflow-hidden rounded-card border {{ $focusTone[0] }} bg-gradient-to-br {{ $focusTone[1] }} via-titan-bg2 to-transparent p-5 md:p-7 shadow-card">
            {{-- ambient glow keyed to recovery --}}
            <div class="pointer-events-none absolute -right-24 -top-24 h-64 w-64 rounded-full opacity-20 blur-3xl" style="background:{{ $rHex }}"></div>

            <div class="relative flex flex-col items-center gap-6 md:flex-row md:gap-8">
                {{-- Recovery ring --}}
                <a href="/recovery" class="group shrink-0">
                    <div class="relative h-40 w-40 md:h-44 md:w-44">
                        <svg viewBox="0 0 108 108" class="h-full w-full -rotate-90" style="filter: drop-shadow(0 0 12px {{ $rHex }}55);">
                            <circle cx="54" cy="54" r="46" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="8"/>
                            <circle cx="54" cy="54" r="46" fill="none" stroke="url(#recRing)" stroke-width="8" stroke-linecap="round"
                                    stroke-dasharray="{{ $rec['circ'] }}" stroke-dashoffset="{{ $rec['offset'] }}"/>
                            <defs>
                                <linearGradient id="recRing" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="{{ $rHex }}" stop-opacity="0.55"/>
                                    <stop offset="100%" stop-color="{{ $rHex }}"/>
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="absolute inset-0 grid place-items-center text-center">
                            <div>
                                <div class="font-display text-5xl font-bold leading-none nums text-gray-50">{{ $rScore ?? '—' }}<span class="align-top text-xl text-gray-500">{{ $rScore !== null ? '%' : '' }}</span></div>
                                <div class="mt-1.5 text-[0.6rem] font-bold uppercase tracking-[0.16em] text-gray-500">Recovery</div>
                            </div>
                        </div>
                    </div>
                </a>

                {{-- Focus copy --}}
                <div class="min-w-0 flex-1 text-center md:text-left">
                    <div class="flex items-center justify-center gap-2 md:justify-start">
                        <span class="text-[0.65rem] font-bold uppercase tracking-[0.16em] {{ $focusTone[2] }}">Today's focus</span>
                        @if ($today['from_wearable'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-titan-mint/10 px-2 py-0.5 text-[10px] font-semibold text-titan-mint/90">
                                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.55a11 11 0 0114 0M8.5 16.05a6 6 0 017 0M2 9.05a16 16 0 0120 0M12 20h.01"/></svg>
                                Live
                            </span>
                        @endif
                    </div>
                    <h1 class="mt-2 font-display text-3xl font-bold leading-tight text-gray-50 md:text-4xl">{{ $focus['headline'] }}</h1>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-gray-400 md:mx-0">{{ $focus['detail'] }}</p>
                    <div class="mt-1 text-xs {{ $rWord }}">{{ $today['readiness_label'] ?: 'Connect a device to unlock recovery' }}</div>
                </div>
            </div>
        </div>

        {{-- ═══════════════ YOUR DAY — sleep · training (the measured pillars) ═══════════════ --}}
        <div class="grid grid-cols-2 gap-3 md:gap-4">
            {{-- Sleep --}}
            <a href="/sleep" class="glass-card flex flex-col items-center gap-3 p-4 md:p-5 active:bg-white/[0.07]">
                <x-stat-ring color="indigo" size="112"
                    :value="$slPerf" :max="100"
                    :display="$slLastH !== null ? rtrim(rtrim(number_format($slLastH, 1), '0'), '.').'h' : '—'" />
                <div class="text-center">
                    <div class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-gray-500">Sleep</div>
                    <div class="mt-0.5 text-xs text-gray-400">
                        {{ $sleepCoach['label'] ?? 'No nights yet' }}
                        @if ($sleepCoach && ($sleepCoach['need_h'] ?? null)) · need {{ rtrim(rtrim(number_format($sleepCoach['need_h'], 1), '0'), '.') }}h @endif
                    </div>
                </div>
            </a>

            {{-- Training (strain vs recovery-driven target) --}}
            <a href="/fitness" class="glass-card flex flex-col items-center gap-3 p-4 md:p-5 active:bg-white/[0.07]">
                <x-stat-ring color="cyan" size="112"
                    :value="$stStrain" :max="$stHigh"
                    :display="number_format($stStrain, 1)" />
                <div class="text-center">
                    <div class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-gray-500">Training</div>
                    <div class="mt-0.5 text-xs text-gray-400">{{ $strain['target']['label'] }} · to {{ number_format($stHigh, 0) }}</div>
                </div>
            </a>
        </div>

        {{-- ═══════════════ FUEL & HYDRATION — the pillars you act on ═══════════════ --}}
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 md:gap-4">

            {{-- Fuel: calories ring + macro split + log --}}
            <x-card pad="p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-titan-amber">Fuel</div>
                        <div class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-display text-3xl font-bold leading-none nums text-gray-50">{{ number_format($cal['value']) }}</span>
                            <span class="text-sm text-gray-500 nums">/ {{ number_format($cal['target']) }} kcal</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">{{ $macros['footer'] }}</div>
                    </div>
                    <x-stat-ring color="amber" size="72" :value="$cal['value']" :max="max(1, $cal['target'])" :display="$calPct.'%'" />
                </div>

                {{-- macro split --}}
                <div class="mt-4 space-y-3">
                    @foreach ([
                        ['Protein', $pro, 'bg-titan-mint',  'text-titan-mint'],
                        ['Carbs',   $carb, 'bg-titan-amber', 'text-titan-amber'],
                        ['Fat',     $fat,  'bg-titan-pink',  'text-titan-pink'],
                    ] as [$mLabel, $m, $barColor, $txtColor])
                        @php $mPct = $m['target'] > 0 ? min(100, (int) round($m['value'] / $m['target'] * 100)) : 0; @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between text-[11px]">
                                <span class="font-semibold {{ $txtColor }}">{{ $mLabel }}</span>
                                <span class="text-gray-500 nums">{{ $m['value'] }} / {{ $m['target'] }} g</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-white/10">
                                <div class="h-full rounded-full {{ $barColor }}" style="width: {{ max(2, $mPct) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <a href="/meals/add" class="mt-4 inline-flex w-full items-center justify-center gap-1.5 rounded-chip bg-titan-amber px-4 py-2.5 text-sm font-bold text-gray-950 active:opacity-90">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Log a meal
                </a>
            </x-card>

            {{-- Hydration: ring + quick-add --}}
            <x-card pad="p-5">
                <div class="flex items-center justify-between">
                    <div class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-titan-cyan">Hydration</div>
                    <div class="text-xs font-semibold text-titan-cyan nums">{{ $hydration['pct'] }}%</div>
                </div>
                <div class="mt-3 flex items-center gap-5">
                    {{-- ring --}}
                    <div class="relative h-24 w-24 shrink-0">
                        <svg viewBox="0 0 108 108" class="h-full w-full -rotate-90" style="filter: drop-shadow(0 0 10px #38bdf855);">
                            <circle cx="54" cy="54" r="46" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="8"/>
                            <circle cx="54" cy="54" r="46" fill="none" stroke="url(#hydRing)" stroke-width="8" stroke-linecap="round"
                                    stroke-dasharray="{{ $hyd['circ'] }}" stroke-dashoffset="{{ $hyd['offset'] }}"/>
                            <defs>
                                <linearGradient id="hydRing" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="#22d3ee"/><stop offset="100%" stop-color="#38bdf8"/>
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="absolute inset-0 grid place-items-center text-center">
                            <div>
                                <div class="font-display text-xl font-bold leading-none nums text-gray-50">{{ $hydL }}</div>
                                <div class="mt-0.5 text-[10px] text-gray-500 nums">of {{ $hydTgt }}L</div>
                            </div>
                        </div>
                    </div>
                    {{-- quick-add --}}
                    <div class="flex-1 space-y-2">
                        @foreach ([['Glass', 250], ['Bottle', 500], ['Large', 750]] as [$label, $ml])
                            <form method="POST" action="{{ route('meals.water') }}">
                                @csrf
                                <input type="hidden" name="ml" value="{{ $ml }}">
                                <button type="submit" class="flex w-full items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-3.5 py-2 text-sm text-gray-200 transition active:bg-white/10 hover:bg-white/[0.07]">
                                    <svg class="h-3.5 w-3.5 text-titan-cyan" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2s6 6.4 6 11a6 6 0 11-12 0c0-4.6 6-11 6-11z"/></svg>
                                    <span class="font-semibold">{{ $label }}</span>
                                    <span class="ml-auto text-xs text-gray-500 nums">+{{ $ml }} ml</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </x-card>
        </div>

        {{-- ═══════════════ CYCLE — phase-aware context (women) ═══════════════ --}}
        @if (! empty($cycle))
            @php
                $cycColors = ['menstrual'=>'#fb7185','follicular'=>'#34d399','fertile'=>'#22d3ee','ovulation'=>'#a78bfa','luteal'=>'#fbbf24','unknown'=>'#9ca3af'];
                $cycC = $cycColors[$cycle['phase']] ?? '#9ca3af';
                $cycNext = $cycle['next_period']['in_days'] ?? null;
            @endphp
            <a href="/cycle" class="glass-card block p-4 active:bg-white/[0.07]">
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
                        <p class="mt-0.5 truncate text-xs text-gray-400">{{ $cycle['note'] }}</p>
                    </div>
                </div>
            </a>
        @endif

        {{-- ═══════════════ FUTURE SELF — the signature long game ═══════════════ --}}
        <a href="/photos" class="group block">
            <div class="rounded-card border border-titan-indigo/20 bg-gradient-to-br from-titan-indigo/15 via-titan-bg2 to-titan-cyan/10 p-4 shadow-card">
                @if ($futureSelf['image'])
                    <div class="flex items-center gap-4">
                        <div class="flex shrink-0 gap-1.5">
                            <div class="relative aspect-[3/4] h-24 overflow-hidden rounded-xl bg-gray-950 sm:h-28">
                                @if ($futureSelf['now_image'])
                                    <img src="{{ $futureSelf['now_image'] }}" alt="Now" class="h-full w-full object-cover opacity-90">
                                @else
                                    <div class="grid h-full place-items-center px-1 text-center text-[10px] text-gray-600">Add a photo</div>
                                @endif
                                <span class="absolute left-1 top-1 rounded bg-black/55 px-1.5 py-px text-[9px] uppercase tracking-wide text-gray-300">Now</span>
                            </div>
                            <div class="relative aspect-[3/4] h-24 overflow-hidden rounded-xl bg-gray-950 ring-1 ring-indigo-400/30 sm:h-28">
                                <img src="{{ $futureSelf['image'] }}" alt="Your future self" class="h-full w-full object-cover">
                                <span class="absolute right-1 top-1 rounded bg-indigo-500/85 px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-white">Future</span>
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-titan-indigo/90">Toward your dream physique</div>
                            <div class="mt-0.5 font-display text-2xl font-bold leading-none text-gray-100">
                                @if ($futureSelf['pct'] !== null){{ $futureSelf['pct'] }}%<span class="ml-1 text-sm font-normal text-gray-500">there</span>@else Tracking @endif
                            </div>
                            @if ($futureSelf['pct'] !== null)
                                <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-white/10">
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

        {{-- ═══════════════ THE LONG GAME — bio-age doorway ═══════════════ --}}
        @php
            $aTone = $bioAge ? match ($bioAge['band']) {
                'much_younger', 'younger' => 'text-emerald-300', 'on_par' => 'text-cyan-300',
                'older' => 'text-amber-300', default => 'text-orange-300',
            } : 'text-gray-300';
        @endphp
        <a href="/progress" class="glass-card group block p-4 active:bg-white/[0.07]">
            <div class="flex items-center gap-4">
                @if ($bioAge)
                    <div class="shrink-0 text-center">
                        <div class="font-display text-3xl font-bold leading-none nums {{ $aTone }}">{{ number_format($bioAge['biological_age'], 0) }}</div>
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
</x-titan-layout>
