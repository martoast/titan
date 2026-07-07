<x-titan-layout title="Dream Physique" subtitle="Who you're becoming — and how far you've come.">
    @php
        /** @var \App\Models\PhysiqueGoal|null $goal */
        /** @var array $dreamShots */              // [{angle, source_url, goal_url}]
        /** @var \Illuminate\Support\Collection $photos */
        /** @var \App\Models\PhysiqueAnalysis|null $latestAnalysis */
        /** @var \App\Models\LivingGoalRender|null $latestRender */
        /** @var \Illuminate\Support\Collection $livingRenders */
        /** @var float $adherence */
        $latestPctAnalysis = \App\Models\PhysiqueAnalysis::where('profile_id', $profile->id)
            ->whereNotNull('pct_to_goal')->latest()->first();
        $pct = $latestPctAnalysis->pct_to_goal ?? null;
        $adherencePct = (int) round(($adherence ?? 0) * 100);
        $hasDream = ! empty($dreamShots);
        // A dream is "complete" only with all three angles — otherwise we keep the capture open so
        // the user is clearly invited to add the missing back / side (where glutes & legs show).
        $dreamAngles = collect($dreamShots)->pluck('angle')->all();
        $dreamComplete = $hasDream && count(array_intersect(['front', 'back', 'side'], $dreamAngles)) === 3;
        $hasLivingFoundation = $goal && $goal->goalUrl() && $photos->isNotEmpty();

        // Progress photos → JS for the compare reveal + gallery (newest first as passed).
        $photoData = $photos->map(fn ($p) => [
            'id' => $p->id, 'url' => $p->photoUrl(),
            'short' => $p->taken_at?->format('M j'),
        ])->values();
    @endphp

    @if (session('status'))
        <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-xl bg-rose-500/10 border border-rose-500/20 px-4 py-3 text-sm text-rose-300">{{ session('error') }}</div>
    @endif
    @unless ($imageGenConfigured && $aiConfigured)
        <div class="mb-4 rounded-xl bg-amber-500/10 border border-amber-500/20 px-4 py-3 text-xs text-amber-300/90">
            Some AI features are offline. You can still log progress photos — generation and analysis activate once the keys are set.
        </div>
    @endunless

    <div class="space-y-5"
         x-data="physiquePage({
            photos: {{ \Illuminate\Support\Js::from($photoData) }},
            dreamShots: {{ \Illuminate\Support\Js::from($dreamShots) }},
            goalId: {{ $goal?->id ?? 'null' }},
         })">

        {{-- ════════════════ HERO · THE DREAM PHYSIQUE (every angle) ════════════════ --}}
        <section class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-indigo-950/40 via-gray-900/60 to-cyan-950/30 shadow-2xl shadow-black/40">
            <div class="absolute inset-0 bg-[radial-gradient(70%_60%_at_50%_0%,rgba(99,102,241,0.16),transparent)] pointer-events-none"></div>

            {{-- A · has a dream → multi-angle before/after showcase --}}
            <template x-if="dShots.length">
                <div class="relative">
                    {{-- header --}}
                    <div class="flex items-start justify-between gap-3 px-4 pt-4 sm:px-5 sm:pt-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-cyan-300/70">Your dream physique</p>
                            <h2 class="mt-0.5 font-display text-lg sm:text-xl font-extrabold leading-tight text-gray-100">This is who you're becoming.</h2>
                        </div>
                        @if ($pct !== null)
                            <div class="shrink-0 text-right">
                                <p class="font-display text-2xl font-black nums bg-gradient-to-r from-indigo-300 to-cyan-200 bg-clip-text text-transparent leading-none">{{ $pct }}%</p>
                                <p class="text-[9px] uppercase tracking-wide text-white/50">to goal</p>
                            </div>
                        @endif
                    </div>

                    {{-- angle tabs (only the angles that exist) --}}
                    <div x-show="dShots.length > 1" class="flex gap-1.5 px-4 pt-3 sm:px-5">
                        <template x-for="s in dShots" :key="s.angle">
                            <button type="button" @click="dActive = s.angle; dPos = 50"
                                    class="rounded-full px-4 py-1.5 text-xs font-bold capitalize transition"
                                    :style="dActive === s.angle ? 'background:linear-gradient(90deg,#6366f1,#22d3ee); color:#0b1220' : 'background:rgba(255,255,255,0.06); color:#cbd5e1'"
                                    x-text="s.angle"></button>
                        </template>
                    </div>

                    {{-- before/after reveal for the active angle --}}
                    <div class="p-4 sm:p-5">
                        <div class="relative aspect-[4/5] sm:aspect-[3/2] rounded-2xl overflow-hidden border border-white/10 bg-gray-950 select-none touch-none cursor-ew-resize"
                             @pointerdown="dStart($event)" @pointermove="dMove($event)" @pointerup="dEnd()" @pointerleave="dEnd()">
                            <img :src="dShot?.goal_url" class="absolute inset-0 h-full w-full object-cover" draggable="false">
                            <div class="absolute inset-0 overflow-hidden" :style="`clip-path: inset(0 ${100 - dPos}% 0 0)`">
                                <img :src="dShot?.source_url" class="absolute inset-0 h-full w-full object-cover" draggable="false">
                            </div>
                            {{-- labels --}}
                            <div class="absolute inset-x-0 top-0 h-20 bg-gradient-to-b from-black/55 to-transparent pointer-events-none"></div>
                            <span class="absolute top-3 left-3 z-10 rounded-md bg-black/50 backdrop-blur px-2 py-0.5 text-[11px] font-semibold text-white/90">Now</span>
                            <span class="absolute top-3 right-3 z-10 rounded-md bg-cyan-500/85 backdrop-blur px-2 py-0.5 text-[11px] font-bold text-gray-950">Goal</span>
                            {{-- handle --}}
                            <div class="absolute top-0 bottom-0 z-10 w-[2px] bg-white/90 shadow-[0_0_12px_rgba(255,255,255,0.5)]" :style="`left:${dPos}%`">
                                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/95 grid place-items-center shadow-lg ring-1 ring-black/10">
                                    <svg class="h-5 w-5 text-gray-800" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7l-4 5 4 5m8-10l4 5-4 5"/></svg>
                                </div>
                            </div>
                            <p class="absolute bottom-3 left-1/2 -translate-x-1/2 z-10 text-[10px] text-white/50 pointer-events-none">drag to reveal</p>
                        </div>

                        @if ($goal?->description)
                            <p class="mt-3 text-center text-sm text-gray-400 italic">“{{ $goal->description }}”</p>
                        @endif

                        {{-- which angles you have / are missing --}}
                        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                            <template x-for="a in BUILD_ANGLES" :key="a">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium capitalize"
                                      :class="hasAngle(a) ? 'bg-cyan-500/10 text-cyan-300' : 'bg-white/[0.04] text-gray-500'">
                                    <svg x-show="hasAngle(a)" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    <span x-text="a"></span>
                                </span>
                            </template>
                        </div>
                        {{-- Front-only dreams get a loud nudge to add the angles that actually show glutes/legs --}}
                        <p x-show="!hasAllAngles()" class="mt-3 text-center text-xs text-cyan-300/80">Front only — add your back & side so glute, leg and back goals show.</p>
                        <button type="button" @click="openBuild()"
                                class="mt-3 w-full h-12 rounded-xl text-sm font-bold transition"
                                :class="hasAllAngles() ? 'bg-white/[0.06] border border-white/10 text-gray-200 active:bg-white/10' : 'text-gray-950 active:brightness-110'"
                                :style="hasAllAngles() ? '' : 'background:linear-gradient(90deg,#6366f1,#22d3ee)'"
                                x-text="hasAllAngles() ? 'Refine my dream physique' : 'Add your back & side angles ✨'"></button>
                    </div>
                </div>
            </template>

            {{-- B · no dream yet → aspirational empty state --}}
            <template x-if="!dShots.length">
                <div class="relative grid place-items-center text-center px-6 py-14">
                    <div class="max-w-sm">
                        <div class="mx-auto mb-4 grid h-16 w-16 place-items-center rounded-2xl bg-gradient-to-br from-indigo-500 to-cyan-400 shadow-lg shadow-indigo-500/20">
                            <svg class="h-8 w-8 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <h2 class="font-display text-xl sm:text-2xl font-extrabold text-gray-100">See your dream physique.</h2>
                        <p class="mt-2 text-sm text-gray-400">Add a front, back and side photo and I'll render a realistic, aspirational version of future you — so your glute, leg and back goals actually show.</p>
                        <button @click="openBuild()"
                                class="mt-5 inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 h-12 px-6 text-sm font-bold text-gray-950 active:brightness-110">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                            Build my dream physique
                        </button>
                    </div>
                </div>
            </template>
        </section>

        {{-- ════════════════ BUILD / REFINE · multi-angle capture ════════════════ --}}
        <section x-show="buildOpen" x-collapse x-cloak x-ref="build" class="scroll-mt-20 rounded-2xl border border-cyan-500/20 bg-cyan-950/10 p-4 md:p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-display text-lg font-bold text-gray-100" x-text="anyBuilt() ? 'Complete your dream physique' : 'Build your dream physique'"></h3>
                    <p class="text-xs text-gray-500 mt-0.5">Front is required. Add back & side — that's where glute, leg and back goals show.</p>
                </div>
                <button @click="buildOpen = false" class="shrink-0 grid h-9 w-9 place-items-center rounded-lg text-gray-500 active:bg-white/5">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <input x-model="buildDesc" maxlength="160" type="text" placeholder="Any detail to nail? (e.g. rounder glutes, visible abs) — optional"
                   class="mt-4 w-full h-11 rounded-xl border border-white/10 bg-gray-950/50 px-3.5 text-base text-gray-100 placeholder:text-gray-600 focus:border-cyan-500/50 focus:outline-none">

            <div class="mt-4 space-y-3">
                <template x-for="a in BUILD_ANGLES" :key="a">
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-3.5">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <span class="font-display text-sm font-bold text-gray-100" x-text="buildMeta[a].label"></span>
                                <span class="ml-1.5 text-[11px] font-semibold uppercase tracking-wider" :class="buildMeta[a].req ? 'text-cyan-300/80' : 'text-gray-600'" x-text="buildMeta[a].req ? 'Required' : 'Optional'"></span>
                                <p class="text-xs text-gray-500" x-text="buildMeta[a].hint"></p>
                            </div>
                            <span class="shrink-0">
                                <svg x-show="build[a].status === 'generating'" class="h-5 w-5 animate-spin text-cyan-300" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" stroke-opacity="0.25"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                                <svg x-show="build[a].status === 'done'" class="h-5 w-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </span>
                        </div>

                        {{-- dropzone (no current photo for this angle) --}}
                        <label x-show="!build[a].preview && build[a].status !== 'done'"
                               class="mt-3 flex w-full cursor-pointer items-center justify-center rounded-xl border-2 border-dashed border-white/15 bg-white/[0.02] py-6 transition active:bg-white/[0.05]">
                            <input type="file" accept="image/*" class="hidden" @change="onBuildPhoto(a, $event)">
                            <span class="flex flex-col items-center gap-1.5 text-gray-400">
                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5V18a2 2 0 002 2h14a2 2 0 002-2v-1.5M12 16V3m0 0L8 7m4-4l4 4"/></svg>
                                <span class="font-display text-xs font-bold text-gray-200" x-text="'Add your ' + buildMeta[a].label.toLowerCase() + ' photo'"></span>
                            </span>
                        </label>

                        {{-- chosen-but-not-rendered preview (with optional generating overlay) --}}
                        <label x-show="build[a].preview && build[a].status !== 'done'"
                               class="mt-3 block cursor-pointer overflow-hidden rounded-xl border border-cyan-400/30">
                            <input type="file" accept="image/*" class="hidden" @change="onBuildPhoto(a, $event)">
                            <div class="relative">
                                <img :src="build[a].preview" alt="" class="max-h-48 w-full object-contain bg-gray-950">
                                <div x-show="build[a].status === 'generating'" x-cloak class="absolute inset-0 grid place-items-center bg-black/55 backdrop-blur-[2px]">
                                    <span class="font-display text-xs font-bold text-cyan-200">Sculpting…</span>
                                </div>
                            </div>
                        </label>

                        {{-- rendered result: before / after + change --}}
                        <div x-show="build[a].status === 'done'" x-cloak class="mt-3">
                            <div class="grid grid-cols-2 gap-2.5">
                                <figure class="space-y-1">
                                    <img :src="build[a].source" alt="" class="aspect-[3/4] w-full rounded-xl border border-white/10 object-cover">
                                    <figcaption class="text-center text-[11px] font-medium uppercase tracking-wider text-gray-600">Now</figcaption>
                                </figure>
                                <figure class="space-y-1">
                                    <img :src="build[a].goal" alt="" class="aspect-[3/4] w-full rounded-xl border-2 object-cover" style="border-color:rgba(34,211,238,0.4)">
                                    <figcaption class="text-center text-[11px] font-bold uppercase tracking-wider" style="color:#67e8f9">Goal</figcaption>
                                </figure>
                            </div>
                            <label class="mt-2 block cursor-pointer text-center text-xs font-medium text-gray-400 active:text-gray-200">
                                <input type="file" accept="image/*" class="hidden" @change="onBuildPhoto(a, $event)">
                                ↻ Change photo
                            </label>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" x-show="buildPending" @click="generateBuild()" :disabled="buildGen || !buildFrontReady"
                    class="mt-4 h-12 w-full rounded-xl font-display text-base font-bold transition disabled:cursor-not-allowed"
                    :style="(!buildGen && buildFrontReady) ? 'background:linear-gradient(90deg,#6366f1,#22d3ee); color:#0b1220' : 'background:rgba(255,255,255,0.08); color:rgba(255,255,255,0.4)'">
                <span x-show="!buildGen" x-text="anyBuilt() ? 'Render the rest ✨' : 'Generate my dream physique ✨'"></span>
                <span x-show="buildGen" x-cloak>Sculpting your future self…</span>
            </button>
            <button type="button" x-show="anyBuilt() && !buildPending && !buildGen" @click="buildOpen = false"
                    class="mt-2 h-12 w-full rounded-xl font-display text-base font-bold text-gray-900" style="background:linear-gradient(90deg,#6366f1,#22d3ee)">Looks great — done</button>
            <p x-show="buildErr" x-cloak x-text="buildErr" @click="buildErr=''" class="mt-3 text-center text-sm text-rose-300"></p>
            @unless ($imageGenConfigured)<p class="mt-3 text-center text-xs text-amber-400/80">Image generation is offline — add a GEMINI_API_KEY.</p>@endunless

            @if ($profile->physiqueGoals()->count() > 1)
                <div class="mt-5 pt-4 border-t border-white/5">
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-2">Earlier dream renders</div>
                    <div class="flex gap-2.5 overflow-x-auto no-scrollbar -mx-1 px-1 pb-1">
                        @foreach ($profile->physiqueGoals()->latest()->get() as $g)
                            @if ($g->goalUrl())
                                <div class="relative shrink-0">
                                    <img src="{{ $g->goalUrl() }}" class="h-24 w-[4.8rem] object-cover rounded-xl border {{ $g->is_active ? 'border-cyan-400/60 ring-1 ring-cyan-500/30' : 'border-white/5' }}">
                                    @unless ($g->is_active)
                                        <form method="POST" action="{{ route('physique.goal.activate', $g) }}" class="absolute inset-x-0 bottom-0">@csrf
                                            <button class="w-full bg-black/70 text-[10px] text-gray-200 py-1 rounded-b-xl active:bg-indigo-600/80">Use</button>
                                        </form>
                                    @endunless
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        {{-- ════════════════ PROGRESS · % to goal + this week's living you ════════════════ --}}
        <section class="glass-card p-4 md:p-5">
            <div class="grid grid-cols-3 gap-3">
                <div class="text-center">
                    <div class="font-display text-2xl font-black nums bg-gradient-to-r from-indigo-400 to-cyan-300 bg-clip-text text-transparent leading-none">{{ $pct !== null ? $pct.'%' : '—' }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">To goal</div>
                </div>
                <div class="text-center">
                    <div class="font-display text-2xl font-black nums text-gray-100 leading-none">{{ $photos->count() }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">Photos</div>
                </div>
                <div class="text-center">
                    <div class="font-display text-2xl font-black nums text-gray-100 leading-none">{{ $latestAnalysis && $latestAnalysis->bodyFatRange() ? $latestAnalysis->bodyFatRange() : $adherencePct.'%' }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">{{ $latestAnalysis && $latestAnalysis->bodyFatRange() ? 'Body fat' : 'Consistency' }}</div>
                </div>
            </div>

            @if ($pct !== null)
                <div class="mt-4">
                    <div class="h-2.5 w-full rounded-full bg-gray-800 overflow-hidden"><div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $pct }}%"></div></div>
                    <p class="mt-1.5 text-[11px] text-gray-500">{{ $latestPctAnalysis?->summary ?? ($pct.'% of the way to your dream physique.') }}</p>
                </div>
            @endif

            {{-- living "this week's you" --}}
            <div class="mt-5 rounded-2xl border border-cyan-500/15 bg-cyan-950/15 p-4">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 shrink-0 text-cyan-300/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <h3 class="font-display font-bold text-gray-100">Your living dream physique</h3>
                </div>
                <p class="text-sm text-gray-400 mt-1">The you in the picture advances as you stay consistent.</p>

                @if ($hasLivingFoundation)
                    <div class="mt-4 grid grid-cols-[7rem_1fr] gap-4 items-start">
                        @if ($livingImageUrl)
                            <figure class="rounded-xl overflow-hidden border border-cyan-500/25 bg-gray-950">
                                <img src="{{ $livingImageUrl }}" alt="This week's step" class="w-full aspect-[3/4] object-cover">
                                <figcaption class="px-2 py-1 text-[10px] text-cyan-300/80 bg-cyan-500/5 truncate">{{ $latestRender ? $latestRender->step_pct.'% there' : 'This week' }}</figcaption>
                            </figure>
                        @else
                            <div class="rounded-xl border border-dashed border-white/10 bg-gray-950/40 grid place-items-center aspect-[3/4] p-2 text-center text-[11px] text-gray-500">Render this week →</div>
                        @endif
                        <p class="text-sm text-gray-300 leading-relaxed">{{ $latestPctAnalysis?->summary ?? "Recalculate your % to goal to see what's improving and what to focus on next." }}</p>
                    </div>

                    @if ($livingRenders->isNotEmpty())
                        <div class="mt-4">
                            <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-2">Week-by-week</div>
                            <div class="flex items-stretch gap-2 overflow-x-auto no-scrollbar -mx-1 px-1 pb-1">
                                @foreach ($livingRenders as $r)
                                    @if ($r->imageUrl())
                                        <figure class="shrink-0 w-[4.5rem]">
                                            <img src="{{ $r->imageUrl() }}" class="h-[6rem] w-full object-cover rounded-lg border {{ $loop->last ? 'border-cyan-400/50 ring-1 ring-cyan-500/30' : 'border-white/5' }}">
                                            <figcaption class="mt-1 text-center text-[9px] nums text-gray-500">{{ $r->created_at?->format('M j') }} · <span class="text-cyan-300/80">{{ $r->step_pct }}%</span></figcaption>
                                        </figure>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <div class="mt-3 rounded-xl border border-white/5 bg-gray-950/40 p-3 text-sm text-gray-400">
                        Build your dream physique and add a progress photo — then I'll render the same you, one believable step closer each week.
                    </div>
                @endif

                <div class="mt-4 flex flex-col sm:flex-row gap-2.5">
                    <form method="POST" action="{{ route('physique.living') }}" class="flex-1" @submit="startWork('render')">
                        @csrf
                        <button type="submit" @disabled(! $imageGenConfigured || ! $hasLivingFoundation)
                                class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 h-11 px-4 text-sm font-semibold text-gray-950 active:brightness-110 transition disabled:opacity-50">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Render this week's step
                        </button>
                    </form>
                    @if ($goal)
                        <form method="POST" action="{{ route('physique.compare') }}" class="flex-1 sm:flex-initial" @submit="startWork('compare')">
                            @csrf
                            <button type="submit" @disabled(! $aiConfigured || $photos->isEmpty())
                                    class="w-full h-11 rounded-xl border border-white/10 px-4 text-sm font-medium text-gray-200 active:bg-white/10 transition disabled:opacity-50">Recalculate % to goal</button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        {{-- ════════════════ PROGRESS GALLERY ════════════════ --}}
        <section class="glass-card p-4 md:p-5">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h3 class="font-display text-lg font-bold text-gray-100">Progress photos</h3>
                    <p class="text-xs text-gray-500 mt-0.5">@if ($photos->count() >= 2) Tap two to compare them side by side. @else Your journey, one photo at a time. @endif</p>
                </div>
                <button @click="addOpen = !addOpen" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-indigo-500 h-10 px-3.5 text-sm font-semibold text-white active:bg-indigo-400 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add
                </button>
            </div>

            {{-- compare reveal (appears when two photos are picked) --}}
            <div x-show="beforeId && afterId" x-cloak x-collapse class="mb-5">
                <div class="relative aspect-[4/5] sm:aspect-[16/10] rounded-2xl overflow-hidden border border-white/10 bg-gray-950 select-none touch-none cursor-ew-resize"
                     @pointerdown="pStart($event)" @pointermove="pMove($event)" @pointerup="pEnd()" @pointerleave="pEnd()">
                    <img :src="afterSrc" class="absolute inset-0 h-full w-full object-cover" draggable="false">
                    <div class="absolute inset-0 overflow-hidden" :style="`clip-path: inset(0 ${100 - pos}% 0 0)`">
                        <img :src="beforeSrc" class="absolute inset-0 h-full w-full object-cover" draggable="false">
                    </div>
                    <span class="absolute top-3 left-3 z-10 rounded-md bg-black/50 backdrop-blur px-2 py-0.5 text-[11px] font-semibold text-white" x-text="beforeLabel"></span>
                    <span class="absolute top-3 right-3 z-10 rounded-md bg-indigo-500/80 backdrop-blur px-2 py-0.5 text-[11px] font-semibold text-gray-950" x-text="afterLabel"></span>
                    <div class="absolute top-0 bottom-0 z-10 w-[2px] bg-white/90 shadow-[0_0_12px_rgba(255,255,255,0.5)]" :style="`left:${pos}%`">
                        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-10 w-10 rounded-full bg-white/95 grid place-items-center shadow-lg ring-1 ring-black/10">
                            <svg class="h-4 w-4 text-gray-800" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7l-4 5 4 5m8-10l4 5-4 5"/></svg>
                        </div>
                    </div>
                    <button @click="clearPick()" class="absolute bottom-3 left-1/2 -translate-x-1/2 z-10 rounded-full bg-black/55 backdrop-blur px-3 py-1 text-[11px] text-white/80 active:bg-black/70">Clear</button>
                </div>
            </div>

            {{-- collapsible upload form --}}
            <div x-show="addOpen" x-collapse x-cloak x-ref="addForm" class="mb-5 scroll-mt-20">
                <form method="POST" action="{{ route('physique.photo.store') }}" enctype="multipart/form-data"
                      x-data="{ name: '', preview: '' }" class="rounded-2xl border border-white/10 bg-gray-950/50 p-4 space-y-4">
                    @csrf
                    <label class="block cursor-pointer">
                        <input type="file" name="photo" accept="image/*" required class="sr-only"
                               @change="const f = $event.target.files[0]; if (f) { if (preview) URL.revokeObjectURL(preview); name = f.name; preview = URL.createObjectURL(f); }">
                        <div class="rounded-2xl border-2 border-dashed border-indigo-500/40 bg-indigo-500/[0.06] active:bg-indigo-500/10 transition text-center" :class="preview ? 'p-3' : 'p-8'">
                            <template x-if="!preview">
                                <div>
                                    <div class="mx-auto h-14 w-14 rounded-2xl bg-indigo-500/15 grid place-items-center mb-3">
                                        <svg class="h-7 w-7 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                                    </div>
                                    <p class="font-display font-bold text-gray-100">Tap to add your photo</p>
                                    <p class="text-xs text-gray-500 mt-1">A full-body shot from your library or camera</p>
                                </div>
                            </template>
                            <template x-if="preview">
                                <div class="flex items-center gap-3 text-left">
                                    <img :src="preview" class="h-24 w-[4.5rem] object-cover rounded-lg border border-white/10 shrink-0">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-100 truncate" x-text="name"></p>
                                        <p class="text-xs text-indigo-300 mt-0.5">Looks good — tap to change</p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </label>
                    <div>
                        <p class="text-[10px] uppercase tracking-wide text-gray-600 mb-1.5">Optional</p>
                        <div class="grid grid-cols-3 gap-2.5">
                            <input type="date" name="taken_at" value="{{ now()->toDateString() }}" class="h-11 rounded-xl border border-white/10 bg-gray-950 px-2.5 text-sm text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                            <select name="pose" class="h-11 rounded-xl border border-white/10 bg-gray-950 px-2.5 text-sm text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                                <option value="">Pose</option><option value="front">Front</option><option value="side">Side</option><option value="back">Back</option>
                            </select>
                            <input type="number" step="0.1" name="weight_kg" placeholder="kg" class="h-11 rounded-xl border border-white/10 bg-gray-950 px-2.5 text-sm text-gray-100 placeholder:text-gray-600 focus:border-indigo-500/50 focus:outline-none">
                        </div>
                    </div>
                    <button type="submit" class="w-full h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 text-sm font-semibold text-gray-950 active:brightness-110">Save photo</button>
                </form>
            </div>

            @if ($photos->isEmpty())
                <div class="text-center py-10 text-gray-600">
                    <svg class="h-9 w-9 mx-auto mb-3 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p class="text-sm">No photos yet. Tap <span class="text-indigo-300">Add</span> to log your first.</p>
                </div>
            @else
                <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2.5">
                    @foreach ($photos as $ph)
                        <div class="group relative rounded-xl overflow-hidden border transition cursor-pointer aspect-[3/4]"
                             :class="isPicked({{ $ph->id }}) ? 'border-cyan-400/70 ring-2 ring-cyan-500/40' : 'border-white/5 active:border-white/20'"
                             @click="pick({{ $ph->id }})">
                            <img src="{{ $ph->photoUrl() }}" class="h-full w-full object-cover" draggable="false">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-2">
                                <p class="text-[10px] text-white font-medium truncate">{{ $ph->taken_at?->format('M j') }}</p>
                                @if ($ph->weight_kg)<p class="text-[9px] text-gray-300 nums truncate">{{ rtrim(rtrim((string) $ph->weight_kg, '0'), '.') }} kg</p>@endif
                            </div>
                            <div x-show="isPicked({{ $ph->id }})" x-cloak class="absolute top-1.5 left-1.5 h-5 w-5 rounded-full bg-cyan-400 text-gray-950 text-[11px] font-bold grid place-items-center" x-text="pickLabel({{ $ph->id }})"></div>
                            <div class="absolute top-1.5 right-1.5 flex gap-1 opacity-0 group-active:opacity-100 sm:group-hover:opacity-100 transition" @click.stop>
                                <form method="POST" action="{{ route('physique.photo.analyze', $ph) }}" @submit="startWork('analyze')">
                                    @csrf
                                    <button type="submit" title="AI analyze" @disabled(! $aiConfigured) class="rounded-md bg-indigo-500/90 active:bg-indigo-500 text-gray-950 h-7 w-7 grid place-items-center text-[10px] font-bold disabled:opacity-40">AI</button>
                                </form>
                                <form method="POST" action="{{ route('physique.photo.destroy', $ph) }}" onsubmit="return confirm('Remove this photo?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" title="Delete" class="rounded-md bg-black/60 active:bg-rose-600/80 text-gray-200 h-7 w-7 grid place-items-center">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ════════════════ LATEST PHYSIQUE READ ════════════════ --}}
        @if ($latestAnalysis && (is_array($latestAnalysis->muscle_ratings) && count($latestAnalysis->muscle_ratings) || $latestAnalysis->summary))
            <section class="glass-card p-4 md:p-5">
                <h3 class="font-display font-bold text-gray-100 mb-4">Latest physique read</h3>
                @if (is_array($latestAnalysis->muscle_ratings) && count($latestAnalysis->muscle_ratings))
                    <div class="space-y-2.5 min-w-0">
                        @foreach (\App\Models\PhysiqueAnalysis::MUSCLE_GROUPS as $grp)
                            @php $r = $latestAnalysis->muscle_ratings[$grp] ?? null; @endphp
                            @if ($r !== null)
                                <div class="flex items-center gap-3">
                                    <span class="w-16 text-sm text-gray-400 capitalize shrink-0 truncate">{{ $grp }}</span>
                                    <div class="flex-1 min-w-0 h-2 rounded-full bg-gray-800 overflow-hidden"><div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $r * 10 }}%"></div></div>
                                    <span class="w-9 text-right text-sm text-gray-300 nums shrink-0">{{ $r }}/10</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
                @if ($latestAnalysis->summary)<p class="mt-4 text-sm text-gray-400 leading-relaxed border-t border-white/5 pt-4">{{ $latestAnalysis->summary }}</p>@endif
                @if ($latestAnalysis->bodyFatRange())<p class="mt-3 text-[11px] text-gray-600">Body-fat estimate is a range, not a precise figure — honest about the uncertainty. Not a medical measurement.</p>@endif
            </section>
        @endif

        {{-- ════════════════ DREAM-PHYSIQUE GENERATION OVERLAY ════════════════ --}}
        {{-- The "we're sculpting YOUR photo" moment: the source image with a scanning sweep,
             per-angle progress, and rotating copy — so it's unmistakably building. --}}
        <div x-show="buildGen" x-cloak x-transition.opacity.duration.300ms
             class="fixed inset-0 z-[130] flex items-center justify-center bg-gray-950/95 backdrop-blur-xl px-6">
            <div class="flex w-full max-w-xs flex-col items-center text-center">
                {{-- the photo being sculpted --}}
                <div class="relative w-40 sm:w-44 overflow-hidden rounded-2xl border border-cyan-400/40 shadow-2xl shadow-cyan-500/25" style="aspect-ratio:3/4">
                    <img :src="genSrc" alt="" class="absolute inset-0 h-full w-full object-cover">
                    <div class="absolute inset-0 bg-indigo-950/35"></div>
                    {{-- corner brackets --}}
                    <span class="absolute top-2 left-2 h-4 w-4 border-t-2 border-l-2 border-cyan-300/80 rounded-tl"></span>
                    <span class="absolute top-2 right-2 h-4 w-4 border-t-2 border-r-2 border-cyan-300/80 rounded-tr"></span>
                    <span class="absolute bottom-2 left-2 h-4 w-4 border-b-2 border-l-2 border-cyan-300/80 rounded-bl"></span>
                    <span class="absolute bottom-2 right-2 h-4 w-4 border-b-2 border-r-2 border-cyan-300/80 rounded-br"></span>
                    {{-- scanning sweep --}}
                    <div class="absolute inset-x-0 h-2/5" style="top:-40%; background:linear-gradient(to bottom, transparent, rgba(34,211,238,0.30)); animation: titanScanBand 2.2s linear infinite;"></div>
                    <div class="absolute inset-x-0 h-[2px] bg-cyan-300 shadow-[0_0_14px_3px_rgba(34,211,238,0.85)]" style="top:0; animation: titanScanLine 2.2s linear infinite;"></div>
                    {{-- soft pulsing ring --}}
                    <div class="absolute inset-0 rounded-2xl ring-1 ring-cyan-400/30 animate-pulse"></div>
                </div>

                <h3 class="mt-7 font-display text-xl font-bold text-gray-100">Sculpting your dream physique</h3>
                <p class="mt-1.5 text-sm text-cyan-300/90 min-h-[1.25rem]" x-text="genMsg"></p>

                {{-- per-angle progress dots --}}
                <div class="mt-4 flex items-center gap-1.5">
                    <template x-for="i in genTotal" :key="i">
                        <span class="h-1.5 rounded-full transition-all duration-300"
                              :class="i < genStep ? 'w-5 bg-cyan-400' : (i === genStep ? 'w-8 bg-gradient-to-r from-indigo-400 to-cyan-300' : 'w-5 bg-white/15')"></span>
                    </template>
                </div>
                <p class="mt-2 text-[11px] font-medium uppercase tracking-wide text-gray-500">
                    <span x-text="genAngleLabel"></span> · <span x-text="genStep"></span> of <span x-text="genTotal"></span>
                </p>

                <p class="mt-6 text-xs text-gray-500">About 10–20 seconds per angle. Hang tight — don't refresh.</p>
            </div>
        </div>

        {{-- ════════════════ BLOCKING-WORK OVERLAY (living render / compare / analyze) ════════════════ --}}
        <div x-show="working" x-cloak x-transition.opacity.duration.300ms
             class="fixed inset-0 z-[120] flex items-center justify-center bg-gray-950/92 backdrop-blur-xl">
            <div class="relative flex flex-col items-center text-center px-6 max-w-sm">
                <div class="relative h-44 w-44 mb-9">
                    <div class="absolute inset-0 rounded-full bg-gradient-to-tr from-indigo-500/40 to-cyan-400/30 blur-3xl animate-pulse"></div>
                    <div class="absolute inset-0 rounded-full border-2 border-indigo-500/40" style="animation: titanRing 2.4s ease-out infinite;"></div>
                    <div class="absolute inset-0 rounded-full border-2 border-cyan-400/30" style="animation: titanRing 2.4s ease-out infinite; animation-delay: .8s;"></div>
                    <div class="absolute inset-0 rounded-full border border-white/10" style="animation: titanRing 2.4s ease-out infinite; animation-delay: 1.6s;"></div>
                    <svg class="absolute inset-0 m-auto h-20 w-20 text-indigo-100 drop-shadow-[0_0_18px_rgba(129,140,248,0.6)]"
                         style="animation: titanFloat 3s ease-in-out infinite;" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2a2.2 2.2 0 100 4.4A2.2 2.2 0 0012 2zM4.5 8.2c.7-.3 1.5 0 1.9.6l1.3 2c.7 1 1.9 1.6 3.1 1.6h2.4c1.2 0 2.4-.6 3.1-1.6l1.3-2c.4-.6 1.2-.9 1.9-.6.8.3 1.1 1.2.7 1.9l-1.4 2.2c-.7 1-1.7 1.8-2.9 2.2l.5 5.3a1.4 1.4 0 01-2.8.3l-.6-3.9h-1.5l-.6 3.9a1.4 1.4 0 01-2.8-.3l.5-5.3a5.3 5.3 0 01-2.9-2.2L3.8 10c-.4-.7-.1-1.6.7-1.9z"/>
                    </svg>
                </div>
                <h3 class="font-display text-xl font-bold text-gray-100" x-text="workTitle"></h3>
                <p class="mt-2 text-sm text-indigo-300/90 min-h-[1.25rem]" x-text="workMsg"></p>
                <div class="mt-6 h-1.5 w-64 max-w-[70vw] overflow-hidden rounded-full bg-white/10">
                    <div class="h-full w-1/3 rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="animation: titanSlide 1.5s ease-in-out infinite;"></div>
                </div>
                <p class="mt-5 text-xs text-gray-500">This usually takes 10–20 seconds. Hang tight — don't refresh.</p>
            </div>
        </div>
    </div>

    <style>
        @keyframes titanRing { 0% { transform: scale(.55); opacity: .85 } 100% { transform: scale(1.35); opacity: 0 } }
        @keyframes titanFloat { 0%, 100% { transform: translateY(0) } 50% { transform: translateY(-7px) } }
        @keyframes titanSlide { 0% { transform: translateX(-120%) } 100% { transform: translateX(430%) } }
        /* dream-physique generation "scanner" sweep over the source photo */
        @keyframes titanScanLine { 0% { top: 0 } 50% { top: calc(100% - 2px) } 100% { top: 0 } }
        @keyframes titanScanBand { 0% { top: -40% } 50% { top: 100% } 100% { top: -40% } }
    </style>

    <script>
        function physiquePage(cfg) {
            return {
                // ── Dream showcase (multi-angle before/after) ──
                dShots: cfg.dreamShots || [],
                dActive: (cfg.dreamShots && cfg.dreamShots[0]) ? cfg.dreamShots[0].angle : 'front',
                dPos: 50, dDrag: false,
                get dShot() { return this.dShots.find(s => s.angle === this.dActive) || null; },
                hasAngle(a) { return this.dShots.some(s => s.angle === a && s.goal_url); },
                hasAllAngles() { return this.BUILD_ANGLES.every(a => this.hasAngle(a)); },
                dStart(e) { this.dDrag = true; this.dMove(e); },
                dMove(e) { if (!this.dDrag) return; const r = e.currentTarget.getBoundingClientRect(); this.dPos = Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)); },
                dEnd() { this.dDrag = false; },

                // ── Build / refine (front / back / side, AJAX per angle) ──
                BUILD_ANGLES: ['front', 'back', 'side'],
                buildMeta: {
                    front: { label: 'Front', req: true,  hint: 'Your face & overall — the identity anchor' },
                    back:  { label: 'Back',  req: false, hint: 'Where glutes, hamstrings & back show' },
                    side:  { label: 'Side',  req: false, hint: 'Waist, posture & glute profile' },
                },
                build: {
                    front: { file: null, preview: '', source: '', goal: '', status: '' },
                    back:  { file: null, preview: '', source: '', goal: '', status: '' },
                    side:  { file: null, preview: '', source: '', goal: '', status: '' },
                },
                buildOpen: {{ $dreamComplete ? 'false' : 'true' }},
                buildDesc: '',
                buildGen: false,
                buildErr: '',
                goalId: cfg.goalId,
                // Rich generation overlay state — the "we're sculpting your photo" moment.
                genStep: 0, genTotal: 0, genAngleLabel: '', genSrc: '', genMsg: '', _genTimer: null,
                _startGenMsgs(angle) {
                    const back = angle === 'back';
                    const msgs = [
                        'Reading your ' + angle + ' photo…',
                        back ? 'Mapping your frame…' : 'Keeping your face & identity…',
                        'Sculpting your dream physique…',
                        'Matching your lighting and pose…',
                        'Rendering future you…',
                    ];
                    let i = 0; this.genMsg = msgs[0];
                    clearInterval(this._genTimer);
                    this._genTimer = setInterval(() => { i = (i + 1) % msgs.length; this.genMsg = msgs[i]; }, 2200);
                },
                _stopGenMsgs() { clearInterval(this._genTimer); this._genTimer = null; },
                init() {
                    // Seed the build cards from any angles already rendered, so they show as done.
                    for (const s of this.dShots) {
                        if (this.build[s.angle]) {
                            this.build[s.angle].source = s.source_url || '';
                            this.build[s.angle].goal = s.goal_url || '';
                            this.build[s.angle].status = s.goal_url ? 'done' : '';
                        }
                    }
                },
                openBuild() { this.buildOpen = true; this.$nextTick(() => this.$refs.build?.scrollIntoView({ behavior: 'smooth', block: 'start' })); },
                get buildFrontReady() { const f = this.build.front; return f.status === 'done' || !!f.file; },
                get buildPending() { return this.BUILD_ANGLES.some(a => this.build[a].file && this.build[a].status !== 'done'); },
                anyBuilt() { return this.BUILD_ANGLES.some(a => this.build[a].status === 'done'); },
                onBuildPhoto(angle, e) {
                    const f = e.target.files && e.target.files[0];
                    if (!f) return;
                    const s = this.build[angle];
                    if (s.preview) URL.revokeObjectURL(s.preview);
                    s.file = f;
                    s.preview = URL.createObjectURL(f);
                    s.status = '';
                    this.buildErr = '';
                },
                async generateBuild() {
                    if (this.buildGen) return;
                    if (!this.buildFrontReady) { this.buildErr = 'Add your front photo to start.'; return; }
                    const pending = this.BUILD_ANGLES.filter(a => this.build[a].file && this.build[a].status !== 'done');
                    if (!pending.length) return;
                    this.buildGen = true; this.buildErr = '';
                    this.genTotal = pending.length; this.genStep = 0;
                    const token = document.querySelector('meta[name=csrf-token]')?.content || document.querySelector('input[name=_token]')?.value || '';
                    const desc = (this.buildDesc || '').trim().slice(0, 160);
                    try {
                        for (const angle of pending) {
                            const s = this.build[angle];
                            // Drive the overlay: which photo, which angle, progress + rotating copy.
                            this.genStep += 1;
                            this.genAngleLabel = this.buildMeta[angle].label;
                            this.genSrc = s.preview;
                            this._startGenMsgs(angle);
                            s.status = 'generating';
                            const file = await this.compressPhoto(s.file);
                            const fd = new FormData();
                            fd.append('photo', file);
                            fd.append('angle', angle);
                            if (desc) fd.append('description', desc);
                            if (this.goalId) fd.append('goal_id', this.goalId);
                            let data = null;
                            try {
                                const res = await fetch('{{ route('physique.goal.generate') }}', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                                    body: fd,
                                });
                                data = await res.json();
                            } catch (_) { data = null; }
                            if (data && data.ok && data.image_url) {
                                this.goalId = data.goal_id;
                                s.source = s.preview;
                                s.goal = data.image_url;
                                s.status = 'done';
                                this.upsertDream(angle, s.preview, data.image_url);   // live-update the hero
                            } else {
                                s.status = 'error';
                                this.buildErr = (data && data.error) || ('Couldn’t generate the ' + angle + ' shot — try again.');
                                if (angle === 'front') break;
                            }
                        }
                    } finally {
                        this.buildGen = false;
                        this._stopGenMsgs();
                    }
                },
                upsertDream(angle, sourceUrl, goalUrl) {
                    const i = this.dShots.findIndex(s => s.angle === angle);
                    const shot = { angle, source_url: sourceUrl, goal_url: goalUrl };
                    if (i === -1) {
                        const order = this.BUILD_ANGLES;
                        this.dShots.push(shot);
                        this.dShots.sort((a, b) => order.indexOf(a.angle) - order.indexOf(b.angle));
                    } else {
                        this.dShots.splice(i, 1, shot);
                    }
                    this.dActive = angle; this.dPos = 50;
                },
                async compressPhoto(file, maxDim = 1600, quality = 0.82) {
                    if (!file || !file.type || !file.type.startsWith('image/')) return file;
                    try {
                        let src = null, w = 0, h = 0;
                        try { src = await createImageBitmap(file); w = src.width; h = src.height; }
                        catch (_) {
                            const url = URL.createObjectURL(file);
                            try { src = await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = url; }); w = src.naturalWidth; h = src.naturalHeight; }
                            finally { URL.revokeObjectURL(url); }
                        }
                        if (!w || !h) return file;
                        const scale = Math.min(1, maxDim / Math.max(w, h));
                        const cw = Math.round(w * scale), ch = Math.round(h * scale);
                        const canvas = document.createElement('canvas');
                        canvas.width = cw; canvas.height = ch;
                        canvas.getContext('2d').drawImage(src, 0, 0, cw, ch);
                        if (src.close) src.close();
                        const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', quality));
                        if (!blob) return file;
                        if (blob.size >= file.size && /jpe?g/i.test(file.type)) return file;
                        return new File([blob], 'photo.jpg', { type: 'image/jpeg' });
                    } catch (_) { return file; }
                },

                // ── Progress gallery + compare reveal ──
                addOpen: false,
                photos: cfg.photos || [],
                pos: 50, dragging: false,
                beforeId: null, afterId: null,
                get beforeSrc() { const p = this.photos.find(x => x.id === this.beforeId); return p ? p.url : ''; },
                get afterSrc() { const p = this.photos.find(x => x.id === this.afterId); return p ? p.url : ''; },
                get beforeLabel() { const p = this.photos.find(x => x.id === this.beforeId); return p ? p.short : 'Before'; },
                get afterLabel() { const p = this.photos.find(x => x.id === this.afterId); return p ? p.short : 'After'; },
                pStart(e) { this.dragging = true; this.pMove(e); },
                pMove(e) { if (!this.dragging) return; const r = e.currentTarget.getBoundingClientRect(); this.pos = Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)); },
                pEnd() { this.dragging = false; },
                pick(id) {
                    if (this.beforeId === id) { this.beforeId = null; return; }
                    if (this.afterId === id) { this.afterId = null; return; }
                    if (this.beforeId === null) { this.beforeId = id; }
                    else if (this.afterId === null) { this.afterId = id; }
                    else { this.beforeId = id; this.afterId = null; }
                },
                clearPick() { this.beforeId = null; this.afterId = null; },
                isPicked(id) { return this.beforeId === id || this.afterId === id; },
                pickLabel(id) { return this.beforeId === id ? 'A' : (this.afterId === id ? 'B' : ''); },

                // ── Blocking-work overlay (server round-trips that reload the page) ──
                working: false, workTitle: '', workMsg: '', _wt: null,
                startWork(kind) {
                    const sets = {
                        render:  { title: 'Rendering this week\'s step', msgs: ['Reading your latest photo…', 'Nudging you one step closer…', 'Matching your lighting and pose…', 'Rendering the new you…'] },
                        compare: { title: 'Measuring your progress', msgs: ['Comparing you to your goal…', 'Reading body composition…', 'Calculating % to goal…'] },
                        analyze: { title: 'Analyzing your physique', msgs: ['Reading the photo…', 'Rating each muscle group…', 'Estimating body fat…'] },
                    };
                    const s = sets[kind] || sets.render;
                    this.working = true; this.workTitle = s.title; this.workMsg = s.msgs[0];
                    let i = 0;
                    clearInterval(this._wt);
                    this._wt = setInterval(() => { i = (i + 1) % s.msgs.length; this.workMsg = s.msgs[i]; }, 2400);
                },
            };
        }
    </script>
</x-titan-layout>
