<x-titan-layout title="Physique" subtitle="Your living dream-physique image — it advances as you do.">
    @php
        /** @var \App\Models\PhysiqueGoal|null $goal */
        /** @var \Illuminate\Support\Collection $photos */
        /** @var \App\Models\PhysiqueAnalysis|null $latestAnalysis */
        $latestPctAnalysis = \App\Models\PhysiqueAnalysis::where('profile_id', $profile->id)
            ->whereNotNull('pct_to_goal')->latest()->first();
        $pct = $latestPctAnalysis->pct_to_goal ?? null;
    @endphp

    @if (session('error'))
        <div class="mb-5 rounded-lg bg-rose-500/10 border border-rose-500/20 px-4 py-3 text-sm text-rose-300">
            {{ session('error') }}
        </div>
    @endif

    @unless ($imageGenConfigured && $aiConfigured)
        <div class="mb-5 rounded-lg bg-amber-500/10 border border-amber-500/20 px-4 py-3 text-sm text-amber-300">
            AI features are partially offline ({{ $imageGenConfigured ? '' : 'image generation' }}{{ (! $imageGenConfigured && ! $aiConfigured) ? ' and ' : '' }}{{ $aiConfigured ? '' : 'vision analysis' }} unavailable). You can still log progress photos — generation and analysis will work once the keys are configured.
        </div>
    @endunless

    <div x-data="{ tab: '{{ $goal ? 'goal' : 'create' }}' }" class="space-y-8">

        {{-- ============ THE HERO: before / after dream physique ============ --}}
        @if ($goal && $goal->goalUrl())
            <section class="relative overflow-hidden rounded-2xl border border-white/5 bg-gradient-to-br from-indigo-950/40 via-gray-900/60 to-cyan-950/30 p-6 sm:p-8">
                <div class="absolute inset-0 bg-[radial-gradient(60%_60%_at_50%_0%,rgba(99,102,241,0.12),transparent)] pointer-events-none"></div>
                <div class="relative">
                    <div class="flex flex-wrap items-end justify-between gap-3 mb-6">
                        <div>
                            <p class="text-xs uppercase tracking-widest text-indigo-300/80 font-semibold">Your dream physique</p>
                            <h2 class="text-2xl sm:text-3xl font-bold mt-1">This is who you're becoming.</h2>
                            @if ($goal->description)
                                <p class="text-gray-400 mt-1 text-sm">Target: {{ $goal->description }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('physique.living') }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-500 px-4 py-2 text-sm font-semibold text-gray-950 hover:brightness-110 transition disabled:opacity-50"
                                    @disabled(! $imageGenConfigured || $photos->isEmpty())>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                One step closer
                            </button>
                        </form>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <figure class="group relative rounded-xl overflow-hidden border border-white/5 bg-gray-950">
                            <img src="{{ $goal->sourceUrl() }}" alt="Current" class="w-full aspect-[3/4] object-cover">
                            <figcaption class="absolute top-3 left-3 rounded-md bg-black/60 backdrop-blur px-2.5 py-1 text-xs font-medium text-gray-200">Now</figcaption>
                        </figure>
                        <figure class="group relative rounded-xl overflow-hidden border border-indigo-400/30 bg-gray-950 ring-1 ring-indigo-500/20">
                            <img src="{{ $goal->goalUrl() }}" alt="Dream physique" class="w-full aspect-[3/4] object-cover">
                            <figcaption class="absolute top-3 left-3 rounded-md bg-indigo-500/80 backdrop-blur px-2.5 py-1 text-xs font-semibold text-gray-950">Dream physique</figcaption>
                        </figure>
                    </div>

                    {{-- Living goal image, if we've rendered one --}}
                    @if ($livingImageUrl)
                        <div class="mt-5 rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-xs uppercase tracking-widest text-cyan-300/80 font-semibold">Living goal image</span>
                                <span class="text-xs text-gray-500">— a step toward the goal, calibrated to your latest photo</span>
                            </div>
                            <figure class="rounded-lg overflow-hidden border border-white/5 max-w-xs">
                                <img src="{{ $livingImageUrl }}" alt="One step closer" class="w-full aspect-[3/4] object-cover">
                            </figure>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- ============ PROGRESS TO GOAL ============ --}}
        @if ($goal)
            <section class="rounded-2xl border border-white/5 bg-gray-900/50 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h3 class="font-semibold text-gray-100">Progress to your dream physique</h3>
                    <form method="POST" action="{{ route('physique.compare') }}">
                        @csrf
                        <button type="submit"
                                class="text-sm rounded-lg border border-white/10 px-3 py-1.5 text-gray-300 hover:bg-white/5 transition disabled:opacity-50"
                                @disabled(! $aiConfigured || $photos->isEmpty())>
                            Recalculate % to goal
                        </button>
                    </form>
                </div>

                @if ($pct !== null)
                    <div class="flex items-end gap-3 mb-2">
                        <span class="text-4xl font-black bg-gradient-to-r from-indigo-400 to-cyan-300 bg-clip-text text-transparent">{{ $pct }}%</span>
                        <span class="text-sm text-gray-500 mb-1.5">of the way there</span>
                    </div>
                    <div class="h-3 w-full rounded-full bg-gray-800 overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400 transition-all" style="width: {{ $pct }}%"></div>
                    </div>
                    @if ($latestPctAnalysis?->summary)
                        <p class="mt-4 text-sm text-gray-400 leading-relaxed">{{ $latestPctAnalysis->summary }}</p>
                    @endif
                @else
                    <p class="text-sm text-gray-500">
                        Add a progress photo, then hit <span class="text-gray-300">Recalculate % to goal</span> to see how far you've come toward your dream physique.
                    </p>
                @endif
            </section>
        @endif

        {{-- ============ CREATE / REPLACE GOAL ============ --}}
        <section class="rounded-2xl border border-white/5 bg-gray-900/50 p-6">
            <h3 class="font-semibold text-gray-100 mb-1">{{ $goal ? 'Regenerate your dream physique' : 'Create your dream physique' }}</h3>
            <p class="text-sm text-gray-500 mb-4">
                Upload a current full-body photo. The AI renders the same you — same face, lighting and background — with about 10 lbs more lean muscle. Believable, not a fantasy filter.
            </p>
            <form method="POST" action="{{ route('physique.goal.generate') }}" enctype="multipart/form-data"
                  x-data="{ name: '' }" class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-3 items-end">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs text-gray-400">Current photo</span>
                        <input type="file" name="photo" accept="image/*" required
                               @change="name = $event.target.files[0]?.name ?? ''"
                               class="mt-1 block w-full text-sm text-gray-400 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-500/20 file:px-3 file:py-2 file:text-indigo-300 file:text-sm hover:file:bg-indigo-500/30">
                    </label>
                    <label class="block">
                        <span class="text-xs text-gray-400">Goal framing (optional)</span>
                        <input type="text" name="description" placeholder="+10 lbs lean muscle" maxlength="120"
                               class="mt-1 block w-full rounded-lg border border-white/10 bg-gray-950 px-3 py-2 text-sm text-gray-100 placeholder:text-gray-600 focus:border-indigo-500/50 focus:outline-none">
                    </label>
                </div>
                <button type="submit"
                        class="rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-500 px-5 py-2.5 text-sm font-semibold text-gray-950 hover:brightness-110 transition disabled:opacity-50 whitespace-nowrap"
                        @disabled(! $imageGenConfigured)>
                    Generate
                </button>
            </form>
            @if (! $imageGenConfigured)
                <p class="mt-3 text-xs text-amber-400/80">Image generation is offline — add a GEMINI_API_KEY to enable this.</p>
            @endif

            @if ($profile->physiqueGoals()->count() > 1)
                <div class="mt-5 pt-5 border-t border-white/5">
                    <p class="text-xs text-gray-500 mb-2">Earlier renders</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($profile->physiqueGoals()->latest()->get() as $g)
                            @if ($g->goalUrl())
                                <div class="relative">
                                    <img src="{{ $g->goalUrl() }}" class="h-24 w-20 object-cover rounded-lg border {{ $g->is_active ? 'border-indigo-400/60 ring-1 ring-indigo-500/30' : 'border-white/5' }}">
                                    @unless ($g->is_active)
                                        <form method="POST" action="{{ route('physique.goal.activate', $g) }}" class="absolute inset-x-0 bottom-0">
                                            @csrf
                                            <button class="w-full bg-black/70 text-[10px] text-gray-200 py-1 rounded-b-lg hover:bg-indigo-600/80">Use this</button>
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
            <section class="rounded-2xl border border-white/5 bg-gray-900/50 p-6">
                <h3 class="font-semibold text-gray-100 mb-4">Latest physique read</h3>
                <div class="grid grid-cols-1 md:grid-cols-[auto_1fr] gap-6">
                    <div class="space-y-4">
                        @if ($latestAnalysis->bodyFatRange())
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Body fat (estimated range)</p>
                                <p class="text-2xl font-bold text-gray-100 mt-0.5">{{ $latestAnalysis->bodyFatRange() }}</p>
                                <p class="text-[11px] text-gray-600">A range, not a precise figure — that's honest about the uncertainty.</p>
                            </div>
                        @endif
                    </div>
                    @if (is_array($latestAnalysis->muscle_ratings) && count($latestAnalysis->muscle_ratings))
                        <div class="space-y-2.5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Muscle development</p>
                            @foreach (\App\Models\PhysiqueAnalysis::MUSCLE_GROUPS as $grp)
                                @php $r = $latestAnalysis->muscle_ratings[$grp] ?? null; @endphp
                                @if ($r !== null)
                                    <div class="flex items-center gap-3">
                                        <span class="w-20 text-sm text-gray-400 capitalize shrink-0">{{ $grp }}</span>
                                        <div class="flex-1 h-2 rounded-full bg-gray-800 overflow-hidden">
                                            <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" style="width: {{ $r * 10 }}%"></div>
                                        </div>
                                        <span class="w-8 text-right text-sm text-gray-300 tabular-nums">{{ $r }}/10</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
                @if ($latestAnalysis->summary)
                    <p class="mt-5 text-sm text-gray-400 leading-relaxed border-t border-white/5 pt-4">{{ $latestAnalysis->summary }}</p>
                @endif
            </section>
        @endif

        {{-- ============ ADD PROGRESS PHOTO ============ --}}
        <section class="rounded-2xl border border-white/5 bg-gray-900/50 p-6">
            <h3 class="font-semibold text-gray-100 mb-4">Log a progress photo</h3>
            <form method="POST" action="{{ route('physique.photo.store') }}" enctype="multipart/form-data"
                  class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                @csrf
                <label class="block">
                    <span class="text-xs text-gray-400">Photo</span>
                    <input type="file" name="photo" accept="image/*" required
                           class="mt-1 block w-full text-sm text-gray-400 file:mr-3 file:rounded-lg file:border-0 file:bg-white/5 file:px-3 file:py-2 file:text-gray-300 file:text-sm hover:file:bg-white/10">
                </label>
                <label class="block">
                    <span class="text-xs text-gray-400">Date</span>
                    <input type="date" name="taken_at" value="{{ now()->toDateString() }}"
                           class="mt-1 block w-full rounded-lg border border-white/10 bg-gray-950 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                </label>
                <label class="block">
                    <span class="text-xs text-gray-400">Pose</span>
                    <select name="pose"
                            class="mt-1 block w-full rounded-lg border border-white/10 bg-gray-950 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                        <option value="">—</option>
                        <option value="front">Front</option>
                        <option value="side">Side</option>
                        <option value="back">Back</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-gray-400">Weight (kg)</span>
                    <input type="number" step="0.1" name="weight_kg" placeholder="optional"
                           class="mt-1 block w-full rounded-lg border border-white/10 bg-gray-950 px-3 py-2 text-sm text-gray-100 placeholder:text-gray-600 focus:border-indigo-500/50 focus:outline-none">
                </label>
                <div class="sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="rounded-lg bg-white/10 px-5 py-2.5 text-sm font-semibold text-gray-100 hover:bg-white/15 transition">
                        Add photo
                    </button>
                </div>
            </form>
        </section>

        {{-- ============ GALLERY + COMPARE ============ --}}
        <section class="rounded-2xl border border-white/5 bg-gray-900/50 p-6"
                 x-data="{ a: null, b: null, pick(id){ if(this.a===id){this.a=null} else if(this.b===id){this.b=null} else if(!this.a){this.a=id} else if(!this.b){this.b=id} else {this.a=id; this.b=null} } }">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h3 class="font-semibold text-gray-100">Progress gallery</h3>
                <p class="text-xs text-gray-500">Tap two photos to compare them side by side.</p>
            </div>

            @if ($photos->isEmpty())
                <div class="text-center py-12 text-gray-600">
                    <svg class="h-10 w-10 mx-auto mb-3 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p class="text-sm">No progress photos yet. Add your first one above.</p>
                </div>
            @else
                {{-- Compare panel --}}
                <template x-if="a && b">
                    <div class="mb-5 grid grid-cols-2 gap-4">
                        @foreach ($photos as $ph)
                            <template x-if="a === {{ $ph->id }}">
                                <figure class="rounded-xl overflow-hidden border border-indigo-400/30">
                                    <img src="{{ $ph->photoUrl() }}" class="w-full aspect-[3/4] object-cover">
                                    <figcaption class="px-3 py-2 text-xs text-gray-400 bg-gray-950">{{ $ph->taken_at?->format('M j, Y') }} · {{ ucfirst($ph->pose ?? '—') }}</figcaption>
                                </figure>
                            </template>
                        @endforeach
                        @foreach ($photos as $ph)
                            <template x-if="b === {{ $ph->id }}">
                                <figure class="rounded-xl overflow-hidden border border-cyan-400/30">
                                    <img src="{{ $ph->photoUrl() }}" class="w-full aspect-[3/4] object-cover">
                                    <figcaption class="px-3 py-2 text-xs text-gray-400 bg-gray-950">{{ $ph->taken_at?->format('M j, Y') }} · {{ ucfirst($ph->pose ?? '—') }}</figcaption>
                                </figure>
                            </template>
                        @endforeach
                    </div>
                </template>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    @foreach ($photos as $ph)
                        <div class="relative group rounded-xl overflow-hidden border transition cursor-pointer"
                             :class="(a === {{ $ph->id }} || b === {{ $ph->id }}) ? 'border-indigo-400/60 ring-1 ring-indigo-500/40' : 'border-white/5 hover:border-white/15'"
                             @click="pick({{ $ph->id }})">
                            <img src="{{ $ph->photoUrl() }}" class="w-full aspect-[3/4] object-cover">
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-2">
                                <p class="text-[11px] text-gray-200 font-medium">{{ $ph->taken_at?->format('M j, Y') }}</p>
                                <p class="text-[10px] text-gray-400">{{ ucfirst($ph->pose ?? '—') }}@if($ph->weight_kg) · {{ rtrim(rtrim((string)$ph->weight_kg,'0'),'.') }} kg @endif</p>
                            </div>
                            {{-- per-photo actions --}}
                            <div class="absolute top-1.5 right-1.5 flex gap-1 opacity-0 group-hover:opacity-100 transition" @click.stop>
                                <form method="POST" action="{{ route('physique.photo.analyze', $ph) }}">
                                    @csrf
                                    <button type="submit" title="AI analyze" @disabled(! $aiConfigured)
                                            class="rounded-md bg-indigo-500/80 hover:bg-indigo-500 text-gray-950 h-7 w-7 grid place-items-center text-xs font-bold disabled:opacity-40">AI</button>
                                </form>
                                <form method="POST" action="{{ route('physique.photo.destroy', $ph) }}" onsubmit="return confirm('Remove this photo?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" title="Delete"
                                            class="rounded-md bg-black/60 hover:bg-rose-600/80 text-gray-200 h-7 w-7 grid place-items-center">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
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
