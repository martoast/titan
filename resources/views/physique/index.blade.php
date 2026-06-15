<x-titan-layout title="Physique" subtitle="Your living dream-physique image — it advances as you do.">
    @php
        /** @var \App\Models\PhysiqueGoal|null $goal */
        /** @var \Illuminate\Support\Collection $photos */
        /** @var \App\Models\PhysiqueAnalysis|null $latestAnalysis */
        /** @var \App\Models\LivingGoalRender|null $latestRender */
        /** @var \Illuminate\Support\Collection $livingRenders */
        /** @var float $adherence */
        $latestPctAnalysis = \App\Models\PhysiqueAnalysis::where('profile_id', $profile->id)
            ->whereNotNull('pct_to_goal')->latest()->first();
        $pct = $latestPctAnalysis->pct_to_goal ?? null;
        $adherencePct = (int) round(($adherence ?? 0) * 100);
        $hasLivingFoundation = $goal && $goal->goalUrl() && $photos->isNotEmpty();
    @endphp

    @if (session('error'))
        <div class="mb-4 rounded-xl bg-rose-500/10 border border-rose-500/20 px-4 py-3 text-sm text-rose-300">
            {{ session('error') }}
        </div>
    @endif

    @unless ($imageGenConfigured && $aiConfigured)
        <div class="mb-4 rounded-xl bg-amber-500/10 border border-amber-500/20 px-4 py-3 text-sm text-amber-300">
            AI features are partially offline ({{ $imageGenConfigured ? '' : 'image generation' }}{{ (! $imageGenConfigured && ! $aiConfigured) ? ' and ' : '' }}{{ $aiConfigured ? '' : 'vision analysis' }} unavailable). You can still log progress photos — generation and analysis will work once the keys are configured.
        </div>
    @endunless

    <div class="space-y-4 md:space-y-5">

        {{-- ============ THE HERO: before / after dream physique ============ --}}
        @if ($goal && $goal->goalUrl())
            <section class="relative overflow-hidden rounded-2xl border border-white/5 bg-gradient-to-br from-indigo-950/40 via-gray-900/60 to-cyan-950/30 p-4 md:p-6">
                <div class="absolute inset-0 bg-[radial-gradient(60%_60%_at_50%_0%,rgba(99,102,241,0.12),transparent)] pointer-events-none"></div>
                <div class="relative">
                    <p class="text-[11px] uppercase tracking-wide text-indigo-300/80 font-semibold">Your dream physique</p>
                    <h2 class="font-display text-2xl md:text-3xl font-bold mt-1 leading-tight">This is who you're becoming.</h2>
                    @if ($goal->description)
                        <p class="text-gray-400 mt-1 text-sm">Target: {{ $goal->description }}</p>
                    @endif

                    {{-- Before / after striking pair --}}
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <figure class="relative rounded-2xl overflow-hidden border border-white/5 bg-gray-950">
                            <img src="{{ $goal->sourceUrl() }}" alt="Current" class="w-full aspect-[3/4] object-cover">
                            <figcaption class="absolute top-2.5 left-2.5 rounded-md bg-black/60 backdrop-blur px-2.5 py-1 text-[11px] font-medium text-gray-200">Now</figcaption>
                        </figure>
                        <figure class="relative rounded-2xl overflow-hidden border border-indigo-400/30 bg-gray-950 ring-1 ring-indigo-500/20">
                            <img src="{{ $goal->goalUrl() }}" alt="Dream physique" class="w-full aspect-[3/4] object-cover">
                            <figcaption class="absolute top-2.5 left-2.5 rounded-md bg-indigo-500/80 backdrop-blur px-2.5 py-1 text-[11px] font-semibold text-gray-950">Dream</figcaption>
                        </figure>
                    </div>

                </div>
            </section>
        @endif

        {{-- ============ YOUR LIVING DREAM PHYSIQUE (the founding wedge) ============ --}}
        <section class="relative overflow-hidden rounded-2xl border border-cyan-500/15 bg-gradient-to-br from-cyan-950/30 via-gray-900/50 to-indigo-950/30 p-4 md:p-5">
            <div class="absolute inset-0 bg-[radial-gradient(70%_60%_at_100%_0%,rgba(34,211,238,0.10),transparent)] pointer-events-none"></div>
            <div class="relative">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] uppercase tracking-wide text-cyan-300/80 font-semibold">Your living dream physique</p>
                        <h2 class="font-display text-xl md:text-2xl font-bold mt-0.5 leading-tight">The you in the picture advances as you do.</h2>
                    </div>
                    <svg class="h-5 w-5 shrink-0 text-cyan-300/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>

                @if (! $hasLivingFoundation)
                    {{-- Friendly empty state: needs a goal image + a progress photo to morph. --}}
                    <div class="mt-4 rounded-xl border border-white/5 bg-gray-950/40 p-5 text-center">
                        <svg class="h-9 w-9 mx-auto mb-3 text-cyan-400/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <p class="text-sm text-gray-300 font-medium">
                            @if (! $goal || ! $goal->goalUrl())
                                Generate your dream physique first.
                            @else
                                Add a progress photo to start the loop.
                            @endif
                        </p>
                        <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                            Upload a progress photo and generate your dream physique — then each week we render the same you, one believable step closer, calibrated to how consistent you've been.
                        </p>
                    </div>
                @else
                    {{-- % to goal + adherence-driven progress bar --}}
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-white/5 bg-gray-950/40 p-3">
                            <div class="text-[11px] uppercase tracking-wide text-gray-500">% to goal</div>
                            <div class="font-display text-3xl font-black nums bg-gradient-to-r from-indigo-400 to-cyan-300 bg-clip-text text-transparent leading-none mt-0.5">
                                {{ $pct !== null ? $pct.'%' : '—' }}
                            </div>
                        </div>
                        <div class="rounded-xl border border-white/5 bg-gray-950/40 p-3">
                            <div class="text-[11px] uppercase tracking-wide text-gray-500">2-week consistency</div>
                            <div class="font-display text-3xl font-black nums text-gray-100 leading-none mt-0.5">{{ $adherencePct }}%</div>
                        </div>
                    </div>

                    @if ($pct !== null)
                        <div class="mt-3 h-3 w-full rounded-full bg-gray-800 overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400 transition-all" style="width: {{ $pct }}%"></div>
                        </div>
                    @endif

                    {{-- Current living render + improved/lagging narrative --}}
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-4 items-start">
                        @if ($livingImageUrl)
                            <figure class="rounded-xl overflow-hidden border border-cyan-500/20 bg-gray-950">
                                <img src="{{ $livingImageUrl }}" alt="This week's step" class="w-full aspect-[3/4] object-cover">
                                <figcaption class="px-2.5 py-1.5 text-[10px] text-cyan-300/80 bg-cyan-500/5 truncate">
                                    This week's you{{ $latestRender ? ' · '.$latestRender->step_pct.'% there' : '' }}
                                </figcaption>
                            </figure>
                        @else
                            <div class="rounded-xl border border-dashed border-white/10 bg-gray-950/40 p-4 text-center grid place-items-center aspect-[3/4]">
                                <p class="text-xs text-gray-500">No step rendered yet. Hit the button to render this week's you.</p>
                            </div>
                        @endif

                        <div class="min-w-0">
                            @if ($latestPctAnalysis?->summary)
                                <p class="text-sm text-gray-300 leading-relaxed">{{ $latestPctAnalysis->summary }}</p>
                            @else
                                <p class="text-sm text-gray-500 leading-relaxed">Recalculate your % to goal to see what's improving and what to focus on next.</p>
                            @endif

                            @if ($latestRender)
                                <p class="mt-2 text-[11px] text-gray-500">
                                    Last step calibrated to {{ $latestRender->adherencePct() }}% consistency · {{ $latestRender->created_at?->diffForHumans() }}.
                                </p>
                            @endif
                        </div>
                    </div>

                    {{-- Week-by-week progression strip: the history of rendered steps --}}
                    @if ($livingRenders->isNotEmpty())
                        <div class="mt-5">
                            <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-2">Week-by-week progression</div>
                            <div class="flex items-stretch gap-2.5 overflow-x-auto no-scrollbar -mx-1 px-1 pb-1">
                                @foreach ($livingRenders as $r)
                                    @if ($r->imageUrl())
                                        <figure class="relative shrink-0 w-[5rem]">
                                            <img src="{{ $r->imageUrl() }}"
                                                 class="h-[6.6rem] w-full object-cover rounded-lg border {{ $loop->last ? 'border-cyan-400/50 ring-1 ring-cyan-500/30' : 'border-white/5' }}">
                                            <figcaption class="mt-1 text-center text-[10px] nums text-gray-500">
                                                {{ $r->created_at?->format('M j') }}<br>
                                                <span class="text-cyan-300/80">{{ $r->step_pct }}%</span>
                                            </figcaption>
                                        </figure>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif

                {{-- Render this week's step --}}
                <form method="POST" action="{{ route('physique.living') }}" class="mt-4">
                    @csrf
                    <button type="submit"
                            class="w-full md:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 h-12 px-5 text-sm font-semibold text-gray-950 active:brightness-110 transition disabled:opacity-50"
                            @disabled(! $imageGenConfigured || ! $hasLivingFoundation)>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Render this week's step
                    </button>
                </form>
                @if (! $imageGenConfigured)
                    <p class="mt-2 text-xs text-amber-400/80">Image generation is offline — add a GEMINI_API_KEY to render steps.</p>
                @endif
            </div>
        </section>

        {{-- ============ "ARE YOU ON TRACK?" — refresh the % to goal read ============ --}}
        @if ($goal)
            <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Are you on track?</div>
                <p class="mt-1 text-sm text-gray-400 leading-relaxed">
                    Re-compare your latest photo to your dream physique — updates the % to goal and the improving / focus-next read above.
                </p>
                <form method="POST" action="{{ route('physique.compare') }}" class="mt-4">
                    @csrf
                    <button type="submit"
                            class="w-full md:w-auto h-11 rounded-xl border border-white/10 px-4 text-sm font-medium text-gray-200 active:bg-white/10 transition disabled:opacity-50"
                            @disabled(! $aiConfigured || $photos->isEmpty())>
                        Recalculate % to goal
                    </button>
                </form>
            </section>
        @endif

        {{-- ============ CREATE / REPLACE GOAL ============ --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100">{{ $goal ? 'Regenerate your dream physique' : 'Create your dream physique' }}</h3>
            <p class="text-sm text-gray-500 mt-1 mb-4">
                Upload a current full-body photo. The AI renders the same you — same face, lighting and background — with about 10 lbs more lean muscle. Believable, not a fantasy filter.
            </p>
            <form method="POST" action="{{ route('physique.goal.generate') }}" enctype="multipart/form-data"
                  x-data="{ name: '' }" class="space-y-3">
                @csrf
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Current photo</span>
                    <input type="file" name="photo" accept="image/*" required
                           @change="name = $event.target.files[0]?.name ?? ''"
                           class="mt-1 block w-full text-base text-gray-400 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-500/20 file:px-3 file:py-2.5 file:text-indigo-300 file:text-sm active:file:bg-indigo-500/30">
                </label>
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Goal framing (optional)</span>
                    <input type="text" name="description" placeholder="+10 lbs lean muscle" maxlength="120"
                           class="mt-1 block w-full h-11 rounded-xl border border-white/10 bg-gray-950 px-3 text-base text-gray-100 placeholder:text-gray-600 focus:border-indigo-500/50 focus:outline-none">
                </label>
                <button type="submit"
                        class="w-full md:w-auto h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 px-5 text-sm font-semibold text-gray-950 active:brightness-110 transition disabled:opacity-50"
                        @disabled(! $imageGenConfigured)>
                    Generate dream physique
                </button>
            </form>
            @if (! $imageGenConfigured)
                <p class="mt-3 text-xs text-amber-400/80">Image generation is offline — add a GEMINI_API_KEY to enable this.</p>
            @endif

            @if ($profile->physiqueGoals()->count() > 1)
                <div class="mt-5 pt-5 border-t border-white/5">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-2">Earlier renders</div>
                    <div class="flex gap-3 overflow-x-auto no-scrollbar -mx-1 px-1 pb-1">
                        @foreach ($profile->physiqueGoals()->latest()->get() as $g)
                            @if ($g->goalUrl())
                                <div class="relative shrink-0">
                                    <img src="{{ $g->goalUrl() }}" class="h-28 w-[5.5rem] object-cover rounded-xl border {{ $g->is_active ? 'border-indigo-400/60 ring-1 ring-indigo-500/30' : 'border-white/5' }}">
                                    @unless ($g->is_active)
                                        <form method="POST" action="{{ route('physique.goal.activate', $g) }}" class="absolute inset-x-0 bottom-0">
                                            @csrf
                                            <button class="w-full bg-black/70 text-[10px] text-gray-200 py-1.5 rounded-b-xl active:bg-indigo-600/80">Use this</button>
                                        </form>
                                    @endunless
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        {{-- ============ LATEST ANALYSIS ============ --}}
        @if ($latestAnalysis)
            <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                <h3 class="font-display font-bold text-gray-100 mb-4">Latest physique read</h3>

                @if ($latestAnalysis->bodyFatRange())
                    <div class="mb-5">
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Body fat (estimated range)</div>
                        <div class="font-display text-2xl font-bold nums text-gray-100 mt-0.5">{{ $latestAnalysis->bodyFatRange() }}</div>
                        <p class="text-[11px] text-gray-600 mt-0.5">A range, not a precise figure — that's honest about the uncertainty.</p>
                    </div>
                @endif

                @if (is_array($latestAnalysis->muscle_ratings) && count($latestAnalysis->muscle_ratings))
                    <div class="space-y-2.5 min-w-0">
                        <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-1">Muscle development</div>
                        @foreach (\App\Models\PhysiqueAnalysis::MUSCLE_GROUPS as $grp)
                            @php $r = $latestAnalysis->muscle_ratings[$grp] ?? null; @endphp
                            @if ($r !== null)
                                <div class="flex items-center gap-3">
                                    <span class="w-16 text-sm text-gray-400 capitalize shrink-0 truncate">{{ $grp }}</span>
                                    <div class="flex-1 min-w-0 h-2 rounded-full bg-gray-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $r * 10 }}%"></div>
                                    </div>
                                    <span class="w-9 text-right text-sm text-gray-300 nums shrink-0">{{ $r }}/10</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if ($latestAnalysis->summary)
                    <p class="mt-5 text-sm text-gray-400 leading-relaxed border-t border-white/5 pt-4">{{ $latestAnalysis->summary }}</p>
                @endif
            </section>
        @endif

        {{-- ============ ADD PROGRESS PHOTO ============ --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100 mb-4">Log a progress photo</h3>
            <form method="POST" action="{{ route('physique.photo.store') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <label class="block">
                    <span class="text-[11px] uppercase tracking-wide text-gray-500">Photo</span>
                    <input type="file" name="photo" accept="image/*" required
                           class="mt-1 block w-full text-base text-gray-400 file:mr-3 file:rounded-lg file:border-0 file:bg-white/5 file:px-3 file:py-2.5 file:text-gray-300 file:text-sm active:file:bg-white/10">
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="block">
                        <span class="text-[11px] uppercase tracking-wide text-gray-500">Date</span>
                        <input type="date" name="taken_at" value="{{ now()->toDateString() }}"
                               class="mt-1 block w-full h-11 rounded-xl border border-white/10 bg-gray-950 px-3 text-base text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                    </label>
                    <label class="block">
                        <span class="text-[11px] uppercase tracking-wide text-gray-500">Pose</span>
                        <select name="pose"
                                class="mt-1 block w-full h-11 rounded-xl border border-white/10 bg-gray-950 px-3 text-base text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                            <option value="">—</option>
                            <option value="front">Front</option>
                            <option value="side">Side</option>
                            <option value="back">Back</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-[11px] uppercase tracking-wide text-gray-500">Weight (kg)</span>
                        <input type="number" step="0.1" name="weight_kg" placeholder="optional"
                               class="mt-1 block w-full h-11 rounded-xl border border-white/10 bg-gray-950 px-3 text-base text-gray-100 placeholder:text-gray-600 focus:border-indigo-500/50 focus:outline-none">
                    </label>
                </div>
                <button type="submit" class="w-full md:w-auto h-12 rounded-xl bg-white/10 px-5 text-sm font-semibold text-gray-100 active:bg-white/15 transition">
                    Add photo
                </button>
            </form>
        </section>

        {{-- ============ GALLERY + COMPARE ============ --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5"
                 x-data="{ a: null, b: null, pick(id){ if(this.a===id){this.a=null} else if(this.b===id){this.b=null} else if(!this.a){this.a=id} else if(!this.b){this.b=id} else {this.a=id; this.b=null} } }">
            <div class="mb-4">
                <h3 class="font-display font-bold text-gray-100">Progress gallery</h3>
                <p class="text-xs text-gray-500 mt-0.5">Tap two photos to compare them side by side.</p>
            </div>

            @if ($photos->isEmpty())
                <div class="text-center py-12 text-gray-600">
                    <svg class="h-10 w-10 mx-auto mb-3 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p class="text-sm">No progress photos yet. Add your first one above.</p>
                </div>
            @else
                {{-- Compare panel --}}
                <template x-if="a && b">
                    <div class="mb-5 grid grid-cols-2 gap-3">
                        @foreach ($photos as $ph)
                            <template x-if="a === {{ $ph->id }}">
                                <figure class="rounded-xl overflow-hidden border border-indigo-400/30">
                                    <img src="{{ $ph->photoUrl() }}" class="w-full aspect-[3/4] object-cover">
                                    <figcaption class="px-3 py-2 text-[11px] text-gray-400 bg-gray-950 truncate">{{ $ph->taken_at?->format('M j, Y') }} · {{ ucfirst($ph->pose ?? '—') }}</figcaption>
                                </figure>
                            </template>
                        @endforeach
                        @foreach ($photos as $ph)
                            <template x-if="b === {{ $ph->id }}">
                                <figure class="rounded-xl overflow-hidden border border-cyan-400/30">
                                    <img src="{{ $ph->photoUrl() }}" class="w-full aspect-[3/4] object-cover">
                                    <figcaption class="px-3 py-2 text-[11px] text-gray-400 bg-gray-950 truncate">{{ $ph->taken_at?->format('M j, Y') }} · {{ ucfirst($ph->pose ?? '—') }}</figcaption>
                                </figure>
                            </template>
                        @endforeach
                    </div>
                </template>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    @foreach ($photos as $ph)
                        <div class="relative rounded-xl overflow-hidden border transition cursor-pointer"
                             :class="(a === {{ $ph->id }} || b === {{ $ph->id }}) ? 'border-indigo-400/60 ring-1 ring-indigo-500/40' : 'border-white/5 active:border-white/15'"
                             @click="pick({{ $ph->id }})">
                            <img src="{{ $ph->photoUrl() }}" class="w-full aspect-[3/4] object-cover">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-2">
                                <p class="text-[11px] text-gray-200 font-medium truncate">{{ $ph->taken_at?->format('M j, Y') }}</p>
                                <p class="text-[10px] text-gray-400 truncate">{{ ucfirst($ph->pose ?? '—') }}@if($ph->weight_kg) · {{ rtrim(rtrim((string)$ph->weight_kg,'0'),'.') }} kg @endif</p>
                            </div>
                            {{-- per-photo actions --}}
                            <div class="absolute top-1.5 right-1.5 flex gap-1.5" @click.stop>
                                <form method="POST" action="{{ route('physique.photo.analyze', $ph) }}">
                                    @csrf
                                    <button type="submit" title="AI analyze" @disabled(! $aiConfigured)
                                            class="rounded-lg bg-indigo-500/90 active:bg-indigo-500 text-gray-950 h-8 w-8 grid place-items-center text-xs font-bold disabled:opacity-40">AI</button>
                                </form>
                                <form method="POST" action="{{ route('physique.photo.destroy', $ph) }}" onsubmit="return confirm('Remove this photo?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" title="Delete"
                                            class="rounded-lg bg-black/60 active:bg-rose-600/80 text-gray-200 h-8 w-8 grid place-items-center">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-titan-layout>
