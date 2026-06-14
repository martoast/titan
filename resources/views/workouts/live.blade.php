@php
    // Hydrate any in-progress session so a reload continues it.
    $initial = ['workoutId' => null, 'exercises' => []];
    if ($active) {
        $initial['workoutId'] = $active->id;
        foreach ($active->exercises as $we) {
            $initial['exercises'][] = [
                'id' => $we->id,
                'name' => $we->exercise?->name ?? 'Exercise',
                'muscle_group' => $we->exercise?->muscle_group,
                'sets' => $we->sets->map(fn ($s) => [
                    'reps' => $s->reps, 'weight' => (float) $s->weight_kg, 'rpe' => $s->rpe,
                ])->values(),
            ];
        }
    }
@endphp

<x-titan-layout title="Live Session" subtitle="Snap the machine — AI names the exercise, you call the reps">
    <div x-data="liveSession({{ \Illuminate\Support\Js::from($initial) }}, {{ $aiReady ? 'true' : 'false' }})" class="max-w-3xl">

        @unless ($aiReady)
            <div class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300">
                Photo identification needs the AI to be configured. You can still add exercises by typing the name.
            </div>
        @endunless

        {{-- ===== Snap / add an exercise ===== --}}
        <div class="rounded-2xl border border-white/5 bg-gray-900/50 p-5 mb-5">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-gray-100">Add exercise</h3>
                <span class="text-xs text-gray-500" x-show="workoutId" x-cloak>Session live · saving as you go</span>
            </div>

            <div class="mt-4 flex flex-col sm:flex-row gap-3">
                {{-- Camera / photo capture --}}
                <label class="flex-1 cursor-pointer">
                    <input type="file" accept="image/*" capture="environment" class="hidden"
                           x-ref="photo" @change="identify($event)" :disabled="!aiReady || busy">
                    <span class="flex items-center justify-center gap-2 rounded-xl bg-indigo-500 hover:bg-indigo-400 px-4 py-3 text-sm font-semibold text-white transition"
                          :class="(!aiReady || busy) && 'opacity-50 pointer-events-none'">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span x-text="busy ? 'Identifying…' : 'Snap the machine'"></span>
                    </span>
                </label>
                {{-- Type it instead --}}
                <form @submit.prevent="addExercise(manualName)" class="flex-1 flex gap-2">
                    <input type="text" x-model="manualName" placeholder="…or type the exercise"
                           class="flex-1 rounded-xl bg-gray-950 border border-white/10 px-3 py-3 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                    <button type="submit" :disabled="!manualName || busy"
                            class="rounded-xl bg-white/10 hover:bg-white/20 px-4 text-sm font-medium text-gray-100 transition disabled:opacity-40">Add</button>
                </form>
            </div>

            {{-- Identification result --}}
            <template x-if="pending">
                <div class="mt-4 rounded-xl border border-indigo-500/30 bg-indigo-500/5 p-4">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-400">Looks like</span>
                        <span class="font-semibold text-indigo-200" x-text="pending.name || 'not sure'"></span>
                        <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded"
                              :class="{'bg-emerald-500/20 text-emerald-300': pending.confidence==='high','bg-amber-500/20 text-amber-300': pending.confidence==='medium','bg-rose-500/20 text-rose-300': pending.confidence==='low'}"
                              x-text="pending.confidence"></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1" x-text="pending.note"></p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button @click="addExercise(pending.name)" x-show="pending.name"
                                class="rounded-lg bg-indigo-500 hover:bg-indigo-400 px-3 py-1.5 text-sm font-semibold text-white">
                            Add “<span x-text="pending.name"></span>”
                        </button>
                        <template x-for="alt in pending.alternates" :key="alt">
                            <button @click="addExercise(alt)"
                                    class="rounded-lg bg-white/5 hover:bg-white/10 px-3 py-1.5 text-sm text-gray-200" x-text="alt"></button>
                        </template>
                        <button @click="pending=null" class="rounded-lg px-3 py-1.5 text-sm text-gray-500 hover:text-gray-300">Dismiss</button>
                    </div>
                </div>
            </template>
        </div>

        {{-- ===== Running session ===== --}}
        <template x-if="exercises.length === 0">
            <p class="text-center text-gray-600 text-sm py-8">No exercises yet. Snap a machine or type one to begin.</p>
        </template>

        <div class="space-y-4">
            <template x-for="(ex, i) in exercises" :key="ex.id">
                <div class="rounded-2xl border border-white/5 bg-gray-900/50 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="font-semibold text-gray-100" x-text="ex.name"></h4>
                            <span class="text-xs text-gray-500 capitalize" x-text="ex.muscle_group"></span>
                        </div>
                        <span class="text-xs text-gray-500"><span x-text="ex.sets.length"></span> sets</span>
                    </div>

                    {{-- logged sets --}}
                    <div class="mt-3 space-y-1" x-show="ex.sets.length">
                        <template x-for="(s, j) in ex.sets" :key="j">
                            <div class="flex items-center gap-3 text-sm text-gray-300 bg-gray-950/60 rounded-lg px-3 py-1.5">
                                <span class="text-gray-600 w-6" x-text="(j+1)+'.'"></span>
                                <span><span class="font-medium text-gray-100" x-text="s.reps"></span> reps</span>
                                <span><span class="font-medium text-gray-100" x-text="s.weight"></span> kg</span>
                                <span x-show="s.rpe" class="text-gray-500">@ RPE <span x-text="s.rpe"></span></span>
                            </div>
                        </template>
                    </div>

                    {{-- add-set row --}}
                    <form @submit.prevent="addSet(i)" class="mt-3 flex items-end gap-2">
                        <label class="flex-1"><span class="text-[11px] text-gray-500">Reps</span>
                            <input type="number" min="0" x-model.number="draft[ex.id].reps" inputmode="numeric"
                                   class="mt-0.5 w-full rounded-lg bg-gray-950 border border-white/10 px-2 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0"></label>
                        <label class="flex-1"><span class="text-[11px] text-gray-500">Weight (kg)</span>
                            <input type="number" min="0" step="0.5" x-model.number="draft[ex.id].weight" inputmode="decimal"
                                   class="mt-0.5 w-full rounded-lg bg-gray-950 border border-white/10 px-2 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0"></label>
                        <label class="w-20"><span class="text-[11px] text-gray-500">RPE</span>
                            <input type="number" min="0" max="10" step="0.5" x-model.number="draft[ex.id].rpe" inputmode="decimal"
                                   class="mt-0.5 w-full rounded-lg bg-gray-950 border border-white/10 px-2 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0"></label>
                        <button type="submit"
                                class="rounded-lg bg-emerald-500/90 hover:bg-emerald-400 px-4 py-2 text-sm font-semibold text-white transition">Add set</button>
                    </form>
                </div>
            </template>
        </div>

        {{-- ===== Finish ===== --}}
        <form method="POST" action="{{ route('workouts.live.finish') }}" class="mt-6 flex items-center gap-3" x-show="workoutId" x-cloak>
            @csrf
            <input type="hidden" name="workout_id" :value="workoutId">
            <input type="text" name="name" placeholder="Name this session (optional)"
                   class="flex-1 rounded-xl bg-gray-950 border border-white/10 px-3 py-2.5 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
            <button type="submit" class="rounded-xl bg-indigo-500 hover:bg-indigo-400 px-5 py-2.5 text-sm font-semibold text-white transition">Finish session</button>
        </form>

        <p x-show="error" x-cloak class="mt-4 text-sm text-rose-400" x-text="error"></p>
    </div>

    <script>
        function liveSession(initial, aiReady) {
            return {
                aiReady,
                workoutId: initial.workoutId,
                exercises: initial.exercises.map(e => ({ ...e, sets: e.sets || [] })),
                draft: {},
                pending: null,
                manualName: '',
                busy: false,
                error: '',
                csrf: document.querySelector('meta[name=csrf-token]').content,

                init() {
                    // ensure a draft input object exists per exercise
                    this.exercises.forEach(e => this.ensureDraft(e.id));
                },
                ensureDraft(id) {
                    if (!this.draft[id]) this.draft[id] = { reps: null, weight: null, rpe: null };
                },
                async post(url, body, isForm = false) {
                    const opts = { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } };
                    if (isForm) { opts.body = body; }
                    else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
                    const r = await fetch(url, opts);
                    if (!r.ok) throw new Error('Request failed (' + r.status + ')');
                    return r.json();
                },
                async identify(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    this.busy = true; this.error = ''; this.pending = null;
                    try {
                        const fd = new FormData(); fd.append('photo', file);
                        const res = await this.post('{{ route('workouts.live.identify') }}', fd, true);
                        if (res.ok) { this.pending = res.identification; }
                        else { this.error = res.message || 'Could not identify the photo.'; }
                    } catch (e) { this.error = e.message; }
                    finally { this.busy = false; this.$refs.photo.value = ''; }
                },
                async addExercise(name) {
                    if (!name) return;
                    this.busy = true; this.error = '';
                    try {
                        const res = await this.post('{{ route('workouts.live.exercise') }}', {
                            name,
                            muscle_group: this.pending?.muscle_group,
                            category: this.pending?.category,
                            equipment: this.pending?.equipment,
                            workout_id: this.workoutId,
                        });
                        this.workoutId = res.workout_id;
                        const ex = { id: res.workout_exercise_id, name: res.exercise_name, muscle_group: res.muscle_group, sets: [] };
                        this.ensureDraft(ex.id);
                        this.exercises.push(ex);
                        this.pending = null; this.manualName = '';
                    } catch (e) { this.error = e.message; }
                    finally { this.busy = false; }
                },
                async addSet(i) {
                    const ex = this.exercises[i];
                    const d = this.draft[ex.id];
                    if (d.reps == null || d.weight == null) { this.error = 'Enter reps and weight.'; return; }
                    this.error = '';
                    try {
                        await this.post('{{ route('workouts.live.set') }}', {
                            workout_exercise_id: ex.id, reps: d.reps, weight_kg: d.weight, rpe: d.rpe || null,
                        });
                        ex.sets.push({ reps: d.reps, weight: d.weight, rpe: d.rpe || null });
                        d.reps = null; d.rpe = null; // keep weight for the next set
                    } catch (e) { this.error = e.message; }
                },
            };
        }
    </script>
</x-titan-layout>
