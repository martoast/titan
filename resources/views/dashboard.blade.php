<x-titan-layout title="Dashboard" subtitle="Your operating system for the strongest version of yourself">
    @php
        $p = auth()->user()?->profile;

        // Today strip: latest objective recovery row + last night's sleep, pulled live.
        $todayRecovery = $p?->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
        $todaySleep = $p?->sleepLogs()->orderByDesc('slept_at')->orderByDesc('id')->first();

        // Today's steps vs the evidence-based personalized goal.
        $todaySteps = (int) ($p?->dailyActivity()->whereDate('date', \Illuminate\Support\Carbon::today())->value('steps') ?? 0);
        $stepTarget = \App\Support\StepGoal::targetFor($p);
        $stepGoal = \App\Support\StepGoal::assess($todaySteps, $stepTarget);
        $todayReadiness = $p ? \App\Support\Readiness::compute($p, $todayRecovery?->logged_at) : ['score' => null, 'label' => '', 'note' => '', 'components' => [], 'provisional' => false];
        $hasToday = ($todayReadiness['score'] ?? null) !== null || $todaySleep || ($todayRecovery?->resting_hr);

        $rTone = match (true) {
            ($todayReadiness['score'] ?? null) === null => 'text-gray-400',
            $todayReadiness['score'] >= 80 => 'text-emerald-300',
            $todayReadiness['score'] >= 60 => 'text-cyan-300',
            $todayReadiness['score'] >= 40 => 'text-amber-300',
            $todayReadiness['score'] >= 20 => 'text-orange-300',
            default => 'text-rose-300',
        };
    @endphp

    <div class="space-y-4 md:space-y-5">
        {{-- Welcome / goal banner --}}
        <div class="rounded-2xl border border-white/5 bg-gradient-to-br from-indigo-500/10 to-cyan-400/[0.06] p-4 md:p-5">
            <div class="text-[11px] uppercase tracking-wide text-gray-500">Welcome back</div>
            <h2 class="font-display text-xl md:text-2xl font-bold text-gray-100 mt-0.5">{{ auth()->user()->name }}</h2>
            <p class="text-sm text-gray-400 mt-1.5">{{ $p?->primary_goal ?? 'Set your goal to start tracking toward your dream physique.' }}</p>
        </div>

        {{-- Today strip: readiness · last night's sleep · resting HR --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Today</div>
                @if ($hasToday && str_starts_with((string) ($todayRecovery?->updated_via), 'biosignal'))
                    <span class="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-400/80">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.55a11 11 0 0114 0M8.5 16.05a6 6 0 017 0M2 9.05a16 16 0 0120 0M12 20h.01"/></svg>
                        Wearable
                    </span>
                @endif
            </div>

            @if ($hasToday)
                <div class="grid grid-cols-3 gap-3 md:gap-4">
                    <a href="/recovery" class="block active:opacity-80">
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Readiness</div>
                        <div class="font-display text-2xl md:text-3xl font-bold nums {{ $rTone }} mt-0.5 leading-none">{{ $todayReadiness['score'] ?? '—' }}</div>
                        <div class="text-xs text-gray-500 mt-1 truncate">{{ $todayReadiness['label'] ?? '' }}</div>
                    </a>
                    <a href="/sleep" class="block active:opacity-80">
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Last sleep</div>
                        <div class="font-display text-2xl md:text-3xl font-bold nums text-indigo-300 mt-0.5 leading-none">{{ $todaySleep ? $todaySleep->durationLabel() : '—' }}</div>
                        <div class="text-xs text-gray-500 mt-1 truncate">{{ $todaySleep?->quality !== null ? $todaySleep->quality.'/100 quality' : 'duration' }}</div>
                    </a>
                    <a href="/recovery" class="block active:opacity-80">
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Resting HR</div>
                        <div class="font-display text-2xl md:text-3xl font-bold nums text-cyan-300 mt-0.5 leading-none">{{ $todayRecovery?->resting_hr ?? '—' }}<span class="text-gray-500 text-sm font-normal">{{ $todayRecovery?->resting_hr !== null ? ' bpm' : '' }}</span></div>
                        <div class="text-xs text-gray-500 mt-1 truncate">overnight</div>
                    </a>
                </div>
            @else
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-gray-400">Connect a device or run the Simulator to see your readiness, sleep and resting HR here.</p>
                    <a href="/recovery" class="shrink-0 text-xs font-medium text-indigo-400 active:text-indigo-300">Recovery →</a>
                </div>
            @endif
        </div>

        {{-- Steps toward the personalized daily goal --}}
        <a href="/fitness" class="block rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 active:bg-white/[0.05] transition">
            @php
                $stepTone = match ($stepGoal['band']) {
                    'excellent' => 'text-emerald-300', 'good' => 'text-cyan-300', 'fair' => 'text-amber-300', default => 'text-orange-300',
                };
                $stepBar = match ($stepGoal['band']) {
                    'excellent' => 'from-emerald-500 to-emerald-400', 'good' => 'from-cyan-500 to-cyan-400',
                    'fair' => 'from-amber-500 to-amber-400', default => 'from-orange-500 to-orange-400',
                };
            @endphp
            <div class="flex items-end justify-between gap-3 mb-2">
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Steps today</div>
                    <div class="font-display text-2xl md:text-3xl font-bold nums {{ $stepTone }} mt-0.5 leading-none">{{ number_format($todaySteps) }}<span class="text-gray-500 text-sm font-normal"> / {{ number_format($stepGoal['target']) }}</span></div>
                </div>
                <div class="text-right shrink-0">
                    <div class="text-sm font-semibold {{ $stepTone }}">{{ $stepGoal['label'] }}</div>
                    <div class="text-[11px] text-gray-600 nums">{{ $stepGoal['to_go'] > 0 ? number_format($stepGoal['to_go']).' to go' : 'goal reached' }}</div>
                </div>
            </div>
            <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r {{ $stepBar }}" style="width: {{ $stepGoal['pct'] }}%"></div>
            </div>
        </a>

        {{-- Section cards. Each links to a domain the feature builds fill in. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
            @php
                $cards = [
                    ['Bloodwork',  'biomarkers', 'Upload labs, track Testosterone, ApoB, HbA1c & more over time.'],
                    ['Meals',      'meals',      'Snap a photo — AI logs calories & macros instantly.'],
                    ['Workouts',   'workouts',   'Log sessions with adaptive progressive overload.'],
                    ['Sleep',      'sleep',      'Track duration, quality and trends.'],
                    ['Recovery',   'recovery',   'HRV, resting HR, stress and soreness.'],
                    ['Physique',   'photos',     'Your living dream-physique image & progress photos.'],
                    ['The Brain',  'brain',      'Your AI long-term memory — everything it knows about you.'],
                    ['Coach',      'coach',      'Talk to your AI coach, grounded in all your data.'],
                    ['Duo',        'duo',        'Race your brother toward your goals.'],
                ];
            @endphp
            @foreach ($cards as [$label, $path, $desc])
                <a href="/{{ $path }}" class="group rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 transition hover:border-indigo-500/40 hover:bg-gray-900 active:bg-white/[0.06]">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-display font-bold text-gray-100">{{ $label }}</h3>
                        <svg class="h-4 w-4 shrink-0 text-gray-600 transition group-hover:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">{{ $desc }}</p>
                </a>
            @endforeach
        </div>
    </div>
</x-titan-layout>
