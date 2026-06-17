<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07080a">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set up your Titan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#07080a] text-gray-100 min-h-[100dvh]">
    @php
        $tz = $profile->settings['timezone'] ?? 'America/Mexico_City';
    @endphp

    <div x-data="onboardingWizard('{{ addslashes($name) }}', '{{ $tz }}')" class="mx-auto flex min-h-[100dvh] max-w-lg flex-col px-5 pb-8 pt-[max(1rem,env(safe-area-inset-top))]">

        {{-- Progress --}}
        <div class="sticky top-0 z-10 -mx-5 bg-[#07080a]/90 px-5 pt-3 pb-3 backdrop-blur">
            <div class="flex items-center justify-between mb-2">
                <span class="font-display text-sm font-bold tracking-tight text-indigo-300">TITAN</span>
                <span class="text-[11px] text-gray-500" x-text="`Step ${idx + 1} of ${steps.length}`"></span>
            </div>
            <div class="h-1 rounded-full bg-white/10 overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400 transition-all duration-300"
                     :style="`width: ${((idx + 1) / steps.length) * 100}%`"></div>
            </div>
        </div>

        <form method="POST" action="/onboarding" class="flex flex-1 flex-col" @submit="submitting = true">
            @csrf

            <div class="flex-1 py-6">
                {{-- 1 · Welcome --}}
                <section x-show="current === 'welcome'" x-cloak class="text-center pt-6">
                    <div class="mx-auto mb-5 h-16 w-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-cyan-400 grid place-items-center">
                        <svg class="h-8 w-8 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h1 class="font-display text-3xl font-extrabold tracking-tight">Welcome, <span x-text="form.display_name"></span>.</h1>
                    <p class="mt-3 text-gray-400 leading-relaxed">Titan is your own AI health, longevity and physique coach — grounded in <em>your</em> data. Let's spend two minutes building your profile so it can actually help you.</p>
                    <ul class="mt-6 space-y-2.5 text-left text-sm text-gray-300">
                        <li class="flex gap-2.5"><span class="text-indigo-400">●</span> The vitals that personalize everything</li>
                        <li class="flex gap-2.5"><span class="text-indigo-400">●</span> Your goal and how you want to be coached</li>
                        <li class="flex gap-2.5"><span class="text-indigo-400">●</span> Nutrition targets, calculated for you</li>
                    </ul>
                </section>

                {{-- 2 · About you --}}
                <section x-show="current === 'about'" x-cloak>
                    <h2 class="font-display text-2xl font-bold">About you</h2>
                    <p class="mt-1 text-sm text-gray-500">The basics behind every score — readiness, biological age, macros.</p>

                    <div class="mt-5 space-y-4">
                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Preferred name</label>
                            <input name="display_name" x-model="form.display_name" type="text" maxlength="60" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Date of birth</label>
                            <input name="birthdate" x-model="form.birthdate" type="date" max="{{ now()->subYears(13)->toDateString() }}" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Sex (for physiology — HRV, macros, cycle)</label>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="opt in [{v:'F',l:'Female'},{v:'M',l:'Male'},{v:'other',l:'Other'}]" :key="opt.v">
                                    <button type="button" @click="form.sex = opt.v"
                                            :class="form.sex === opt.v ? 'border-indigo-400/60 bg-indigo-400/15 text-indigo-100' : 'border-white/10 text-gray-300'"
                                            class="rounded-xl border px-3 py-2.5 text-sm font-medium transition" x-text="opt.l"></button>
                                </template>
                            </div>
                            <input type="hidden" name="sex" :value="form.sex">
                        </div>

                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Units</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="form.units = 'metric'" :class="form.units === 'metric' ? 'border-indigo-400/60 bg-indigo-400/15 text-indigo-100' : 'border-white/10 text-gray-300'" class="rounded-xl border px-3 py-2.5 text-sm font-medium transition">Metric (kg / cm)</button>
                                <button type="button" @click="form.units = 'imperial'" :class="form.units === 'imperial' ? 'border-indigo-400/60 bg-indigo-400/15 text-indigo-100' : 'border-white/10 text-gray-300'" class="rounded-xl border px-3 py-2.5 text-sm font-medium transition">Imperial (lb / in)</button>
                            </div>
                            <input type="hidden" name="units" :value="form.units">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Height (<span x-text="form.units === 'metric' ? 'cm' : 'in'"></span>)</label>
                                <input name="height" x-model="form.height" type="number" step="0.1" inputmode="decimal" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Weight (<span x-text="form.units === 'metric' ? 'kg' : 'lb'"></span>)</label>
                                <input name="weight" x-model="form.weight" type="number" step="0.1" inputmode="decimal" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Typical activity</label>
                            <div class="space-y-2">
                                <template x-for="opt in [{v:'sedentary',l:'Sedentary',d:'Desk job, little exercise'},{v:'light',l:'Light',d:'1–3 workouts / week'},{v:'moderate',l:'Moderate',d:'4–5 workouts / week'},{v:'active',l:'Very active',d:'6+ or physical job'}]" :key="opt.v">
                                    <button type="button" @click="form.activity_level = opt.v"
                                            :class="form.activity_level === opt.v ? 'border-indigo-400/60 bg-indigo-400/10' : 'border-white/10'"
                                            class="flex w-full items-center justify-between rounded-xl border px-4 py-3 text-left transition">
                                        <span class="text-sm font-medium text-gray-200" x-text="opt.l"></span>
                                        <span class="text-xs text-gray-500" x-text="opt.d"></span>
                                    </button>
                                </template>
                            </div>
                            <input type="hidden" name="activity_level" :value="form.activity_level">
                            <input type="hidden" name="timezone" :value="form.timezone">
                        </div>
                    </div>
                </section>

                {{-- 3 · Goal --}}
                <section x-show="current === 'goal'" x-cloak>
                    <h2 class="font-display text-2xl font-bold">Your main goal</h2>
                    <p class="mt-1 text-sm text-gray-500">What should Titan optimize you toward? You can change this anytime.</p>
                    <div class="mt-5 space-y-2.5">
                        @foreach ($goals as $key => $label)
                            <button type="button" @click="form.primary_goal = '{{ $key }}'"
                                    :class="form.primary_goal === '{{ $key }}' ? 'border-indigo-400/60 bg-indigo-400/10' : 'border-white/10'"
                                    class="flex w-full items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition">
                                <span class="h-2.5 w-2.5 rounded-full shrink-0" :class="form.primary_goal === '{{ $key }}' ? 'bg-indigo-400' : 'bg-white/15'"></span>
                                <span class="text-sm font-medium text-gray-100">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                    <input type="hidden" name="primary_goal" :value="form.primary_goal">
                </section>

                {{-- 4 · Coaching style --}}
                <section x-show="current === 'tone'" x-cloak>
                    <h2 class="font-display text-2xl font-bold">How should I coach you?</h2>
                    <p class="mt-1 text-sm text-gray-500">Your coach's voice. Be honest about what gets you moving.</p>
                    <div class="mt-5 space-y-2.5">
                        <template x-for="opt in [
                            {v:'tough_love',l:'Tough love',d:'Direct and demanding. Calls out excuses, pushes you hard.'},
                            {v:'balanced',l:'Balanced',d:'Supportive but straight — encourages and tells you the truth.'},
                            {v:'gentle',l:'Gentle',d:'Warm and patient. Celebrates small wins, never shames.'}
                        ]" :key="opt.v">
                            <button type="button" @click="form.coach_tone = opt.v"
                                    :class="form.coach_tone === opt.v ? 'border-indigo-400/60 bg-indigo-400/10' : 'border-white/10'"
                                    class="block w-full rounded-xl border px-4 py-3.5 text-left transition">
                                <span class="text-sm font-semibold text-gray-100" x-text="opt.l"></span>
                                <span class="mt-0.5 block text-xs text-gray-500" x-text="opt.d"></span>
                            </button>
                        </template>
                    </div>
                    <input type="hidden" name="coach_tone" :value="form.coach_tone">
                </section>

                {{-- 5 · Cycle (women only) --}}
                <section x-show="current === 'cycle'" x-cloak>
                    <h2 class="font-display text-2xl font-bold">Your cycle</h2>
                    <p class="mt-1 text-sm text-gray-500">Titan can weave your menstrual cycle into recovery, nutrition and training. Optional — and yours alone.</p>

                    <label class="mt-5 flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3.5">
                        <span class="text-sm font-medium text-gray-200">Track my cycle</span>
                        <input type="checkbox" name="cycle_enabled" value="1" x-model="form.cycle_enabled" class="h-5 w-9 appearance-none rounded-full bg-white/15 checked:bg-indigo-500 transition relative cursor-pointer before:absolute before:top-0.5 before:left-0.5 before:h-4 before:w-4 before:rounded-full before:bg-white before:transition checked:before:translate-x-4">
                    </label>

                    <div x-show="form.cycle_enabled" x-cloak class="mt-4 space-y-4">
                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">First day of your last period</label>
                            <input name="last_period" x-model="form.last_period" type="date" max="{{ now()->toDateString() }}" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Cycle length (days)</label>
                                <input name="cycle_length" x-model="form.cycle_length" type="number" min="21" max="45" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Birth control</label>
                                <select name="birth_control" x-model="form.birth_control" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                                    <option value="none">None</option>
                                    <option value="pill">Pill</option>
                                    <option value="hormonal_iud">Hormonal IUD</option>
                                    <option value="copper_iud">Copper IUD</option>
                                    <option value="implant">Implant</option>
                                    <option value="ring">Ring</option>
                                    <option value="patch">Patch</option>
                                    <option value="injection">Injection</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">I'm…</label>
                            <select name="cycle_intent" x-model="form.cycle_intent" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                                <option value="tracking">Just tracking</option>
                                <option value="conceiving">Trying to conceive</option>
                                <option value="avoiding">Avoiding pregnancy</option>
                            </select>
                        </div>
                        <p class="text-[11px] text-amber-300/70 leading-relaxed">Cycle features are for awareness and coaching — never contraception or medical diagnosis.</p>
                    </div>
                </section>

                {{-- 6 · Nutrition --}}
                <section x-show="current === 'nutrition'" x-cloak>
                    <h2 class="font-display text-2xl font-bold">Eating rhythm</h2>
                    <p class="mt-1 text-sm text-gray-500">Titan reminds you to eat <em>before</em> you're hungry — and we'll calculate your daily targets from your vitals and goal.</p>

                    <div class="mt-5 space-y-4">
                        <div>
                            <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Meals per day</label>
                            <div class="grid grid-cols-5 gap-2">
                                <template x-for="n in [2,3,4,5,6]" :key="n">
                                    <button type="button" @click="form.meals_per_day = n"
                                            :class="form.meals_per_day === n ? 'border-indigo-400/60 bg-indigo-400/15 text-indigo-100' : 'border-white/10 text-gray-300'"
                                            class="rounded-xl border py-3 text-base font-semibold transition" x-text="n"></button>
                                </template>
                            </div>
                            <input type="hidden" name="meals_per_day" :value="form.meals_per_day">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">First meal</label>
                                <input name="eat_start" x-model="form.eat_start" type="time" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-[11px] uppercase tracking-wider text-gray-500">Last meal</label>
                                <input name="eat_end" x-model="form.eat_end" type="time" class="w-full rounded-xl border border-white/10 bg-gray-950/60 px-4 py-3 text-base">
                            </div>
                        </div>
                    </div>
                </section>

                {{-- 7 · Finish --}}
                <section x-show="current === 'finish'" x-cloak class="text-center pt-4">
                    <div class="mx-auto mb-5 h-16 w-16 rounded-full bg-emerald-500/15 grid place-items-center">
                        <svg class="h-8 w-8 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h2 class="font-display text-2xl font-bold">Your Titan is ready</h2>
                    <p class="mt-2 text-gray-400 leading-relaxed">I've got your vitals, your goal, and your coaching style. I'll calculate your nutrition targets and start learning from everything you log.</p>
                    <div class="mt-6 rounded-2xl border border-white/5 bg-white/[0.03] p-4 text-left text-sm">
                        <div class="flex justify-between py-1"><span class="text-gray-500">Goal</span><span class="text-gray-200 font-medium" x-text="goalLabel"></span></div>
                        <div class="flex justify-between py-1"><span class="text-gray-500">Coaching</span><span class="text-gray-200 font-medium capitalize" x-text="form.coach_tone.replace('_',' ')"></span></div>
                        <div class="flex justify-between py-1"><span class="text-gray-500">Meals / day</span><span class="text-gray-200 font-medium" x-text="form.meals_per_day"></span></div>
                        <div class="flex justify-between py-1" x-show="form.sex === 'F' && form.cycle_enabled"><span class="text-gray-500">Cycle</span><span class="text-gray-200 font-medium">Tracking on</span></div>
                    </div>
                    <p class="mt-4 text-[11px] text-gray-600">Titan is coaching and wellness, not medical advice.</p>
                </section>
            </div>

            {{-- Nav --}}
            <div class="sticky bottom-0 -mx-5 flex gap-3 bg-[#07080a]/90 px-5 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur">
                <button type="button" x-show="idx > 0" @click="back()" class="rounded-xl border border-white/10 px-5 py-3.5 text-sm font-semibold text-gray-300 active:bg-white/5">Back</button>
                <button type="button" x-show="current !== 'finish'" @click="next()" :disabled="!valid()"
                        class="flex-1 rounded-xl bg-indigo-500 px-5 py-3.5 text-sm font-bold text-white active:bg-indigo-400 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    Continue
                </button>
                <button type="submit" x-show="current === 'finish'" :disabled="submitting"
                        class="flex-1 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-5 py-3.5 text-sm font-bold text-gray-900 active:opacity-90 disabled:opacity-50 transition">
                    <span x-show="!submitting">Enter Titan →</span>
                    <span x-show="submitting" x-cloak>Setting up…</span>
                </button>
            </div>
        </form>
    </div>

    <script>
        function onboardingWizard(name, tz) {
            return {
                idx: 0,
                submitting: false,
                form: {
                    display_name: name || '',
                    birthdate: '', sex: '', units: 'metric', height: '', weight: '',
                    activity_level: 'light', timezone: tz || 'UTC',
                    primary_goal: '', coach_tone: 'balanced',
                    meals_per_day: 4, eat_start: '08:00', eat_end: '21:00',
                    cycle_enabled: false, last_period: '', cycle_length: 28, birth_control: 'none', cycle_intent: 'tracking',
                },
                goalLabels: {
                    build_muscle: 'Build muscle', lose_fat: 'Lose fat / get lean', recomp: 'Recomposition',
                    longevity: 'Longevity & healthspan', performance: 'Athletic performance', general: 'General health',
                },
                get goalLabel() { return this.goalLabels[this.form.primary_goal] || '—'; },
                get steps() {
                    const base = ['welcome', 'about', 'goal', 'tone'];
                    if (this.form.sex === 'F') base.push('cycle');
                    base.push('nutrition', 'finish');
                    return base;
                },
                get current() { return this.steps[Math.min(this.idx, this.steps.length - 1)]; },
                valid() {
                    const f = this.form;
                    switch (this.current) {
                        case 'about':
                            return f.display_name.trim() && f.birthdate && f.sex && f.units
                                && Number(f.height) > 0 && Number(f.weight) > 0 && f.activity_level;
                        case 'goal': return !!f.primary_goal;
                        case 'tone': return !!f.coach_tone;
                        case 'nutrition': return Number(f.meals_per_day) >= 2;
                        default: return true;
                    }
                },
                next() {
                    if (!this.valid()) return;
                    if (this.idx < this.steps.length - 1) this.idx++;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
                back() {
                    if (this.idx > 0) this.idx--;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
            };
        }
    </script>
</body>
</html>
