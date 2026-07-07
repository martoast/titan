<x-titan-layout title="Cycle" subtitle="Your menstrual cycle, woven into the rest of Titan">
    @php
        $has = $status['has_data'] ?? false;
        $phaseColors = [
            'menstrual' => '#fb7185', 'follicular' => '#34d399', 'fertile' => '#22d3ee',
            'ovulation' => '#a78bfa', 'luteal' => '#fbbf24', 'unknown' => '#9ca3af',
        ];
        $accent = $phaseColors[$status['phase'] ?? 'unknown'] ?? '#9ca3af';

        // --- Build the cycle ring (SVG arc segments + today/ovulation markers) ---
        $ring = null;
        if ($has) {
            $len = max(21, (int) $status['avg_length']);
            $period = (int) $status['period_length'];
            $ovDay = (int) ($status['ovulation']['day'] ?? ($len - ($config['luteal_length'] ?? 14)));
            $fStart = max(1, $ovDay - \App\Support\Cycle::FERTILE_PRE);
            $fEnd = $ovDay + \App\Support\Cycle::FERTILE_POST;
            $C = 2 * M_PI * 52;
            $seg = function ($startDay, $days) use ($len, $C) {
                $l = max(0, $days) / $len * $C;
                return ['len' => round($l, 2), 'gap' => round($C - $l, 2), 'deg' => round((($startDay - 1) / $len) * 360 - 90, 2)];
            };
            $segments = [
                ['c' => $phaseColors['menstrual'], 's' => $seg(1, $period)],
                ['c' => $phaseColors['follicular'], 's' => $seg($period + 1, max(0, $fStart - 1 - $period))],
                ['c' => $phaseColors['fertile'], 's' => $seg($fStart, $fEnd - $fStart + 1)],
                ['c' => $phaseColors['luteal'], 's' => $seg($fEnd + 1, $len - $fEnd)],
            ];
            $markerAngle = fn ($day) => (($day - 1) / $len) * 2 * M_PI - M_PI / 2;
            $todayA = $markerAngle((int) $status['cycle_day']);
            $ovA = $markerAngle($ovDay);
            $ring = [
                'segments' => $segments,
                'today' => ['x' => round(60 + 52 * cos($todayA), 2), 'y' => round(60 + 52 * sin($todayA), 2)],
                'ov' => ['x' => round(60 + 52 * cos($ovA), 2), 'y' => round(60 + 52 * sin($ovA), 2)],
            ];
        }
    @endphp

    @if (session('status'))
        <div class="mb-4 rounded-chip bg-titan-mint/10 border border-titan-mint/20 px-4 py-2.5 text-sm text-titan-mint">{{ session('status') }}</div>
    @endif

    @if (! $has)
        {{-- Empty state: warm setup --}}
        <div class="max-w-md mx-auto text-center py-6">
            <div class="mx-auto mb-4 h-14 w-14 rounded-full grid place-items-center bg-titan-pink/15">
                <svg class="h-7 w-7 text-titan-pink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-2.64-6.36M21 4v4h-4"/></svg>
            </div>
            <h2 class="font-display text-xl font-bold text-gray-100">Track your cycle</h2>
            <p class="text-sm text-gray-400 mt-1.5 leading-relaxed">{{ $status['note'] }}</p>
            <form method="POST" action="/cycle/period" class="mt-5">
                @csrf
                <input type="hidden" name="event" value="start">
                <input type="hidden" name="date" value="{{ $today }}">
                <button class="w-full rounded-chip px-4 py-3 font-semibold text-titan-bg bg-titan-pink active:opacity-90 transition">My period started today</button>
            </form>
            <p class="text-[11px] text-gray-600 mt-3">Or set a past start date and your averages in <a href="#cycle-settings" class="underline">settings</a> below.</p>
        </div>
    @else
        {{-- ===== Hero: the cycle ring ===== --}}
        <x-card pad="p-5" class="rounded-card mb-4">
            <div class="flex flex-col items-center">
                <div class="relative">
                    <svg viewBox="0 0 120 120" class="h-52 w-52" style="transform:rotate(0deg)">
                        <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="10"/>
                        @foreach ($ring['segments'] as $s)
                            <circle cx="60" cy="60" r="52" fill="none" stroke="{{ $s['c'] }}" stroke-width="10" stroke-linecap="round"
                                    stroke-dasharray="{{ $s['s']['len'] }} {{ $s['s']['gap'] }}"
                                    transform="rotate({{ $s['s']['deg'] }} 60 60)" opacity="0.9"/>
                        @endforeach
                        {{-- ovulation marker --}}
                        <circle cx="{{ $ring['ov']['x'] }}" cy="{{ $ring['ov']['y'] }}" r="3.5" fill="#a78bfa" stroke="#07070A" stroke-width="1.5"/>
                        {{-- today marker --}}
                        <circle cx="{{ $ring['today']['x'] }}" cy="{{ $ring['today']['y'] }}" r="6" fill="#fff" stroke="{{ $accent }}" stroke-width="3"/>
                    </svg>
                    <div class="absolute inset-0 grid place-items-center text-center">
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-gray-500">Day</div>
                            <div class="font-display text-4xl font-extrabold leading-none" style="color:{{ $accent }}">{{ $status['cycle_day'] }}</div>
                            <div class="mt-1 text-sm font-semibold text-gray-200">{{ $status['phase_label'] }}</div>
                        </div>
                    </div>
                </div>

                <p class="mt-4 text-center text-sm text-gray-300 leading-relaxed max-w-sm">{{ $status['note'] }}</p>

                {{-- phase legend --}}
                <div class="mt-3 flex flex-wrap justify-center gap-x-3 gap-y-1 text-[11px] text-gray-500">
                    @foreach (['menstrual'=>'Menstrual','follicular'=>'Follicular','fertile'=>'Fertile','luteal'=>'Luteal'] as $k => $label)
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background:{{ $phaseColors[$k] }}"></span>{{ $label }}</span>
                    @endforeach
                </div>
            </div>
        </x-card>

        {{-- ===== Quick actions ===== --}}
        <div class="grid grid-cols-2 gap-3 mb-4">
            <form method="POST" action="/cycle/period">
                @csrf
                <input type="hidden" name="event" value="start">
                <input type="hidden" name="date" value="{{ $today }}">
                <button class="w-full rounded-chip border border-titan-pink/30 bg-titan-pink/10 px-3 py-3 text-sm font-semibold text-titan-pink active:bg-titan-pink/20 transition">
                    Period started today
                </button>
            </form>
            <a href="#log-today" class="rounded-chip border border-white/10 bg-white/[0.04] px-3 py-3 text-sm font-semibold text-gray-200 text-center active:bg-white/[0.08] transition">
                Log how I feel
            </a>
        </div>

        {{-- ===== Predictions ===== --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
            <x-card pad="p-4">
                <div class="text-[11px] uppercase tracking-wider text-gray-500">Next period</div>
                <div class="mt-1 font-display text-xl font-bold text-gray-100">
                    @if ($status['late'])<span class="text-titan-pink">{{ $status['next_period']['late_days'] }}d late</span>
                    @elseif ($status['next_period']['in_days'] === 0) Today
                    @else in {{ $status['next_period']['in_days'] }}d @endif
                </div>
                <div class="text-[11px] text-gray-500 mt-0.5">{{ \Illuminate\Support\Carbon::parse($status['next_period']['date'])->format('M j') }}</div>
            </x-card>
            <x-card pad="p-4">
                <div class="text-[11px] uppercase tracking-wider text-gray-500">Ovulation</div>
                <div class="mt-1 font-display text-xl font-bold text-gray-100">
                    @if ($status['ovulation']['in_days'] === 0) Today
                    @elseif ($status['ovulation']['in_days'] > 0) in {{ $status['ovulation']['in_days'] }}d
                    @else {{ abs($status['ovulation']['in_days']) }}d ago @endif
                </div>
                <div class="text-[11px] text-gray-500 mt-0.5">est · {{ \Illuminate\Support\Carbon::parse($status['ovulation']['date'])->format('M j') }}</div>
            </x-card>
            <x-card pad="p-4" class="col-span-2 sm:col-span-1">
                <div class="text-[11px] uppercase tracking-wider text-gray-500">Cycle</div>
                <div class="mt-1 font-display text-xl font-bold text-gray-100">{{ $status['avg_length'] }}d avg</div>
                <div class="text-[11px] text-gray-500 mt-0.5">{{ ucfirst($status['regularity']) }} · {{ $status['cycles_tracked'] }} tracked</div>
            </x-card>
        </div>

        {{-- ===== Fertile window / conception (awareness only) ===== --}}
        @if ($status['fertile_window']['applicable'])
            @php $cl = $status['conception']['likelihood']; $clColor = ['high'=>'#22d3ee','medium'=>'#34d399','low'=>'#9ca3af'][$cl]; @endphp
            <x-card pad="p-4" class="mb-4">
                <div class="flex items-center justify-between">
                    <div class="text-[11px] uppercase tracking-wider text-gray-500">Fertile window</div>
                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full" style="color:{{ $clColor }};background:{{ $clColor }}1a">{{ ucfirst($cl) }} chance today</span>
                </div>
                <div class="mt-1.5 text-sm text-gray-200">
                    {{ \Illuminate\Support\Carbon::parse($status['fertile_window']['start'])->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($status['fertile_window']['end'])->format('M j') }}
                    @if ($status['fertile_window']['active']) <span class="text-titan-cyan font-semibold">· active now</span> @endif
                </div>
                <p class="mt-1 text-xs text-gray-500">{{ $status['conception']['note'] }}</p>
                <p class="mt-2 text-[11px] text-titan-amber/70 leading-relaxed">⚠ {{ $status['disclaimer'] }}</p>
            </x-card>
        @else
            <x-card pad="p-4" class="mb-4 text-xs text-gray-400">
                On hormonal birth control, the usual fertile-window estimate doesn’t apply. <span class="text-titan-amber/70">{{ $status['disclaimer'] }}</span>
            </x-card>
        @endif

        {{-- ===== Phase insight (ties to recovery) ===== --}}
        <x-card pad="p-4" class="mb-4">
            <div class="text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">This phase</div>
            <p class="text-sm text-gray-300 leading-relaxed">{{ $status['phase_blurb'] }}</p>
            @if (! empty($insight['note']))
                <div class="mt-3 flex gap-2 rounded-chip bg-titan-indigo/[0.08] border border-titan-indigo/15 p-3">
                    <svg class="h-4 w-4 shrink-0 mt-0.5 text-titan-indigo" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <p class="text-xs text-indigo-200/90 leading-relaxed">{{ $insight['note'] }}</p>
                </div>
            @endif
        </x-card>

        {{-- ===== Log today ===== --}}
        <x-card id="log-today" pad="p-4" class="mb-4"
             x-data="cycleLog({{ \Illuminate\Support\Js::from($status['today_log']['symptoms'] ?? []) }})">
            <h3 class="font-display font-bold text-gray-100 mb-3">Log today</h3>
            <form method="POST" action="/cycle/day" class="space-y-4">
                @csrf
                <input type="hidden" name="date" value="{{ $today }}">

                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Flow</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($flows as $f)
                            <label class="cursor-pointer">
                                <input type="radio" name="flow" value="{{ $f }}" class="peer sr-only" @checked(($status['today_log']['flow'] ?? null) === $f)>
                                <span class="block rounded-full border border-white/10 px-3.5 py-1.5 text-xs text-gray-300 peer-checked:border-titan-pink/50 peer-checked:bg-titan-pink/15 peer-checked:text-titan-pink transition">{{ ucfirst($f) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Symptoms</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($symptoms as $sym)
                            <button type="button" @click="toggle('{{ $sym }}')"
                                    :class="has('{{ $sym }}') ? 'border-titan-pink/50 bg-titan-pink/15 text-titan-pink' : 'border-white/10 text-gray-400'"
                                    class="rounded-full border px-3 py-1.5 text-xs transition">{{ str_replace('_',' ', $sym) }}</button>
                        @endforeach
                    </div>
                    <template x-for="s in selected" :key="s"><input type="hidden" name="symptoms[]" :value="s"></template>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Mood (1–5)</label>
                        <input type="number" name="mood" min="1" max="5" value="{{ $status['today_log']['mood'] ?? '' }}" class="w-full rounded-chip bg-titan-bg border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Energy (1–5)</label>
                        <input type="number" name="energy" min="1" max="5" value="{{ $status['today_log']['energy'] ?? '' }}" class="w-full rounded-chip bg-titan-bg border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                    </div>
                </div>

                <button class="w-full rounded-chip bg-titan-pink px-4 py-3 font-semibold text-titan-bg active:opacity-90 transition">Save today</button>
            </form>
        </x-card>

        {{-- ===== History ===== --}}
        @if ($history->isNotEmpty())
            <x-card pad="p-4" class="mb-4">
                <h3 class="font-display font-bold text-gray-100 mb-3">Recent cycles</h3>
                <div class="space-y-1.5">
                    @foreach ($history as $h)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-300">{{ $h['start'] }}</span>
                            <span class="text-gray-500 text-xs">
                                {{ $h['length'] ? $h['length'].'-day cycle' : 'current' }}@if ($h['period']) · {{ $h['period'] }}d period @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif
    @endif

    {{-- ===== Settings (always available) ===== --}}
    <x-card id="cycle-settings" pad="p-4" class="mb-4">
        <h3 class="font-display font-bold text-gray-100 mb-3">Cycle settings</h3>
        <form method="POST" action="/cycle/settings" class="space-y-4">
            @csrf
            <input type="hidden" name="enabled" value="1">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Cycle days</label>
                    <input type="number" name="avg_length" min="21" max="45" value="{{ $config['avg_length'] }}" class="w-full rounded-chip bg-titan-bg border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Period days</label>
                    <input type="number" name="avg_period" min="1" max="10" value="{{ $config['avg_period'] }}" class="w-full rounded-chip bg-titan-bg border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Luteal days</label>
                    <input type="number" name="luteal_length" min="9" max="17" value="{{ $config['luteal_length'] }}" class="w-full rounded-chip bg-titan-bg border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">Birth control</label>
                    <select name="birth_control" class="w-full rounded-chip bg-titan-bg border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                        @foreach (['none'=>'None','pill'=>'Pill','patch'=>'Patch','ring'=>'Ring','hormonal_iud'=>'Hormonal IUD','copper_iud'=>'Copper IUD','implant'=>'Implant','injection'=>'Injection','other'=>'Other'] as $v => $label)
                            <option value="{{ $v }}" @selected($config['birth_control'] === $v)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-gray-500 mb-1.5">I'm…</label>
                    <select name="intent" class="w-full rounded-chip bg-titan-bg border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                        @foreach (['tracking'=>'Just tracking','conceiving'=>'Trying to conceive','avoiding'=>'Avoiding pregnancy'] as $v => $label)
                            <option value="{{ $v }}" @selected($config['intent'] === $v)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <button class="w-full rounded-chip border border-white/10 bg-white/[0.04] px-4 py-3 font-semibold text-gray-200 active:bg-white/[0.08] transition">Save settings</button>
        </form>
        <p class="mt-3 text-[11px] text-gray-600 leading-relaxed">Titan’s cycle features are for awareness and coaching, not contraception or medical diagnosis. For decisions about pregnancy or any symptom that worries you, see a healthcare provider.</p>
    </x-card>

    <script>
        function cycleLog(initial) {
            return {
                selected: initial || [],
                has(s) { return this.selected.includes(s); },
                toggle(s) { this.has(s) ? this.selected = this.selected.filter(x => x !== s) : this.selected.push(s); },
            };
        }
    </script>
</x-titan-layout>
