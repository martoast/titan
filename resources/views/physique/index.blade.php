<x-titan-layout title="Physique" subtitle="See how far you've come — and who you're becoming.">
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

        // Photos as JS for the reveal slider + gallery (newest first as passed).
        $photoData = $photos->map(fn ($p) => [
            'id' => $p->id, 'url' => $p->photoUrl(),
            'date' => $p->taken_at?->format('M j, Y'), 'short' => $p->taken_at?->format('M j'),
            'pose' => ucfirst($p->pose ?? '—'),
            'weight' => $p->weight_kg ? rtrim(rtrim((string) $p->weight_kg, '0'), '.').' kg' : null,
        ])->values();
        $oldest = $photos->last();   // earliest
        $newest = $photos->first();  // latest
        // Default reveal pair: earliest vs latest progress photo; else goal source vs dream.
        $revBefore = $oldest?->photoUrl() ?? ($goal?->sourceUrl());
        $revAfter  = $newest && $newest->id !== $oldest?->id ? $newest->photoUrl() : ($goal?->goalUrl());
        $revBeforeLabel = $oldest && $newest && $newest->id !== $oldest->id ? ($oldest->taken_at?->format('M j') ?? 'Before') : 'Now';
        $revAfterLabel  = $oldest && $newest && $newest->id !== $oldest->id ? ($newest->taken_at?->format('M j') ?? 'Latest') : 'Dream';
        $hasReveal = $revBefore && $revAfter;
    @endphp

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
            defBefore: @js($revBefore), defAfter: @js($revAfter),
            defBeforeLabel: @js($revBeforeLabel), defAfterLabel: @js($revAfterLabel)
         })">

        {{-- ============ HERO: before / after reveal slider ============ --}}
        @if ($hasReveal)
            <section>
                <div class="relative aspect-[4/5] sm:aspect-[16/10] rounded-3xl overflow-hidden border border-white/10 bg-gray-950 select-none touch-none cursor-ew-resize shadow-2xl shadow-black/40"
                     @pointerdown="startDrag($event)" @pointermove="onDrag($event)" @pointerup="endDrag()" @pointerleave="endDrag()">
                    {{-- after (full) --}}
                    <img :src="afterSrc" class="absolute inset-0 h-full w-full object-cover" draggable="false">
                    {{-- before (clipped to pos%) --}}
                    <div class="absolute inset-0 overflow-hidden" :style="`clip-path: inset(0 ${100 - pos}% 0 0)`">
                        <img :src="beforeSrc" class="absolute inset-0 h-full w-full object-cover" draggable="false">
                    </div>
                    {{-- top gradient + labels --}}
                    <div class="absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-black/60 to-transparent pointer-events-none"></div>
                    <div class="absolute top-4 left-4 z-10">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-white/60">Your transformation</p>
                        <span class="rounded-md bg-black/50 backdrop-blur px-2 py-0.5 text-[11px] font-semibold text-white" x-text="beforeLabel"></span>
                    </div>
                    <div class="absolute top-4 right-4 z-10 text-right">
                        @if ($pct !== null)
                            <p class="font-display text-2xl font-black nums bg-gradient-to-r from-indigo-300 to-cyan-200 bg-clip-text text-transparent leading-none">{{ $pct }}%</p>
                            <p class="text-[9px] uppercase tracking-wide text-white/50">to goal</p>
                        @endif
                        <span class="mt-1 inline-block rounded-md bg-indigo-500/80 backdrop-blur px-2 py-0.5 text-[11px] font-semibold text-gray-950" x-text="afterLabel"></span>
                    </div>
                    {{-- drag handle --}}
                    <div class="absolute top-0 bottom-0 z-10 w-[2px] bg-white/90 shadow-[0_0_12px_rgba(255,255,255,0.5)]" :style="`left:${pos}%`">
                        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-11 w-11 rounded-full bg-white/95 backdrop-blur grid place-items-center shadow-lg ring-1 ring-black/10">
                            <svg class="h-5 w-5 text-gray-800" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7l-4 5 4 5m8-10l4 5-4 5"/></svg>
                        </div>
                    </div>
                    <p class="absolute bottom-3 left-1/2 -translate-x-1/2 z-10 text-[10px] text-white/50 pointer-events-none">drag to reveal your progress</p>
                </div>
            </section>
        @else
            {{-- Aspirational empty hero --}}
            <section class="relative aspect-[4/5] sm:aspect-[16/9] rounded-3xl overflow-hidden border border-white/10 bg-gradient-to-br from-indigo-950/50 via-gray-900/60 to-cyan-950/40 grid place-items-center text-center p-8">
                <div class="absolute inset-0 bg-[radial-gradient(60%_60%_at_50%_30%,rgba(99,102,241,0.18),transparent)]"></div>
                <div class="relative max-w-sm">
                    <svg class="h-10 w-10 mx-auto mb-4 text-indigo-300/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <h2 class="font-display text-2xl font-bold text-gray-100">Start your transformation</h2>
                    <p class="mt-2 text-sm text-gray-400">Log your first progress photo and generate your dream physique. Then watch yourself close the gap — week by week.</p>
                    <button @click="addOpen = true; setTimeout(() => $refs.addForm?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 260)"
                            class="mt-5 inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 h-11 px-5 text-sm font-semibold text-gray-950 active:brightness-110">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Add your first photo
                    </button>
                </div>
            </section>
        @endif

        {{-- stat strip --}}
        @if ($pct !== null || $hasReveal || ($latestAnalysis && $latestAnalysis->bodyFatRange()))
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-3.5 text-center">
                    <div class="font-display text-2xl font-black nums bg-gradient-to-r from-indigo-400 to-cyan-300 bg-clip-text text-transparent leading-none">{{ $pct !== null ? $pct.'%' : '—' }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">To goal</div>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-3.5 text-center">
                    <div class="font-display text-2xl font-black nums text-gray-100 leading-none">{{ $photos->count() }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">Photos</div>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-3.5 text-center">
                    <div class="font-display text-2xl font-black nums text-gray-100 leading-none">{{ $latestAnalysis && $latestAnalysis->bodyFatRange() ? $latestAnalysis->bodyFatRange() : $adherencePct.'%' }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">{{ $latestAnalysis && $latestAnalysis->bodyFatRange() ? 'Body fat' : 'Consistency' }}</div>
                </div>
            </div>
        @endif

        {{-- ============ PROGRESS GALLERY (the centerpiece) ============ --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h3 class="font-display text-lg font-bold text-gray-100">Progress gallery</h3>
                    <p class="text-xs text-gray-500 mt-0.5">@if ($photos->count() >= 2) Tap two to load them into the reveal above. @else Your journey, one photo at a time. @endif</p>
                </div>
                <button @click="addOpen = !addOpen" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-indigo-500 h-10 px-3.5 text-sm font-semibold text-white active:bg-indigo-400 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add
                </button>
            </div>

            {{-- collapsible upload form --}}
            <div x-show="addOpen" x-collapse x-cloak x-ref="addForm" class="mb-5 scroll-mt-20">
                <form method="POST" action="{{ route('physique.photo.store') }}" enctype="multipart/form-data"
                      x-data="{ name: '', preview: '' }" class="rounded-2xl border border-white/10 bg-gray-950/50 p-4 space-y-4">
                    @csrf
                    {{-- Big, obvious tap-to-upload zone with live preview --}}
                    <label class="block cursor-pointer">
                        <input type="file" name="photo" accept="image/*" required class="sr-only"
                               @change="const f = $event.target.files[0]; if (f) { if (preview) URL.revokeObjectURL(preview); name = f.name; preview = URL.createObjectURL(f); }">
                        <div class="rounded-2xl border-2 border-dashed border-indigo-500/40 bg-indigo-500/[0.06] active:bg-indigo-500/10 transition text-center"
                             :class="preview ? 'p-3' : 'p-8'">
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
                    {{-- Optional details (clearly secondary) --}}
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
                            {{-- pick badge --}}
                            <div x-show="isPicked({{ $ph->id }})" x-cloak class="absolute top-1.5 left-1.5 h-5 w-5 rounded-full bg-cyan-400 text-gray-950 text-[11px] font-bold grid place-items-center" x-text="pickLabel({{ $ph->id }})"></div>
                            {{-- actions --}}
                            <div class="absolute top-1.5 right-1.5 flex gap-1 opacity-0 group-active:opacity-100 sm:group-hover:opacity-100 transition" @click.stop>
                                <form method="POST" action="{{ route('physique.photo.analyze', $ph) }}">
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

        {{-- ============ LIVING DREAM PHYSIQUE ============ --}}
        <section class="relative overflow-hidden rounded-2xl border border-cyan-500/15 bg-gradient-to-br from-cyan-950/25 via-gray-900/50 to-indigo-950/25 p-4 md:p-5">
            <div class="absolute inset-0 bg-[radial-gradient(70%_60%_at_100%_0%,rgba(34,211,238,0.10),transparent)] pointer-events-none"></div>
            <div class="relative">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 shrink-0 text-cyan-300/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <h3 class="font-display text-lg font-bold text-gray-100">Your living dream physique</h3>
                </div>
                <p class="text-sm text-gray-400 mt-1">The you in the picture advances as you stay consistent.</p>

                @if (! $hasLivingFoundation)
                    @if ($photos->isNotEmpty() && (! $goal || ! $goal->goalUrl()))
                        {{-- On-rails onboarding: they already added a photo — generate the dream
                             physique FROM IT in one tap, no second upload. --}}
                        <div class="mt-4 rounded-xl border border-cyan-500/25 bg-cyan-500/[0.07] p-4">
                            <p class="text-sm font-semibold text-gray-100">You've got your starting photo. Now meet your dream physique.</p>
                            <p class="text-xs text-gray-400 mt-1">We'll render the same you — same face, just ~10 lbs more lean muscle — from the photo you just added.</p>
                            <form method="POST" action="{{ route('physique.goal.generate') }}" class="mt-3" @submit="startGenerating()">
                                @csrf
                                <input type="hidden" name="source_photo_id" value="{{ $newest->id }}">
                                <button type="submit" @disabled(! $imageGenConfigured)
                                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 h-12 px-5 text-sm font-semibold text-gray-950 active:brightness-110 transition disabled:opacity-50">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 3l1.5 4.5L11 9l-4.5 1.5L5 15l-1.5-4.5L-1 9m0 0M19 11l1 3 3 1-3 1-1 3-1-3-3-1 3-1z"/></svg>
                                    Generate my dream physique
                                </button>
                            </form>
                            @unless ($imageGenConfigured)<p class="mt-2 text-xs text-amber-400/80">Image generation is offline — add a GEMINI_API_KEY.</p>@endunless
                        </div>
                    @else
                        <div class="mt-4 rounded-xl border border-white/5 bg-gray-950/40 p-4 text-sm text-gray-400">
                            Add a progress photo above, then generate your dream physique — and we'll render the same you, one believable step closer each week.
                        </div>
                    @endif
                @else
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-[9rem_1fr] gap-4 items-start">
                        @if ($livingImageUrl)
                            <figure class="rounded-xl overflow-hidden border border-cyan-500/25 bg-gray-950">
                                <img src="{{ $livingImageUrl }}" alt="This week's step" class="w-full aspect-[3/4] object-cover">
                                <figcaption class="px-2.5 py-1.5 text-[10px] text-cyan-300/80 bg-cyan-500/5 truncate">This week's you{{ $latestRender ? ' · '.$latestRender->step_pct.'% there' : '' }}</figcaption>
                            </figure>
                        @else
                            <div class="rounded-xl border border-dashed border-white/10 bg-gray-950/40 grid place-items-center aspect-[3/4] p-3 text-center text-xs text-gray-500">Render this week's step →</div>
                        @endif
                        <div class="min-w-0">
                            @if ($pct !== null)
                                <div class="h-2.5 w-full rounded-full bg-gray-800 overflow-hidden"><div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $pct }}%"></div></div>
                                <p class="mt-1 text-[11px] text-gray-500 nums">{{ $pct }}% of the way to your dream physique</p>
                            @endif
                            <p class="mt-2 text-sm text-gray-300 leading-relaxed">{{ $latestPctAnalysis?->summary ?? "Recalculate your % to goal to see what's improving and what to focus on next." }}</p>
                        </div>
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
                @endif

                <div class="mt-4 flex flex-col sm:flex-row gap-2.5">
                    <form method="POST" action="{{ route('physique.living') }}" class="flex-1">
                        @csrf
                        <button type="submit" @disabled(! $imageGenConfigured || ! $hasLivingFoundation)
                                class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 h-11 px-4 text-sm font-semibold text-gray-950 active:brightness-110 transition disabled:opacity-50">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Render this week's step
                        </button>
                    </form>
                    @if ($goal)
                        <form method="POST" action="{{ route('physique.compare') }}" class="flex-1 sm:flex-initial">
                            @csrf
                            <button type="submit" @disabled(! $aiConfigured || $photos->isEmpty())
                                    class="w-full h-11 rounded-xl border border-white/10 px-4 text-sm font-medium text-gray-200 active:bg-white/10 transition disabled:opacity-50">Recalculate % to goal</button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        {{-- ============ DREAM PHYSIQUE GENERATION (secondary, collapsible) ============ --}}
        @php
            // Heading/intent shifts: brand-new user = "Create"; has a photo but no goal =
            // a secondary "use a different photo" path (the on-rails CTA above is primary);
            // has a goal = "Regenerate".
            $genHeading = $goal ? 'Regenerate your dream physique'
                : ($photos->isNotEmpty() ? 'Use a different photo instead' : 'Create your dream physique');
        @endphp
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5" x-data="{ genOpen: {{ ($goal || $photos->isNotEmpty()) ? 'false' : 'true' }} }">
            <button @click="genOpen = !genOpen" class="flex w-full items-center justify-between gap-2">
                <h3 class="font-display font-bold text-gray-100">{{ $genHeading }}</h3>
                <svg class="h-4 w-4 text-gray-500 transition" :class="genOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="genOpen" x-collapse>
                <p class="text-sm text-gray-500 mt-1 mb-4">@if ($photos->isNotEmpty() && ! $goal)Rather use a different shot than the one you added? @endif Upload a current full-body photo. The AI renders the same you — same face, lighting and background — with ~10 lbs more lean muscle. Believable, not a fantasy filter.</p>
                <form method="POST" action="{{ route('physique.goal.generate') }}" enctype="multipart/form-data"
                      x-data="{ name: '', preview: '' }" @submit="startGenerating()" class="space-y-3">
                    @csrf
                    <label class="block cursor-pointer">
                        <input type="file" name="photo" accept="image/*" required class="sr-only"
                               @change="const f = $event.target.files[0]; if (f) { if (preview) URL.revokeObjectURL(preview); name = f.name; preview = URL.createObjectURL(f); }">
                        <div class="rounded-2xl border-2 border-dashed border-indigo-500/40 bg-indigo-500/[0.06] active:bg-indigo-500/10 transition text-center"
                             :class="preview ? 'p-3' : 'p-8'">
                            <template x-if="!preview">
                                <div>
                                    <div class="mx-auto h-14 w-14 rounded-2xl bg-indigo-500/15 grid place-items-center mb-3">
                                        <svg class="h-7 w-7 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                                    </div>
                                    <p class="font-display font-bold text-gray-100">Tap to add a full-body photo</p>
                                    <p class="text-xs text-gray-500 mt-1">We'll render the same you, with more muscle</p>
                                </div>
                            </template>
                            <template x-if="preview">
                                <div class="flex items-center gap-3 text-left">
                                    <img :src="preview" class="h-24 w-[4.5rem] object-cover rounded-lg border border-white/10 shrink-0">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-100 truncate" x-text="name"></p>
                                        <p class="text-xs text-indigo-300 mt-0.5">Ready — tap to change</p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </label>
                    <input type="text" name="description" placeholder="+10 lbs lean muscle (optional)" maxlength="120" class="block w-full h-11 rounded-xl border border-white/10 bg-gray-950 px-3 text-base text-gray-100 placeholder:text-gray-600 focus:border-indigo-500/50 focus:outline-none">
                    <button type="submit" @disabled(! $imageGenConfigured) class="w-full h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-500 px-5 text-sm font-semibold text-gray-950 active:brightness-110 transition disabled:opacity-50">Generate dream physique</button>
                </form>
                @if (! $imageGenConfigured)<p class="mt-3 text-xs text-amber-400/80">Image generation is offline — add a GEMINI_API_KEY.</p>@endif

                @if ($profile->physiqueGoals()->count() > 1)
                    <div class="mt-5 pt-5 border-t border-white/5">
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-2">Earlier renders</div>
                        <div class="flex gap-2.5 overflow-x-auto no-scrollbar -mx-1 px-1 pb-1">
                            @foreach ($profile->physiqueGoals()->latest()->get() as $g)
                                @if ($g->goalUrl())
                                    <div class="relative shrink-0">
                                        <img src="{{ $g->goalUrl() }}" class="h-24 w-[4.8rem] object-cover rounded-xl border {{ $g->is_active ? 'border-indigo-400/60 ring-1 ring-indigo-500/30' : 'border-white/5' }}">
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
            </div>
        </section>

        {{-- ============ LATEST ANALYSIS ============ --}}
        @if ($latestAnalysis && (is_array($latestAnalysis->muscle_ratings) && count($latestAnalysis->muscle_ratings) || $latestAnalysis->summary))
            <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
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

        {{-- ============ GENERATING OVERLAY ============ --}}
        {{-- Blocking POST takes 10–20s while Nano Banana renders — show a premium,
             unmistakable "we're working" state so the screen never just freezes. --}}
        <div x-show="generating" x-cloak x-transition.opacity.duration.300ms
             class="fixed inset-0 z-[120] flex items-center justify-center bg-gray-950/92 backdrop-blur-xl">
            <div class="relative flex flex-col items-center text-center px-6 max-w-sm">
                {{-- pulsing aura + expanding rings around a floating figure --}}
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
                <h3 class="font-display text-xl font-bold text-gray-100">Rendering your dream physique</h3>
                <p class="mt-2 text-sm text-indigo-300/90 min-h-[1.25rem]" x-text="genMsg"></p>
                {{-- indeterminate shimmer bar --}}
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
    </style>

    <script>
        function physiquePage(cfg) {
            return {
                addOpen: false,
                photos: cfg.photos || [],
                // generating overlay — rotates status copy while the blocking POST renders
                generating: false,
                genMsg: '',
                genMsgs: [
                    'Reading your current physique…',
                    'Keeping your face & identity…',
                    'Sculpting ~10 lbs of lean muscle…',
                    'Matching your lighting and pose…',
                    'Rendering the new you…',
                ],
                startGenerating() {
                    this.generating = true;
                    let i = 0;
                    this.genMsg = this.genMsgs[0];
                    setInterval(() => { i = (i + 1) % this.genMsgs.length; this.genMsg = this.genMsgs[i]; }, 2600);
                },
                // reveal slider
                pos: 50, dragging: false,
                beforeId: null, afterId: null,
                get beforeSrc() { const p = this.photos.find(x => x.id === this.beforeId); return p ? p.url : cfg.defBefore; },
                get afterSrc() { const p = this.photos.find(x => x.id === this.afterId); return p ? p.url : cfg.defAfter; },
                get beforeLabel() { const p = this.photos.find(x => x.id === this.beforeId); return p ? p.short : cfg.defBeforeLabel; },
                get afterLabel() { const p = this.photos.find(x => x.id === this.afterId); return p ? p.short : cfg.defAfterLabel; },
                startDrag(e) { this.dragging = true; this.onDrag(e); },
                onDrag(e) { if (!this.dragging) return; const r = e.currentTarget.getBoundingClientRect(); this.pos = Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)); },
                endDrag() { this.dragging = false; },
                // gallery pick → feed the reveal (a = before, b = after)
                pick(id) {
                    if (this.beforeId === id) { this.beforeId = null; return; }
                    if (this.afterId === id) { this.afterId = null; return; }
                    if (this.beforeId === null) { this.beforeId = id; }
                    else if (this.afterId === null) { this.afterId = id; window.scrollTo({ top: 0, behavior: 'smooth' }); }
                    else { this.beforeId = id; this.afterId = null; }
                },
                isPicked(id) { return this.beforeId === id || this.afterId === id; },
                pickLabel(id) { return this.beforeId === id ? 'A' : (this.afterId === id ? 'B' : ''); },
            };
        }
    </script>
</x-titan-layout>
