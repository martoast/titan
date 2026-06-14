<x-titan-layout title="Log workout" subtitle="Add exercises and sets — we'll suggest your next progression">
    <div class="mb-6">
        <a href="/workouts" class="text-sm text-gray-500 hover:text-gray-300">← Back to log</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-300 mb-6">
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
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5 mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="sm:col-span-1">
                <label class="block text-xs uppercase tracking-wide text-gray-500 mb-1">Workout name</label>
                <input type="text" name="name" value="{{ old('name') }}" required placeholder="Push Day"
                       class="w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-gray-500 mb-1">Performed at</label>
                <input type="datetime-local" name="performed_at" value="{{ old('performed_at', now()->format('Y-m-d\TH:i')) }}"
                       class="w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-gray-500 mb-1">Duration (min)</label>
                <input type="number" name="duration_min" value="{{ old('duration_min') }}" min="0" max="1440" placeholder="60"
                       class="w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            </div>
        </div>

        {{-- Exercises --}}
        <div class="space-y-4 mb-6">
            <template x-for="(ex, exIndex) in rows" :key="ex.uid">
                <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div class="flex-1">
                            <label class="block text-xs uppercase tracking-wide text-gray-500 mb-1">Exercise</label>
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="text" x-model="ex.search" @input="ex.open = true" @focus="ex.open = true"
                                       placeholder="Search the library…"
                                       :name="ex.exercise_id ? null : 'search_' + ex.uid"
                                       class="w-64 rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                <span x-show="ex.exercise_id" class="inline-flex items-center gap-1 rounded-md bg-indigo-500/15 px-2 py-1 text-xs text-indigo-300">
                                    <span x-text="exerciseName(ex.exercise_id)"></span>
                                </span>
                            </div>
                            {{-- Searchable dropdown --}}
                            <div x-show="ex.open && filtered(ex.search).length" @click.outside="ex.open = false"
                                 class="relative">
                                <div class="absolute z-10 mt-1 w-72 max-h-56 overflow-y-auto rounded-lg border border-white/10 bg-gray-950 shadow-xl">
                                    <template x-for="opt in filtered(ex.search)" :key="opt.id">
                                        <button type="button" @click="pick(exIndex, opt)"
                                                class="w-full text-left px-3 py-2 text-sm text-gray-200 hover:bg-indigo-500/15">
                                            <span x-text="opt.name"></span>
                                            <span class="text-xs text-gray-500 capitalize" x-text="' · ' + opt.muscle_group"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <button type="button" @click="removeExercise(exIndex)"
                                class="text-gray-600 hover:text-red-400 mt-5" title="Remove exercise">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {{-- Progressive-overload suggestion --}}
                    <div x-show="ex.exercise_id && suggestionFor(ex.exercise_id)"
                         class="rounded-lg bg-cyan-500/5 border border-cyan-500/15 px-3 py-2 mb-3 text-sm">
                        <template x-if="suggestionFor(ex.exercise_id) && suggestionFor(ex.exercise_id).last">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-gray-400" x-text="suggestionFor(ex.exercise_id).note"></span>
                                <template x-if="suggestionFor(ex.exercise_id).suggestion">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="text-cyan-300 font-medium">
                                            Try: <span x-text="suggestionFor(ex.exercise_id).suggestion.reps"></span> ×
                                            <span x-text="suggestionFor(ex.exercise_id).suggestion.weight_kg"></span>kg
                                        </span>
                                        <button type="button" @click="applySuggestion(exIndex)"
                                                class="rounded-md bg-cyan-500/20 hover:bg-cyan-500/30 px-2 py-0.5 text-xs text-cyan-200">Apply</button>
                                    </span>
                                </template>
                            </div>
                        </template>
                        <template x-if="suggestionFor(ex.exercise_id) && !suggestionFor(ex.exercise_id).last">
                            <span class="text-gray-500" x-text="suggestionFor(ex.exercise_id).note"></span>
                        </template>
                    </div>

                    {{-- Sets table --}}
                    <div class="space-y-2">
                        <div class="grid grid-cols-12 gap-2 text-[11px] uppercase tracking-wide text-gray-500 px-1">
                            <div class="col-span-1">#</div>
                            <div class="col-span-3">Reps</div>
                            <div class="col-span-3">Weight (kg)</div>
                            <div class="col-span-2">RPE</div>
                            <div class="col-span-2">Warmup</div>
                            <div class="col-span-1"></div>
                        </div>
                        <template x-for="(set, setIndex) in ex.sets" :key="set.uid">
                            <div class="grid grid-cols-12 gap-2 items-center">
                                <div class="col-span-1 text-sm text-gray-500" x-text="setIndex + 1"></div>
                                <input type="number" min="0" max="1000" x-model="set.reps"
                                       :name="`exercises[${exIndex}][sets][${setIndex}][reps]`"
                                       class="col-span-3 rounded-lg bg-gray-950 border border-white/10 px-2 py-1.5 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                <input type="number" step="0.5" min="0" max="9999" x-model="set.weight_kg"
                                       :name="`exercises[${exIndex}][sets][${setIndex}][weight_kg]`"
                                       class="col-span-3 rounded-lg bg-gray-950 border border-white/10 px-2 py-1.5 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                <input type="number" step="0.5" min="0" max="10" x-model="set.rpe" placeholder="—"
                                       :name="`exercises[${exIndex}][sets][${setIndex}][rpe]`"
                                       class="col-span-2 rounded-lg bg-gray-950 border border-white/10 px-2 py-1.5 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                <div class="col-span-2 flex items-center">
                                    <input type="hidden" :name="`exercises[${exIndex}][sets][${setIndex}][is_warmup]`" :value="set.is_warmup ? 1 : 0">
                                    <input type="checkbox" x-model="set.is_warmup"
                                           class="rounded bg-gray-950 border-white/20 text-indigo-500 focus:ring-0">
                                </div>
                                <button type="button" @click="removeSet(exIndex, setIndex)"
                                        class="col-span-1 text-gray-600 hover:text-red-400" title="Remove set">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
                                </button>
                            </div>
                        </template>
                    </div>

                    <input type="hidden" :name="`exercises[${exIndex}][exercise_id]`" :value="ex.exercise_id">

                    <div class="mt-3">
                        <label class="block text-xs uppercase tracking-wide text-gray-500 mb-1">Exercise notes</label>
                        <input type="text" x-model="ex.notes" :name="`exercises[${exIndex}][notes]`" placeholder="Optional"
                               class="w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                    </div>

                    <button type="button" @click="addSet(exIndex)"
                            class="mt-3 inline-flex items-center gap-1.5 text-sm text-indigo-400 hover:text-indigo-300 font-medium">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Add set
                    </button>
                </div>
            </template>
        </div>

        <button type="button" @click="addExercise"
                class="w-full rounded-xl border border-dashed border-white/10 bg-gray-900/30 py-3 text-sm text-gray-400 hover:text-gray-200 hover:border-indigo-500/40 transition mb-6">
            + Add exercise
        </button>

        <div class="mb-6">
            <label class="block text-xs uppercase tracking-wide text-gray-500 mb-1">Session notes</label>
            <textarea name="notes" rows="2" placeholder="How did it feel?"
                      class="w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">{{ old('notes') }}</textarea>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="/workouts" class="text-sm text-gray-400 hover:text-gray-200 px-4 py-2">Cancel</a>
            <button type="submit"
                    class="rounded-lg bg-indigo-500 hover:bg-indigo-400 text-white text-sm font-semibold px-5 py-2 transition">
                Save workout
            </button>
        </div>
    </form>

    <script>
        function workoutForm(config) {
            let counter = 0;
            const uid = () => 'r' + (++counter);
            const blankSet = () => ({ uid: uid(), reps: '', weight_kg: '', rpe: '', is_warmup: false });
            const blankRow = () => ({ uid: uid(), exercise_id: null, search: '', open: false, notes: '', sets: [blankSet()] });

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
                    // Block submit if any exercise row lacks a picked exercise.
                    const incomplete = this.rows.some(r => !r.exercise_id);
                    if (incomplete) {
                        e.preventDefault();
                        alert('Pick an exercise from the library for each row before saving.');
                    }
                },
            };
        }
    </script>
</x-titan-layout>
