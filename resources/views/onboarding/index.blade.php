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
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800;900&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        .ob-glow {
            position: fixed; inset: -30% 0 auto 0; height: 60vh; z-index: 0; pointer-events: none;
            background: radial-gradient(60% 60% at 50% 0%, var(--accent, #6366f1) 0%, transparent 70%);
            opacity: 0.16; filter: blur(20px); transition: background 0.6s ease, opacity 0.6s ease;
        }
        /* margin:auto centres a short step but lets a tall one (e.g. 6 goal cards) scroll on small phones */
        .ob-step { animation: ob-in 0.42s cubic-bezier(0.16,1,0.3,1) both; margin-block: auto; width: 100%; }
        @keyframes ob-in { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        .ob-opt { transition: transform 0.12s ease, border-color 0.18s ease, background 0.18s ease; }
        .ob-opt:active { transform: scale(0.985); }
        @media (prefers-reduced-motion: reduce) {
            .ob-step, .ob-opt { animation: none !important; transition: none !important; }
        }
        /* big, centred number fields */
        .ob-num { font-family: 'Archivo', sans-serif; font-weight: 800; letter-spacing: -0.02em; }
        .ob-num::-webkit-outer-spin-button, .ob-num::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        /* DOB selects — native wheel pickers on mobile, themed dark */
        .ob-sel {
            -webkit-appearance: none; appearance: none; text-align: center;
            border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.04);
            padding: 0.95rem 0.5rem; font-family: 'Archivo', sans-serif; font-weight: 700; font-size: 1.05rem;
        }
        .ob-sel:focus { outline: none; border-color: #818cf8; }
        .ob-sel option { background: #14161b; color: #f3f4f6; }
    </style>
</head>
<body class="bg-[#07080a] text-gray-100">
    @php $tz = $profile->settings['timezone'] ?? 'America/Mexico_City'; @endphp

    <div x-data="onboardingWizard('{{ addslashes($name) }}', '{{ $tz }}')"
         class="relative mx-auto flex min-h-[100dvh] max-w-md flex-col px-6"
         :style="`--accent: ${accent}`">

        <div class="ob-glow"></div>

        {{-- Progress --}}
        <header class="relative z-10 pt-[max(1.25rem,env(safe-area-inset-top))]">
            <div class="flex items-center justify-between">
                <span class="font-display text-base font-extrabold tracking-tight" :style="`color:${accent}`">TITAN</span>
                <span class="font-display text-xs font-bold tabular-nums text-gray-500">
                    <span x-text="String(idx + 1).padStart(2,'0')"></span><span class="text-gray-700"> / <span x-text="String(steps.length).padStart(2,'0')"></span></span>
                </span>
            </div>
            {{-- Tappable segments — jump back to any step you've already done --}}
            <div class="mt-3 flex gap-1">
                <template x-for="(s, i) in steps" :key="s">
                    <button type="button" @click="goTo(i)" :disabled="i > maxIdx"
                            :aria-label="`Go to step ${i + 1}`"
                            class="group flex-1 -my-2 py-2"
                            :class="i <= maxIdx ? 'cursor-pointer' : 'cursor-default'">
                        <span class="block h-1.5 rounded-full transition-all duration-300"
                              :style="i <= idx ? `background:${stepAccents[s] || accent}` : 'background:rgba(255,255,255,0.1)'"></span>
                    </button>
                </template>
            </div>
        </header>

        <form method="POST" action="/onboarding" class="relative z-10 flex flex-1 flex-col" @submit="submitting = true; clearSaved()">
            @csrf
            {{-- All submitted values live in always-present hidden inputs, so they post even when
                 their step isn't currently rendered (sections use x-if). Visible inputs only x-model. --}}
            <input type="hidden" name="display_name" :value="form.display_name">
            <input type="hidden" name="birthdate" :value="birthdate">
            <input type="hidden" name="sex" :value="form.sex">
            <input type="hidden" name="units" :value="form.units">
            <input type="hidden" name="height" :value="form.height">
            <input type="hidden" name="weight" :value="form.weight">
            <input type="hidden" name="activity_level" :value="form.activity_level">
            <input type="hidden" name="primary_goal" :value="form.primary_goal">
            <input type="hidden" name="coach_tone" :value="form.coach_tone">
            <input type="hidden" name="meals_per_day" :value="form.meals_per_day">
            <input type="hidden" name="eat_start" :value="form.eat_start">
            <input type="hidden" name="eat_end" :value="form.eat_end">
            <input type="hidden" name="timezone" :value="form.timezone">
            <input type="hidden" name="cycle_enabled" :value="form.cycle_enabled ? 1 : 0">
            <input type="hidden" name="last_period" :value="form.last_period">
            <input type="hidden" name="cycle_length" :value="form.cycle_length">
            <input type="hidden" name="birth_control" :value="form.birth_control">
            <input type="hidden" name="cycle_intent" :value="form.cycle_intent">

            <main class="flex flex-1 flex-col overflow-y-auto py-5">
                <template x-if="current === 'welcome'">
                    <section class="ob-step text-center">
                        <div class="mx-auto mb-7 grid h-20 w-20 place-items-center rounded-[1.4rem] bg-gradient-to-br from-indigo-500 to-cyan-400 shadow-lg shadow-indigo-500/20">
                            <svg class="h-10 w-10 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <h1 class="font-display text-[2.1rem] font-extrabold leading-[1.05] tracking-tight">Hey <span x-text="form.display_name"></span>.<br>Let's build your Titan.</h1>
                        <p class="mx-auto mt-4 max-w-xs text-[15px] leading-relaxed text-gray-400">A few quick questions so your coach knows exactly who it's training. Takes about a minute.</p>
                    </section>
                </template>

                {{-- Name --}}
                <template x-if="current === 'name'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">First things first</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">What should I call you?</h2>
                        <input x-model="form.display_name" type="text" maxlength="60" autocomplete="given-name"
                               class="mt-7 w-full border-0 border-b-2 border-white/15 bg-transparent px-1 pb-3 font-display text-3xl font-extrabold tracking-tight text-gray-100 placeholder-gray-700 focus:border-indigo-400 focus:ring-0"
                               placeholder="Your name" @keydown.enter.prevent="valid() && next()">
                    </section>
                </template>

                {{-- Birthday --}}
                <template x-if="current === 'birthday'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Your age shapes every score</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">When were you born?</h2>
                        <div class="mt-7 grid grid-cols-[1.4fr_0.8fr_1fr] gap-2.5">
                            <select x-model="form.bMonth" @change="clampDay()" class="ob-sel" :class="form.bMonth ? 'text-gray-100' : 'text-gray-600'">
                                <option value="" disabled>Month</option>
                                <template x-for="m in months" :key="m.v"><option :value="m.v" x-text="m.l"></option></template>
                            </select>
                            <select x-model="form.bDay" class="ob-sel" :class="form.bDay ? 'text-gray-100' : 'text-gray-600'">
                                <option value="" disabled>Day</option>
                                <template x-for="d in daysInMonth" :key="d"><option :value="d" x-text="d"></option></template>
                            </select>
                            <select x-model="form.bYear" @change="clampDay()" class="ob-sel" :class="form.bYear ? 'text-gray-100' : 'text-gray-600'">
                                <option value="" disabled>Year</option>
                                <template x-for="y in years" :key="y"><option :value="y" x-text="y"></option></template>
                            </select>
                        </div>
                        <p class="mt-4 text-sm" :class="age !== null ? 'text-indigo-300 font-semibold' : 'text-gray-600'"
                           x-text="age !== null ? `You're ${age} — readiness, biological age and your macros all use this.` : 'Readiness, biological age and your macros all use this.'"></p>
                    </section>
                </template>

                {{-- Sex --}}
                <template x-if="current === 'sex'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Physiology differs</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">What's your sex?</h2>
                        <p class="mt-2 text-sm text-gray-500">Drives HRV baselines, macros, and cycle tracking.</p>
                        <div class="mt-6 space-y-3">
                            <template x-for="o in sexes" :key="o.value">
                                <button type="button" @click="pick('sex', o.value)"
                                        class="ob-opt flex w-full items-center gap-4 rounded-2xl border-2 px-4 py-4 text-left"
                                        :style="form.sex === o.value ? `border-color:${o.accent}; background:${o.accent}1f` : 'border-color:rgba(255,255,255,0.08)'">
                                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl text-2xl font-bold" :style="`background:${o.accent}26; color:${o.accent}`" x-text="o.glyph"></span>
                                    <span class="flex-1 font-display text-lg font-bold text-gray-100" x-text="o.label"></span>
                                    <svg x-show="form.sex === o.value" class="h-6 w-6" :style="`color:${o.accent}`" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            </template>
                        </div>
                    </section>
                </template>

                {{-- Units --}}
                <template x-if="current === 'units'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Measurements</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">Pick your units.</h2>
                        <div class="mt-6 grid grid-cols-2 gap-3">
                            <template x-for="o in unitsList" :key="o.value">
                                <button type="button" @click="pick('units', o.value)"
                                        class="ob-opt flex flex-col items-center gap-2 rounded-2xl border-2 px-4 py-6"
                                        :style="form.units === o.value ? `border-color:${accent}; background:${accent}1f` : 'border-color:rgba(255,255,255,0.08)'">
                                    <span class="font-display text-2xl font-extrabold" :style="form.units === o.value ? `color:${accent}` : 'color:#e5e7eb'" x-text="o.big"></span>
                                    <span class="text-xs text-gray-500" x-text="o.label"></span>
                                </button>
                            </template>
                        </div>
                    </section>
                </template>

                {{-- Body (height + weight) --}}
                <template x-if="current === 'body'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">The baseline</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">Your height &amp; weight.</h2>
                        <div class="mt-7 space-y-4">
                            <div class="flex items-baseline gap-3 rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-4">
                                <span class="w-20 text-sm font-medium text-gray-500">Height</span>
                                <input x-model="form.height" type="number" step="0.1" inputmode="decimal" placeholder="0"
                                       class="ob-num min-w-0 flex-1 border-0 bg-transparent p-0 text-right text-3xl text-gray-100 placeholder-gray-700 focus:ring-0">
                                <span class="w-8 text-lg font-semibold text-gray-500" x-text="form.units === 'metric' ? 'cm' : 'in'"></span>
                            </div>
                            <div class="flex items-baseline gap-3 rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-4">
                                <span class="w-20 text-sm font-medium text-gray-500">Weight</span>
                                <input x-model="form.weight" type="number" step="0.1" inputmode="decimal" placeholder="0"
                                       class="ob-num min-w-0 flex-1 border-0 bg-transparent p-0 text-right text-3xl text-gray-100 placeholder-gray-700 focus:ring-0">
                                <span class="w-8 text-lg font-semibold text-gray-500" x-text="form.units === 'metric' ? 'kg' : 'lb'"></span>
                            </div>
                        </div>
                    </section>
                </template>

                {{-- Activity --}}
                <template x-if="current === 'activity'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Day to day</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">How active are you?</h2>
                        <div class="mt-6 space-y-3">
                            <template x-for="o in activities" :key="o.value">
                                <button type="button" @click="pick('activity_level', o.value)"
                                        class="ob-opt flex w-full items-center gap-4 rounded-2xl border-2 px-4 py-3.5 text-left"
                                        :style="form.activity_level === o.value ? `border-color:${accent}; background:${accent}1f` : 'border-color:rgba(255,255,255,0.08)'">
                                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :style="`background:${accent}22; color:${accent}`">
                                        {{-- intensity bars: more bars = more active (static rects — x-for can't build SVG nodes) --}}
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                            <rect x="2.5"  y="11.9" width="3.4" height="8.1"  rx="1.2" :opacity="o.level >= 1 ? 1 : 0.2"></rect>
                                            <rect x="7.9"  y="8.8"  width="3.4" height="11.2" rx="1.2" :opacity="o.level >= 2 ? 1 : 0.2"></rect>
                                            <rect x="13.3" y="5.7"  width="3.4" height="14.3" rx="1.2" :opacity="o.level >= 3 ? 1 : 0.2"></rect>
                                            <rect x="18.7" y="2.6"  width="3.4" height="17.4" rx="1.2" :opacity="o.level >= 4 ? 1 : 0.2"></rect>
                                        </svg>
                                    </span>
                                    <span class="flex-1">
                                        <span class="block font-display text-base font-bold text-gray-100" x-text="o.label"></span>
                                        <span class="block text-xs text-gray-500" x-text="o.desc"></span>
                                    </span>
                                </button>
                            </template>
                        </div>
                    </section>
                </template>

                {{-- Goal --}}
                <template x-if="current === 'goal'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">The mission</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">What's your main goal?</h2>
                        <div class="mt-6 space-y-3">
                            <template x-for="o in goals" :key="o.value">
                                <button type="button" @click="pick('primary_goal', o.value)"
                                        class="ob-opt flex w-full items-center gap-4 rounded-2xl border-2 px-4 py-3.5 text-left"
                                        :style="form.primary_goal === o.value ? `border-color:${o.accent}; background:${o.accent}1f` : 'border-color:rgba(255,255,255,0.08)'">
                                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :style="`background:${o.accent}26; color:${o.accent}`">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" :d="o.icon"/></svg>
                                    </span>
                                    <span class="flex-1">
                                        <span class="block font-display text-base font-bold text-gray-100" x-text="o.label"></span>
                                        <span class="block text-xs text-gray-500" x-text="o.desc"></span>
                                    </span>
                                    <svg x-show="form.primary_goal === o.value" class="h-5 w-5 shrink-0" :style="`color:${o.accent}`" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            </template>
                        </div>
                    </section>
                </template>

                {{-- Coaching tone --}}
                <template x-if="current === 'tone'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Your coach's voice</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">How should I push you?</h2>
                        <div class="mt-6 space-y-3">
                            <template x-for="o in tones" :key="o.value">
                                <button type="button" @click="pick('coach_tone', o.value)"
                                        class="ob-opt block w-full rounded-2xl border-2 px-5 py-4 text-left"
                                        :style="form.coach_tone === o.value ? `border-color:${o.accent}; background:${o.accent}1f` : 'border-color:rgba(255,255,255,0.08)'">
                                    <span class="flex items-center gap-2">
                                        <span class="text-xl" x-text="o.emoji"></span>
                                        <span class="font-display text-lg font-bold text-gray-100" x-text="o.label"></span>
                                    </span>
                                    <span class="mt-1 block text-[13px] leading-snug text-gray-500" x-text="o.desc"></span>
                                </button>
                            </template>
                        </div>
                    </section>
                </template>

                {{-- Cycle: enable --}}
                <template x-if="current === 'cycle_enable'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Women's health</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">Track your cycle?</h2>
                        <p class="mt-2 text-sm text-gray-500">Titan weaves your phase into recovery, nutrition and training. Private, and yours alone — never contraception or diagnosis.</p>
                        <div class="mt-6 space-y-3">
                            <button type="button" @click="form.cycle_enabled = true; pickAdvance()"
                                    class="ob-opt flex w-full items-center gap-4 rounded-2xl border-2 px-4 py-4 text-left"
                                    :style="form.cycle_enabled ? 'border-color:#fb7185; background:#fb71851f' : 'border-color:rgba(255,255,255,0.08)'">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl" style="background:#fb718526; color:#fb7185">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-2.64-6.36M21 4v4h-4"/></svg>
                                </span>
                                <span class="flex-1 font-display text-lg font-bold text-gray-100">Yes, track it</span>
                            </button>
                            <button type="button" @click="form.cycle_enabled = false; pickAdvance()"
                                    class="ob-opt flex w-full items-center gap-4 rounded-2xl border-2 px-4 py-4 text-left border-white/[0.08]">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white/5 text-gray-400">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </span>
                                <span class="flex-1 font-display text-lg font-bold text-gray-300">Not now</span>
                            </button>
                        </div>
                    </section>
                </template>

                {{-- Cycle: details --}}
                <template x-if="current === 'cycle_details'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Cycle setup</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">A few cycle details.</h2>
                        <div class="mt-6 space-y-4">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-500">First day of your last period</label>
                                <input x-model="form.last_period" type="date" max="{{ now()->toDateString() }}"
                                       class="w-full rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-3.5 text-lg font-semibold text-gray-100 focus:border-rose-400 focus:ring-0">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-500">Typical cycle length</label>
                                <div class="flex items-baseline gap-3 rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-3.5">
                                    <input x-model="form.cycle_length" type="number" min="21" max="45"
                                           class="ob-num min-w-0 flex-1 border-0 bg-transparent p-0 text-2xl text-gray-100 focus:ring-0">
                                    <span class="text-sm text-gray-500">days</span>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-500">Birth control</label>
                                <select x-model="form.birth_control" class="w-full rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-3.5 text-base text-gray-100 focus:border-rose-400 focus:ring-0">
                                    <option value="none">None</option><option value="pill">Pill</option>
                                    <option value="hormonal_iud">Hormonal IUD</option><option value="copper_iud">Copper IUD</option>
                                    <option value="implant">Implant</option><option value="ring">Ring</option>
                                    <option value="patch">Patch</option><option value="injection">Injection</option><option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </section>
                </template>

                {{-- Nutrition --}}
                <template x-if="current === 'nutrition'">
                    <section class="ob-step">
                        <p class="font-display text-sm font-bold uppercase tracking-[0.12em] text-gray-600">Fuel</p>
                        <h2 class="mt-2 font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">How many meals a day?</h2>
                        <p class="mt-2 text-sm text-gray-500">Titan reminds you to eat before you're hungry. We'll calculate your targets.</p>
                        <div class="mt-6 grid grid-cols-5 gap-2.5">
                            <template x-for="n in [2,3,4,5,6]" :key="n">
                                <button type="button" @click="form.meals_per_day = n; pickAdvance()"
                                        class="ob-opt grid aspect-square place-items-center rounded-2xl border-2 font-display text-2xl font-extrabold"
                                        :style="form.meals_per_day === n ? `border-color:${accent}; background:${accent}1f; color:${accent}` : 'border-color:rgba(255,255,255,0.08); color:#d1d5db'"
                                        x-text="n"></button>
                            </template>
                        </div>
                        <div class="mt-5 grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-500">First meal</label>
                                <input x-model="form.eat_start" type="time" class="w-full rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-3 text-base text-gray-100 focus:ring-0">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-500">Last meal</label>
                                <input x-model="form.eat_end" type="time" class="w-full rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-3 text-base text-gray-100 focus:ring-0">
                            </div>
                        </div>
                    </section>
                </template>

                {{-- Finish --}}
                <template x-if="current === 'finish'">
                    <section class="ob-step text-center">
                        <div class="mx-auto mb-6 grid h-20 w-20 place-items-center rounded-full bg-emerald-500/15">
                            <svg class="h-10 w-10 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h2 class="font-display text-[1.9rem] font-extrabold leading-tight tracking-tight">You're all set, <span x-text="form.display_name"></span>.</h2>
                        <p class="mx-auto mt-3 max-w-xs text-[15px] leading-relaxed text-gray-400">I'll calculate your nutrition targets and start learning from everything you log.</p>
                        <div class="mt-7 space-y-2 rounded-2xl border border-white/[0.07] bg-white/[0.03] p-4 text-left text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Goal</span><span class="font-semibold text-gray-200" x-text="goalLabel"></span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Coaching</span><span class="font-semibold capitalize text-gray-200" x-text="form.coach_tone.replace('_',' ')"></span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Meals / day</span><span class="font-semibold text-gray-200" x-text="form.meals_per_day"></span></div>
                            <div class="flex justify-between" x-show="form.sex === 'F' && form.cycle_enabled"><span class="text-gray-500">Cycle</span><span class="font-semibold text-rose-300">Tracking on</span></div>
                        </div>
                    </section>
                </template>
            </main>

            {{-- Sticky nav --}}
            <footer class="sticky bottom-0 z-10 -mx-6 flex items-center gap-3 bg-gradient-to-t from-[#07080a] via-[#07080a]/95 to-transparent px-6 pb-[max(1rem,env(safe-area-inset-bottom))] pt-4">
                <button type="button" x-show="idx > 0" @click="back()" aria-label="Back"
                        class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl border border-white/10 text-gray-400 active:bg-white/5">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" x-show="current !== 'finish'" @click="next()" :disabled="!valid()"
                        class="h-14 flex-1 rounded-2xl font-display text-base font-bold text-white transition disabled:opacity-30 disabled:cursor-not-allowed"
                        :style="valid() ? `background:linear-gradient(90deg, #6366f1, ${accent})` : 'background:rgba(255,255,255,0.08)'"
                        x-text="idx === 0 ? 'Get started' : 'Continue'"></button>
                <button type="submit" x-show="current === 'finish'" :disabled="submitting"
                        class="h-14 flex-1 rounded-2xl bg-gradient-to-r from-indigo-500 to-cyan-400 font-display text-base font-bold text-gray-900 active:opacity-90 disabled:opacity-60">
                    <span x-show="!submitting">Enter Titan →</span>
                    <span x-show="submitting" x-cloak>Setting up…</span>
                </button>
            </footer>
        </form>
    </div>

    <script>
        function onboardingWizard(name, tz) {
            return {
                idx: 0,
                maxIdx: 0,           // furthest step reached — you can jump back to any step ≤ this
                submitting: false,
                STORE_KEY: 'titan_onboarding_v1',
                form: {
                    display_name: name || '',
                    bMonth: '', bDay: '', bYear: '', sex: '', units: 'metric', height: '', weight: '',
                    activity_level: '', timezone: tz || 'UTC',
                    primary_goal: '', coach_tone: '',
                    meals_per_day: 0, eat_start: '08:00', eat_end: '21:00',
                    cycle_enabled: false, last_period: '', cycle_length: 28, birth_control: 'none', cycle_intent: 'tracking',
                },

                sexes: [
                    { value: 'F', label: 'Female', glyph: '♀', accent: '#fb7185' },
                    { value: 'M', label: 'Male', glyph: '♂', accent: '#22d3ee' },
                    { value: 'other', label: 'Other / prefer not to say', glyph: '⚬', accent: '#a78bfa' },
                ],
                unitsList: [
                    { value: 'metric', big: 'kg · cm', label: 'Metric' },
                    { value: 'imperial', big: 'lb · in', label: 'Imperial' },
                ],
                activities: [
                    { value: 'sedentary', label: 'Sedentary', desc: 'Desk job, little exercise', level: 1 },
                    { value: 'light', label: 'Light', desc: '1–3 workouts a week', level: 2 },
                    { value: 'moderate', label: 'Moderate', desc: '4–5 workouts a week', level: 3 },
                    { value: 'active', label: 'Very active', desc: '6+ a week, or a physical job', level: 4 },
                ],
                goals: [
                    { value: 'build_muscle', label: 'Build muscle', desc: 'Add lean mass and strength', accent: '#a78bfa', icon: 'M6.5 6.5l11 11M4 9l1.5-1.5M9 4L7.5 5.5m9 13L18 17m-1-9l2-2' },
                    { value: 'lose_fat', label: 'Get lean', desc: 'Drop fat, keep muscle', accent: '#22d3ee', icon: 'M19 14l-7 7-7-7M12 3v18' },
                    { value: 'recomp', label: 'Recomposition', desc: 'Leaner and stronger at once', accent: '#818cf8', icon: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15' },
                    { value: 'longevity', label: 'Longevity', desc: 'Add healthy years', accent: '#34d399', icon: 'M12 21s-6-4.35-9-8.5C1 9 3 5 7 5c2 0 3 1 5 3 2-2 3-3 5-3 4 0 6 4 4 7.5C18 16.65 12 21 12 21z' },
                    { value: 'performance', label: 'Performance', desc: 'Train for output and capacity', accent: '#fbbf24', icon: 'M13 10V3L4 14h7v7l9-11h-7z' },
                    { value: 'general', label: 'Feel good', desc: 'Energy, sleep, general health', accent: '#fb7185', icon: 'M12 3v1.5m0 15V21m9-9h-1.5M4.5 12H3m15.36 6.36l-1.06-1.06M6.7 6.7L5.64 5.64m12.72 0L17.3 6.7M6.7 17.3l-1.06 1.06M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
                ],
                tones: [
                    { value: 'tough_love', label: 'Tough love', emoji: '🔥', desc: 'Direct and demanding. Calls out excuses, pushes you hard.', accent: '#fb7185' },
                    { value: 'balanced', label: 'Balanced', emoji: '⚖️', desc: 'Supportive but straight — honest about what needs work.', accent: '#818cf8' },
                    { value: 'gentle', label: 'Gentle', emoji: '🌱', desc: 'Warm and patient. Celebrates small wins, never shames.', accent: '#34d399' },
                ],
                goalLabels: {
                    build_muscle: 'Build muscle', lose_fat: 'Get lean', recomp: 'Recomposition',
                    longevity: 'Longevity', performance: 'Performance', general: 'Feel good',
                },
                stepAccents: {
                    welcome: '#6366f1', name: '#6366f1', birthday: '#6366f1', sex: '#a78bfa', units: '#22d3ee',
                    body: '#22d3ee', activity: '#22d3ee', goal: '#a78bfa', tone: '#818cf8',
                    cycle_enable: '#fb7185', cycle_details: '#fb7185', nutrition: '#34d399', finish: '#22d3ee',
                },

                months: [
                    { v: 1, l: 'January' }, { v: 2, l: 'February' }, { v: 3, l: 'March' }, { v: 4, l: 'April' },
                    { v: 5, l: 'May' }, { v: 6, l: 'June' }, { v: 7, l: 'July' }, { v: 8, l: 'August' },
                    { v: 9, l: 'September' }, { v: 10, l: 'October' }, { v: 11, l: 'November' }, { v: 12, l: 'December' },
                ],
                get years() {
                    const max = new Date().getFullYear() - 13;   // 13+ to register
                    const out = [];
                    for (let y = max; y >= 1920; y--) out.push(y);
                    return out;
                },
                get daysInMonth() {
                    const m = Number(this.form.bMonth);
                    const y = Number(this.form.bYear) || 2000;
                    const n = m ? new Date(y, m, 0).getDate() : 31;
                    return Array.from({ length: n }, (_, i) => i + 1);
                },
                get birthdate() {
                    const { bYear, bMonth, bDay } = this.form;
                    if (!bYear || !bMonth || !bDay) return '';
                    return `${bYear}-${String(bMonth).padStart(2, '0')}-${String(bDay).padStart(2, '0')}`;
                },
                get age() {
                    if (!this.birthdate) return null;
                    const b = new Date(this.birthdate + 'T00:00:00'), now = new Date();
                    let a = now.getFullYear() - b.getFullYear();
                    if (now.getMonth() < b.getMonth() || (now.getMonth() === b.getMonth() && now.getDate() < b.getDate())) a--;
                    return a >= 13 && a < 120 ? a : null;
                },
                clampDay() {
                    if (Number(this.form.bDay) > this.daysInMonth.length) this.form.bDay = '';
                },
                get goalLabel() { return this.goalLabels[this.form.primary_goal] || '—'; },

                // --- Persistence: survive refresh, and clear on finish ---
                get snapshot() { return JSON.stringify({ idx: this.idx, maxIdx: this.maxIdx, form: this.form }); },
                init() {
                    this.load();
                    // snapshot touches every reactive field, so this saves on ANY change.
                    this.$watch('snapshot', (val) => { try { localStorage.setItem(this.STORE_KEY, val); } catch (e) {} });
                },
                load() {
                    try {
                        const saved = JSON.parse(localStorage.getItem(this.STORE_KEY) || 'null');
                        if (!saved) return;
                        if (saved.form) Object.assign(this.form, saved.form);
                        if (typeof saved.maxIdx === 'number') this.maxIdx = saved.maxIdx;
                        if (typeof saved.idx === 'number') this.idx = Math.min(saved.idx, this.steps.length - 1);
                        this.maxIdx = Math.min(Math.max(this.maxIdx, this.idx), this.steps.length - 1);
                    } catch (e) {}
                },
                clearSaved() { try { localStorage.removeItem(this.STORE_KEY); } catch (e) {} },

                get steps() {
                    const s = ['welcome', 'name', 'birthday', 'sex', 'units', 'body', 'activity', 'goal', 'tone'];
                    if (this.form.sex === 'F') {
                        s.push('cycle_enable');
                        if (this.form.cycle_enabled) s.push('cycle_details');
                    }
                    s.push('nutrition', 'finish');
                    return s;
                },
                get current() { return this.steps[Math.min(this.idx, this.steps.length - 1)]; },
                get accent() { return this.stepAccents[this.current] || '#6366f1'; },

                valid() {
                    const f = this.form;
                    switch (this.current) {
                        case 'name': return f.display_name.trim().length > 0;
                        case 'birthday': return this.age !== null;
                        case 'sex': return !!f.sex;
                        case 'units': return !!f.units;
                        case 'body': return Number(f.height) > 0 && Number(f.weight) > 0;
                        case 'activity': return !!f.activity_level;
                        case 'goal': return !!f.primary_goal;
                        case 'tone': return !!f.coach_tone;
                        case 'nutrition': return Number(f.meals_per_day) >= 2;
                        default: return true;
                    }
                },

                // Choose a value then glide to the next screen (the one-tap feel).
                pick(field, value) { this.form[field] = value; this.pickAdvance(); },
                pickAdvance() {
                    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    setTimeout(() => { if (this.valid()) this.next(); }, reduce ? 0 : 320);
                },
                next() {
                    if (!this.valid()) return;
                    if (this.idx < this.steps.length - 1) this.idx++;
                    this.maxIdx = Math.max(this.maxIdx, this.idx);
                    window.scrollTo({ top: 0 });
                },
                back() { if (this.idx > 0) this.idx--; window.scrollTo({ top: 0 }); },
                // Jump straight to any step already reached (tap the progress segments).
                goTo(i) { if (i <= this.maxIdx && i >= 0) { this.idx = i; window.scrollTo({ top: 0 }); } },
            };
        }
    </script>
</body>
</html>
