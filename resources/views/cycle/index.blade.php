<x-titan-layout title="Cycle" subtitle="Your cycle, woven into the rest of Titan">
    @php
        $has = $status['has_data'] ?? false;
        // Titan palette phase colors (mirrors iOS Theme.Palette)
        $phaseColors = [
            'menstrual' => '#FF4D8D', 'follicular' => '#34E5C0', 'fertile' => '#22D3EE',
            'ovulation' => '#A78BFA', 'luteal' => '#FFB020', 'unknown' => '#9ca3af',
        ];
        $accent = $phaseColors[$status['phase'] ?? 'unknown'] ?? '#9ca3af';

        if ($has) {
            // ── Flo-style prediction hero: the single most imminent event ──────────────
            $day = (int) $status['cycle_day'];
            $periodLen = (int) $status['period_length'];
            $np = $status['next_period']['in_days'];
            $ov = $status['ovulation']['in_days'];
            [$heroTop, $heroBig] = match (true) {
                $status['late'] => ['Period', abs($np).'d late'],
                $day <= $periodLen => ['Period', 'Day '.$day],
                $ov === 0 => ['Ovulation', 'Today'],
                $ov > 0 && $ov <= 6 => ['Ovulation in', $ov.' day'.($ov === 1 ? '' : 's')],
                $np !== null && $np >= 0 => ['Period in', $np.' day'.($np === 1 ? '' : 's')],
                default => [$status['phase_label'], 'Day '.$day],
            };

            // ── Pregnancy chance (awareness) ───────────────────────────────────────────
            $hormonalBc = ! ($status['fertile_window']['applicable'] ?? true);
            $cl = $status['conception']['likelihood'];
            $chanceLabel = strtoupper($cl);
            $chanceColor = ['high' => '#FF4D8D', 'medium' => '#FFB020', 'low' => '#34E5C0'][$cl] ?? '#34E5C0';
        }

        // A single day-cell renderer, shared by the week strip and the calendar grid.
        $dayCell = function (array $d, string $today, bool $small = false) {
            $dt = \Illuminate\Support\Carbon::parse($d['date']);
            $isToday = $d['date'] === $today;
            $size = $small ? 'h-9 w-9' : 'h-9 w-9';
            $cls = 'relative grid '.$size.' place-items-center rounded-full text-sm nums transition ';
            $style = '';
            if ($isToday) {
                $cls .= 'bg-titan-pink font-bold text-white';
            } elseif (! empty($d['period'])) {
                $cls .= 'text-white'; $style = 'background:#FF4D8Dcc';
            } elseif (! empty($d['ovulation'])) {
                $cls .= 'text-titan-violet'; $style = 'border:1.5px dashed #A78BFA';
            } elseif (! empty($d['fertile'])) {
                $cls .= 'text-gray-100'; $style = 'background:#22D3EE2e';
            } else {
                $cls .= 'text-gray-300';
            }
            return ['dt' => $dt, 'cls' => $cls, 'style' => $style];
        };
    @endphp

    <div x-data="{ calOpen: false, periodOpen: false }" class="space-y-4 md:space-y-5">

        @if (session('status'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 2500)"
                 class="rounded-chip bg-titan-mint/10 border border-titan-mint/20 px-4 py-2.5 text-sm text-titan-mint">{{ session('status') }}</div>
        @endif

        @if (! $has)
            {{-- ═══════════ Empty state ═══════════ --}}
            <x-card pad="p-6 md:p-8" class="text-center">
                <div class="mx-auto mb-4 grid h-16 w-16 place-items-center rounded-full bg-titan-pink/15">
                    <svg class="h-8 w-8 text-titan-pink" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2s6 6.4 6 11a6 6 0 11-12 0c0-4.6 6-11 6-11z"/></svg>
                </div>
                <h2 class="font-display text-2xl font-bold text-gray-50">Log your period to begin</h2>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-gray-400">Set the first day of your last period and Titan maps your phases, predicts your next one, shows your daily pregnancy chance, and factors your cycle into recovery &amp; nutrition.</p>
                <button @click="periodOpen = true" class="mt-5 inline-flex items-center gap-2 rounded-chip bg-gradient-to-r from-titan-pink to-titan-violet px-6 py-3 font-bold text-white active:opacity-90">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2s6 6.4 6 11a6 6 0 11-12 0c0-4.6 6-11 6-11z"/></svg>
                    Log period
                </button>
            </x-card>
        @else
            {{-- ═══════════ HERO — week strip · prediction · chance · actions ═══════════ --}}
            <x-card pad="p-5 md:p-6">
                {{-- month + calendar --}}
                <div class="flex items-center justify-between">
                    <span class="font-display font-bold text-gray-100">{{ $monthLabel }}</span>
                    <button @click="calOpen = true" class="grid h-8 w-8 place-items-center rounded-full text-titan-pink active:bg-white/5" aria-label="Open calendar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
                    </button>
                </div>

                {{-- week strip --}}
                <div class="mt-4 flex">
                    @foreach ($week as $d)
                        @php $c = $dayCell($d, $today); @endphp
                        <div class="flex flex-1 flex-col items-center gap-1.5">
                            <span class="text-[10px] font-bold uppercase {{ $d['date'] === $today ? 'text-gray-200' : 'text-gray-600' }}">{{ substr($c['dt']->format('D'), 0, 1) }}</span>
                            <div class="{{ $c['cls'] }}" @if ($c['style']) style="{{ $c['style'] }}" @endif>{{ $c['dt']->day }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- prediction hero --}}
                <div class="mt-6 text-center">
                    <div class="text-sm text-gray-400">{{ $heroTop }}</div>
                    <div class="font-display text-5xl font-bold leading-none text-gray-50 md:text-6xl" style="text-shadow: 0 0 30px {{ $accent }}44;">{{ $heroBig }}</div>
                </div>

                {{-- pregnancy chance --}}
                <div class="mt-4 text-center">
                    @if ($hormonalBc)
                        <p class="mx-auto max-w-xs text-xs text-gray-500">On hormonal birth control, the usual fertile-window estimate doesn’t apply.</p>
                    @else
                        <div class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-500">Chance of pregnancy today</div>
                        <div class="mt-1 font-display text-lg font-bold" style="color: {{ $chanceColor }}">{{ $chanceLabel }}</div>
                    @endif
                </div>

                {{-- circular actions --}}
                <div class="mt-5 flex items-start justify-center gap-8">
                    <button @click="periodOpen = true" class="flex flex-col items-center gap-2">
                        <span class="grid h-14 w-14 place-items-center rounded-full bg-titan-pink text-white active:opacity-90">
                            <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2s6 6.4 6 11a6 6 0 11-12 0c0-4.6 6-11 6-11z"/></svg>
                        </span>
                        <span class="text-[11px] text-gray-400">Log period</span>
                    </button>
                    <button @click="document.getElementById('log-today').scrollIntoView({ behavior: 'smooth' })" class="flex flex-col items-center gap-2">
                        <span class="grid h-14 w-14 place-items-center rounded-full border border-white/10 bg-white/[0.04] text-gray-100 active:bg-white/10">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                        </span>
                        <span class="text-[11px] text-gray-400">Symptoms</span>
                    </button>
                    <button @click="calOpen = true" class="flex flex-col items-center gap-2">
                        <span class="grid h-14 w-14 place-items-center rounded-full border border-white/10 bg-white/[0.04] text-gray-100 active:bg-white/10">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
                        </span>
                        <span class="text-[11px] text-gray-400">Calendar</span>
                    </button>
                </div>
            </x-card>

            {{-- ═══════════ Phase card ═══════════ --}}
            <x-card pad="p-4 md:p-5">
                <div class="flex items-center justify-between">
                    <h3 class="font-display font-bold text-gray-100">{{ $status['phase_label'] }}</h3>
                    <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold" style="color: {{ $accent }}; background: {{ $accent }}1a;">Day {{ $status['cycle_day'] }}</span>
                </div>
                @if (! empty($status['phase_blurb']))
                    <p class="mt-2 text-sm leading-relaxed text-gray-300">{{ $status['phase_blurb'] }}</p>
                @endif
                <div class="mt-4 grid grid-cols-3 gap-3">
                    <div class="text-center">
                        <div class="font-display text-xl font-bold nums text-titan-pink leading-none">
                            @if ($status['late']){{ $status['next_period']['late_days'] }}d @elseif ($np === 0) Today @else {{ $np }}d @endif
                        </div>
                        <div class="mt-1 text-[10px] uppercase tracking-wide text-gray-500">{{ $status['late'] ? 'Late' : 'Next period' }}</div>
                    </div>
                    <div class="text-center">
                        <div class="font-display text-xl font-bold nums text-titan-violet leading-none">
                            @if ($ov === 0) Today @elseif ($ov > 0){{ $ov }}d @else {{ abs($ov) }}d ago @endif
                        </div>
                        <div class="mt-1 text-[10px] uppercase tracking-wide text-gray-500">Ovulation</div>
                    </div>
                    <div class="text-center">
                        <div class="font-display text-xl font-bold nums text-titan-cyan leading-none">{{ $status['fertile_window']['active'] ? 'Now' : ($status['avg_length'].'d') }}</div>
                        <div class="mt-1 text-[10px] uppercase tracking-wide text-gray-500">{{ $status['fertile_window']['active'] ? 'Fertile' : 'Cycle' }}</div>
                    </div>
                </div>
            </x-card>

            {{-- ═══════════ Fertile window (awareness only) ═══════════ --}}
            @if ($status['fertile_window']['applicable'])
                <x-card pad="p-4 md:p-5">
                    <div class="flex items-center justify-between">
                        <div class="text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Fertile window</div>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="color: {{ $chanceColor }}; background: {{ $chanceColor }}1a;">{{ ucfirst($cl) }} chance</span>
                    </div>
                    <div class="mt-1.5 text-sm text-gray-200">
                        {{ \Illuminate\Support\Carbon::parse($status['fertile_window']['start'])->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($status['fertile_window']['end'])->format('M j') }}
                        @if ($status['fertile_window']['active']) <span class="font-semibold text-titan-cyan">· active now</span> @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $status['conception']['note'] }}</p>
                </x-card>
            @endif

            {{-- ═══════════ This phase × recovery (Titan's cross-signal edge) ═══════════ --}}
            @if (! empty($insight['note']))
                <x-card pad="p-4 md:p-5">
                    <div class="text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500 mb-2">Your body, this phase</div>
                    <div class="flex gap-2.5 rounded-chip border border-titan-indigo/15 bg-titan-indigo/[0.08] p-3">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-titan-indigo" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <p class="text-xs leading-relaxed text-indigo-200/90">{{ $insight['note'] }}</p>
                    </div>
                </x-card>
            @endif

            {{-- ═══════════ Log today (flow · symptoms · mood · energy) ═══════════ --}}
            <x-card id="log-today" pad="p-4 md:p-5"
                 x-data="cycleLog({{ \Illuminate\Support\Js::from($status['today_log']['symptoms'] ?? []) }})">
                <h3 class="mb-3 font-display font-bold text-gray-100">Log today</h3>
                <form method="POST" action="/cycle/day" class="space-y-4">
                    @csrf
                    <input type="hidden" name="date" value="{{ $today }}">
                    <div>
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Flow</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($flows as $f)
                                <label class="cursor-pointer">
                                    <input type="radio" name="flow" value="{{ $f }}" class="peer sr-only" @checked(($status['today_log']['flow'] ?? null) === $f)>
                                    <span class="block rounded-full border border-white/10 px-3.5 py-1.5 text-xs text-gray-300 transition peer-checked:border-titan-pink/50 peer-checked:bg-titan-pink/15 peer-checked:text-titan-pink">{{ ucfirst($f) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Symptoms</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($symptoms as $sym)
                                <button type="button" @click="toggle('{{ $sym }}')"
                                        :class="has('{{ $sym }}') ? 'border-titan-pink/50 bg-titan-pink/15 text-titan-pink' : 'border-white/10 text-gray-400'"
                                        class="rounded-full border px-3 py-1.5 text-xs transition">{{ str_replace('_', ' ', $sym) }}</button>
                            @endforeach
                        </div>
                        <template x-for="s in selected" :key="s"><input type="hidden" name="symptoms[]" :value="s"></template>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Mood (1–5)</label>
                            <input type="number" name="mood" min="1" max="5" value="{{ $status['today_log']['mood'] ?? '' }}" class="h-11 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 nums focus:border-titan-pink focus:ring-0">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Energy (1–5)</label>
                            <input type="number" name="energy" min="1" max="5" value="{{ $status['today_log']['energy'] ?? '' }}" class="h-11 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 nums focus:border-titan-pink focus:ring-0">
                        </div>
                    </div>
                    <button class="h-11 w-full rounded-chip bg-titan-pink font-semibold text-gray-950 active:opacity-90">Save today</button>
                </form>
            </x-card>

            {{-- ═══════════ Recent cycles ═══════════ --}}
            @if ($history->isNotEmpty())
                <x-card pad="p-4 md:p-5">
                    <h3 class="mb-3 font-display font-bold text-gray-100">Recent cycles</h3>
                    <div class="space-y-1.5">
                        @foreach ($history as $h)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-300">{{ $h['start'] }}</span>
                                <span class="text-xs text-gray-500">{{ $h['length'] ? $h['length'].'-day cycle' : 'current' }}@if ($h['period']) · {{ $h['period'] }}d period @endif</span>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        @endif

        {{-- ═══════════ Settings (always available) ═══════════ --}}
        <x-card id="cycle-settings" pad="p-4 md:p-5">
            <h3 class="mb-3 font-display font-bold text-gray-100">Cycle settings</h3>
            <form method="POST" action="/cycle/settings" class="space-y-4">
                @csrf
                <input type="hidden" name="enabled" value="1">
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Cycle days</label>
                        <input type="number" name="avg_length" min="21" max="45" value="{{ $config['avg_length'] }}" class="h-11 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 nums focus:border-titan-pink focus:ring-0">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Period days</label>
                        <input type="number" name="avg_period" min="1" max="10" value="{{ $config['avg_period'] }}" class="h-11 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 nums focus:border-titan-pink focus:ring-0">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Luteal days</label>
                        <input type="number" name="luteal_length" min="9" max="17" value="{{ $config['luteal_length'] }}" class="h-11 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 nums focus:border-titan-pink focus:ring-0">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Birth control</label>
                        <select name="birth_control" class="h-11 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                            @foreach (['none'=>'None','pill'=>'Pill','patch'=>'Patch','ring'=>'Ring','hormonal_iud'=>'Hormonal IUD','copper_iud'=>'Copper IUD','implant'=>'Implant','injection'=>'Injection','other'=>'Other'] as $v => $label)
                                <option value="{{ $v }}" @selected($config['birth_control'] === $v)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">I'm…</label>
                        <select name="intent" class="h-11 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                            @foreach (['tracking'=>'Just tracking','conceiving'=>'Trying to conceive','avoiding'=>'Avoiding pregnancy'] as $v => $label)
                                <option value="{{ $v }}" @selected($config['intent'] === $v)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button class="h-11 w-full rounded-chip border border-white/10 bg-white/[0.04] font-semibold text-gray-200 active:bg-white/[0.08]">Save settings</button>
            </form>
            <p class="mt-3 text-[11px] leading-relaxed text-gray-600">⚠ {{ \App\Support\Cycle::DISCLAIMER }}</p>
        </x-card>

        {{-- ═══════════ Log-period modal ═══════════ --}}
        <div x-show="periodOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" style="display:none;">
            <div @click="periodOpen = false" class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-md rounded-t-card sm:rounded-card border border-white/10 bg-titan-bg2 p-5 shadow-2xl" @click.stop x-transition>
                <h3 class="font-display text-lg font-bold text-gray-100">When did your period start?</h3>
                <form method="POST" action="/cycle/period" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="event" value="start">
                    <input type="date" name="date" value="{{ $today }}" max="{{ $today }}" required
                           class="h-12 w-full rounded-chip border border-white/10 bg-titan-bg px-3 text-base text-gray-100 focus:border-titan-pink focus:ring-0">
                    <button class="h-12 w-full rounded-chip bg-gradient-to-r from-titan-pink to-titan-violet font-bold text-white active:opacity-90">Log period start</button>
                    <button type="button" @click="periodOpen = false" class="h-11 w-full rounded-chip text-sm text-gray-400 active:bg-white/5">Cancel</button>
                </form>
            </div>
        </div>

        {{-- ═══════════ Calendar modal (projected — plan ahead) ═══════════ --}}
        <div x-show="calOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" style="display:none;">
            <div @click="calOpen = false" class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
            <div class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-t-card sm:rounded-card border border-white/10 bg-titan-bg2 p-5 shadow-2xl" @click.stop>
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-display font-bold text-gray-100">{{ $monthLabel }}</h3>
                    <button @click="calOpen = false" class="grid h-8 w-8 place-items-center rounded-full text-gray-400 active:bg-white/5" aria-label="Close">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="grid grid-cols-7 gap-1 text-center">
                    @foreach (['S','M','T','W','T','F','S'] as $dow)
                        <div class="text-[10px] font-bold uppercase text-gray-600">{{ $dow }}</div>
                    @endforeach
                    @foreach ($calendarDays as $d)
                        @php $c = $dayCell($d, $today); $inMonth = (int) $c['dt']->month === $monthNum; @endphp
                        <div class="flex justify-center py-0.5 {{ $inMonth ? '' : 'opacity-30' }}">
                            <div class="{{ $c['cls'] }}" @if ($c['style']) style="{{ $c['style'] }}" @endif>{{ $c['dt']->day }}</div>
                        </div>
                    @endforeach
                </div>
                {{-- legend --}}
                <div class="mt-4 flex flex-wrap justify-center gap-x-3 gap-y-1.5 text-[11px] text-gray-400">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#FF4D8Dcc"></span>Period</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#22D3EE2e"></span>Fertile</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="border:1.5px dashed #A78BFA"></span>Ovulation</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-titan-pink"></span>Today</span>
                </div>
                <p class="mt-4 text-center text-[11px] leading-relaxed text-gray-600">Projected from your averages — estimates for awareness, not a contraceptive method.</p>
            </div>
        </div>
    </div>

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
