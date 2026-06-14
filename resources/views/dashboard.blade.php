<x-titan-layout title="Dashboard" subtitle="Your operating system for the strongest version of yourself">
    @php $p = auth()->user()?->profile; @endphp

    <div class="space-y-4 md:space-y-5">
        {{-- Welcome / goal banner --}}
        <div class="rounded-2xl border border-white/5 bg-gradient-to-br from-indigo-500/10 to-cyan-400/[0.06] p-4 md:p-5">
            <div class="text-[11px] uppercase tracking-wide text-gray-500">Welcome back</div>
            <h2 class="font-display text-xl md:text-2xl font-bold text-gray-100 mt-0.5">{{ auth()->user()->name }}</h2>
            <p class="text-sm text-gray-400 mt-1.5">{{ $p?->primary_goal ?? 'Set your goal to start tracking toward your dream physique.' }}</p>
        </div>

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
