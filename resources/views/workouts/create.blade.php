<x-titan-layout title="Log workout" subtitle="Add exercises and sets — we'll suggest your next progression">
    <div class="mb-5">
        <a href="/workouts" class="text-sm text-gray-500 active:text-gray-300">← Back to log</a>
    </div>

    @if ($errors->any())
        <div class="rounded-xl bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-300 mb-5">
            <p class="font-medium mb-1">Please fix the following:</p>
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        // Exercise catalog + per-exercise progression suggestions, handed to Alpine as JSON.
        $exerciseData = $exercises->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'muscle_group' => $e->muscle_group,
            'category' => $e->category,
            'equipment' => $e->equipment,
        ])->values();
    @endphp

    <form method="POST" action="/workouts"
          x-data="workoutForm({
              exercises: {{ Js::from($exerciseData) }},
              suggestions: {{ Js::from($suggestions) }}
          })"
          @submit="prepare">
        @csrf

        {{-- Session meta --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 mb-5 grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4">
            <div class="sm:col-span-1">
                <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Workout name</label>
                {{-- Pre-filled with a sensible default so saving never blocks on a blank name; the user can rename. --}}
                <input type="text" name="name" value="{{ old('name', now()->format('l').' workout') }}" required placeholder="Push Day"
                       class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Performed at</label>
                <input type="datetime-local" name="performed_at" value="{{ old('performed_at', now()->format('Y-m-d\TH:i')) }}"
                       class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Duration (min)</label>
                <input type="number" name="duration_min" value="{{ old('duration_min') }}" min="0" max="1440" placeholder="60" inputmode="numeric"
                       class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
        </div>

        {{-- Exercises --}}
        <div class="space-y-4 mb-5">
            <template x-for="(ex, exIndex) in rows" :key="ex.uid">
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                    <div class="flex items-start gap-3 mb-3">
                        <div class="flex-1 min-w-0">
                            <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Exercise</label>
                            <div class="relative">
                                <input type="text" x-model="ex.search" @input="ex.open = true; ex.invalid = false; ex.exercise_id = null" @focus="ex.open = true"
                                       placeholder="Search the library…"
                                       :name="ex.exercise_id ? null : 'search_' + ex.uid"
                                       class="w-full h-11 rounded-xl bg-gray-950 border px-3 text-base text-gray-100 focus:ring-0"
                                       :class="ex.invalid ? 'border-rose-500/60 focus:border-rose-500' : 'border-white/10 focus:border-indigo-500'">
                                {{-- Searchable dropdown --}}
                                <div x-show="ex.open && filtered(ex.search).length" @click.outside="ex.open = false"
                                     class="absolute z-10 mt-1 w-full max-h-56 overflow-y-auto rounded-xl border border-white/10 bg-gray-950 shadow-xl">
                                    <template x-for="opt in filtered(ex.search)" :key="opt.id">
                                        <button type="button" @click="pick(exIndex, opt)"
                                                class="w-full text-left px-3 py-2.5 text-sm text-gray-200 active:bg-indigo-500/15 hover:bg-indigo-500/15">
                                            <span x-text="opt.name"></span>
                                            <span class="text-xs text-gray-500 capitalize" x-text="' · ' + opt.muscle_group"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <span x-show="ex.exercise_id" class="mt-2 inline-flex items-center gap-1 rounded-md bg-indigo-500/15 px-2 py-1 text-xs text-indigo-300">
                                <span x-text="exerciseName(ex.exercise_id)"></span>
                            </span>
                            <p x-show="ex.invalid" x-cloak class="mt-1.5 text-xs text-rose-300">Tap a result to add this exercise.</p>
                        </div>
                        <button type="button" @click="removeExercise(exIndex)"
                                class="shrink-0 h-10 w-10 grid place-items-center rounded-xl text-gray-500 active:text-red-400 active:bg-white/5 mt-5" title="Remove exercise">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {{-- Progressive-overload suggestion --}}
                    <div x-show="ex.exercise_id && suggestionFor(ex.exercise_id)"
                         class="rounded-xl bg-cyan-500/5 border border-cyan-500/15 px-3 py-2.5 mb-3 text-sm">
                        <template x-if="suggestionFor(ex.exercise_id) && suggestionFor(ex.exercise_id).last">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-gray-400" x-text="suggestionFor(ex.exercise_id).note"></span>
                                <template x-if="suggestionFor(ex.exercise_id).suggestion">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="text-cyan-300 font-medium nums">
                                            Try: <span x-text="suggestionFor(ex.exercise_id).suggestion.reps"></span> ×
                                            <span x-text="suggestionFor(ex.exercise_id).suggestion.weight_kg"></span>{{ $weightUnit }}
                                        </span>
                                        <button type="button" @click="applySuggestion(exIndex)"
                                                class="rounded-md bg-cyan-500/20 active:bg-cyan-500/30 px-2.5 py-1 text-xs font-medium text-cyan-200">Apply</button>
                                    </span>
                                </template>
                            </div>
                        </template>
                        <template x-if="suggestionFor(ex.exercise_id) && !suggestionFor(ex.exercise_id).last">
                            <span class="text-gray-500" x-text="suggestionFor(ex.exercise_id).note"></span>
                        </template>
                    </div>

                    {{-- Sets --}}
                    <div class="space-y-2">
                        <template x-for="(set, setIndex) in ex.sets" :key="set.uid">
                            <div class="rounded-xl bg-gray-950/60 border border-white/5 p-2.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="h-7 w-7 shrink-0 grid place-items-center rounded-lg bg-white/5 text-xs font-semibold text-gray-400 nums" x-text="setIndex + 1"></span>
                                    <label class="flex-1 min-w-[5rem]">
                                        <span class="block text-[10px] uppercase tracking-wide text-gray-500 mb-0.5">Reps</span>
                                        <input type="number" min="0" max="1000" x-model="set.reps" inputmode="numeric"
                                               :name="`exercises[${exIndex}][sets][${setIndex}][reps]`"
                                               class="w-full h-11 rounded-lg bg-gray-950 border border-white/10 px-2.5 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                                    </label>
                                    <label class="flex-1 min-w-[5rem]">
                                        <span class="block text-[10px] uppercase tracking-wide text-gray-500 mb-0.5">Weight ({{ $weightUnit }})</span>
                                        <input type="number" step="0.5" min="0" max="9999" x-model="set.weight_kg" inputmode="decimal"
                                               :name="`exercises[${exIndex}][sets][${setIndex}][weight_kg]`"
                                               class="w-full h-11 rounded-lg bg-gray-950 border border-white/10 px-2.5 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                                    </label>
                                    <label class="w-16 shrink-0">
                                        <span class="block text-[10px] uppercase tracking-wide text-gray-500 mb-0.5">RPE</span>
                                        <input type="number" step="0.5" min="0" max="10" x-model="set.rpe" placeholder="—" inputmode="decimal"
                                               :name="`exercises[${exIndex}][sets][${setIndex}][rpe]`"
                                               class="w-full h-11 rounded-lg bg-gray-950 border border-white/10 px-2 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                                    </label>
                                </div>
                                <div class="flex items-center justify-between mt-2 pl-9">
                                    <label class="inline-flex items-center gap-2 text-xs text-gray-400">
                                        <input type="hidden" :name="`exercises[${exIndex}][sets][${setIndex}][is_warmup]`" :value="set.is_warmup ? 1 : 0">
                                        <input type="checkbox" x-model="set.is_warmup"
                                               class="h-4 w-4 rounded bg-gray-950 border-white/20 text-indigo-500 focus:ring-0">
                                        Warmup set
                                    </label>
                                    <button type="button" @click="removeSet(exIndex, setIndex)"
                                            class="inline-flex items-center gap-1 text-xs text-gray-500 active:text-red-400" title="Remove set">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <input type="hidden" :name="`exercises[${exIndex}][exercise_id]`" :value="ex.exercise_id">

                    <button type="button" @click="addSet(exIndex)"
                            class="mt-3 w-full h-11 inline-flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-white/10 text-sm text-indigo-400 active:text-indigo-300 active:bg-white/5 font-medium transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Add set
                    </button>

                    <div class="mt-3">
                        <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Exercise notes</label>
                        <input type="text" x-model="ex.notes" :name="`exercises[${exIndex}][notes]`" placeholder="Optional"
                               class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                    </div>
                </div>
            </template>
        </div>

        <button type="button" @click="addExercise"
                class="w-full h-12 rounded-xl border border-dashed border-white/10 bg-white/[0.02] text-sm font-medium text-gray-400 active:text-gray-200 active:border-indigo-500/40 transition mb-5">
            + Add exercise
        </button>

        <div class="mb-5">
            <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Session notes</label>
            <textarea name="notes" rows="2" placeholder="How did it feel?"
                      class="w-full rounded-xl bg-gray-950 border border-white/10 px-3 py-2.5 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">{{ old('notes') }}</textarea>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2 sm:gap-3">
            <a href="/workouts" class="text-center text-sm text-gray-400 active:text-gray-200 h-12 grid place-items-center px-4">Cancel</a>
            <button type="submit"
                    class="w-full sm:w-auto h-12 rounded-xl bg-indigo-500 active:bg-indigo-400 text-white text-sm font-semibold px-5 transition">
                Save workout
            </button>
        </div>
    </form>

    <script>
        function workoutForm(config) {
            let counter = 0;
            const uid = () => 'r' + (++counter);
            const blankSet = () => ({ uid: uid(), reps: '', weight_kg: '', rpe: '', is_warmup: false });
            const blankRow = () => ({ uid: uid(), exercise_id: null, search: '', open: false, invalid: false, notes: '', sets: [blankSet()] });

            return {
                exercises: config.exercises,
                suggestions: config.suggestions,
                rows: [blankRow()],

                filtered(search) {
                    const q = (search || '').toLowerCase().trim();
                    const list = q
                        ? this.exercises.filter(e =>
                            e.name.toLowerCase().includes(q) || (e.muscle_group || '').toLowerCase().includes(q))
                        : this.exercises;
                    return list.slice(0, 25);
                },
                exerciseName(id) {
                    const e = this.exercises.find(x => x.id === id);
                    return e ? e.name : '';
                },
                suggestionFor(id) {
                    return id ? (this.suggestions[id] || null) : null;
                },
                pick(exIndex, opt) {
                    const row = this.rows[exIndex];
                    row.exercise_id = opt.id;
                    row.search = opt.name;
                    row.open = false;
                    row.invalid = false;
                },
                // Resolve a typed-but-unpicked row to an exact name match, or the only match.
                resolve(row) {
                    if (row.exercise_id || !row.search) return;
                    const q = row.search.trim().toLowerCase();
                    const matches = this.filtered(row.search);
                    const exact = matches.find(o => o.name.toLowerCase() === q);
                    const hit = exact || (matches.length === 1 ? matches[0] : null);
                    if (hit) { row.exercise_id = hit.id; row.search = hit.name; }
                },
                applySuggestion(exIndex) {
                    const row = this.rows[exIndex];
                    const s = this.suggestionFor(row.exercise_id);
                    if (!s || !s.suggestion) return;
                    const first = row.sets[0];
                    first.reps = s.suggestion.reps;
                    first.weight_kg = s.suggestion.weight_kg;
                },
                addExercise() { this.rows.push(blankRow()); },
                removeExercise(i) {
                    this.rows.splice(i, 1);
                    if (this.rows.length === 0) this.rows.push(blankRow());
                },
                addSet(exIndex) { this.rows[exIndex].sets.push(blankSet()); },
                removeSet(exIndex, setIndex) {
                    const sets = this.rows[exIndex].sets;
                    sets.splice(setIndex, 1);
                    if (sets.length === 0) sets.push(blankSet());
                },
                prepare(e) {
                    // Forgive a typed-but-unpicked exercise: auto-resolve an exact / only match
                    // so the common case just works instead of erroring.
                    this.rows.forEach(r => this.resolve(r));

                    // Anything still unpicked → flag it inline (no jarring browser alert) and stop.
                    const bad = this.rows.find(r => !r.exercise_id);
                    if (bad) {
                        e.preventDefault();
                        this.rows.forEach(r => { r.invalid = ! r.exercise_id; });
                        bad.open = true;
                    }
                },
            };
        }
    </script>
</x-titan-layout>
