@php
    // The live UI works entirely in the user's display units; weights convert to/from metric
    // at the controller boundary (hydration here, store on add-set, snapshot on voice).
    $weightUnit = \App\Support\Units::weightUnit($profile);
    $spokenUnit = $weightUnit === 'lb' ? 'pounds' : 'kilos';   // how the example reads aloud

    // Hydrate any in-progress session so a reload continues it.
    $initial = ['workoutId' => null, 'startedAt' => null, 'exercises' => []];
    if ($active) {
        $initial['workoutId'] = $active->id;
        $initial['startedAt'] = $active->performed_at->toIso8601String();
        foreach ($active->exercises as $we) {
            $initial['exercises'][] = [
                'id' => $we->id,
                'name' => $we->exercise?->name ?? 'Exercise',
                'muscle_group' => $we->exercise?->muscle_group,
                'sets' => $we->sets->map(fn ($s) => [
                    'reps' => $s->reps, 'weight' => \App\Support\Units::weightOut((float) $s->weight_kg, $profile), 'rpe' => $s->rpe,
                ])->values(),
            ];
        }
    }
@endphp

<x-titan-layout title="Live Session" subtitle="Snap the machine — AI names the exercise, you call the reps">
    <div x-data="liveSession({{ \Illuminate\Support\Js::from($initial) }}, {{ $aiReady ? 'true' : 'false' }})" class="max-w-3xl">

        @unless ($aiReady)
            <div class="mb-5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300">
                Photo identification needs the AI to be configured. You can still add exercises by typing the name.
            </div>
        @endunless

        {{-- ===== Say your set (voice logging) ===== --}}
        <div class="rounded-2xl border border-emerald-500/15 bg-emerald-500/[0.04] p-4 md:p-5 mb-5" x-show="voiceSupported" x-cloak>
            <h3 class="font-display font-bold text-gray-100">Say your set</h3>
            <p class="text-xs text-gray-500 mt-0.5">Tap, then say it — e.g. “bench press, 80 {{ $spokenUnit }}, 8 reps”.</p>

            <button type="button" @click="toggleVoice()"
                    class="mt-4 w-full h-20 rounded-2xl flex items-center justify-center gap-3 text-lg font-semibold text-white transition"
                    :class="listening ? 'bg-rose-500/90 animate-pulse' : 'bg-gradient-to-r from-emerald-500 to-cyan-400 active:from-emerald-400 active:to-cyan-300'">
                <svg class="h-8 w-8 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 1.5a3 3 0 00-3 3v6a3 3 0 006 0v-6a3 3 0 00-3-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 10.5a7 7 0 0014 0M12 17.5V21M8.5 21h7"/></svg>
                <span x-text="listening ? 'Listening… tap to stop' : 'Tap & speak'"></span>
            </button>

            <p x-show="heard" x-cloak class="mt-3 text-sm text-gray-400 italic">“<span x-text="heard"></span>”</p>

            <p x-show="spoken" x-cloak class="mt-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-2.5 text-sm text-emerald-200" x-text="spoken"></p>

            <p class="mt-3 text-[11px] text-gray-600 leading-relaxed">
                Say a set — “bench press 80 {{ $spokenUnit }} 8 reps”. Fix it by talking: “change the last set to 10 reps”, “make it 85 {{ $spokenUnit }}”, “delete that set”, “undo”. When you’re done: “finish workout”.
            </p>
        </div>
        <div x-show="!voiceSupported" x-cloak class="mb-5 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm text-gray-400">
            Voice logging needs Chrome or Android. You can still snap a photo or type each set below.
        </div>

        {{-- ===== Live session stats ===== --}}
        <div x-show="workoutId" x-cloak class="mb-5 rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-[11px] uppercase tracking-wider text-gray-500">This session</h3>
                <span class="text-[11px] text-gray-500 nums" x-show="elapsedMin >= 0" x-text="durationLabel"></span>
            </div>
            <div class="grid grid-cols-3 gap-3 text-center">
                <div>
                    <div class="font-display text-2xl font-bold text-cyan-300 nums leading-none" x-text="Math.round(totalVolume).toLocaleString()"></div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">{{ $weightUnit }} volume</div>
                </div>
                <div>
                    <div class="font-display text-2xl font-bold text-gray-100 nums leading-none" x-text="totalSets"></div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">sets · <span x-text="exerciseCount"></span> ex</div>
                </div>
                <div>
                    <div class="font-display text-2xl font-bold text-gray-100 nums leading-none" x-text="totalReps"></div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 mt-1">total reps</div>
                </div>
            </div>
        </div>

        {{-- ===== Snap / add an exercise ===== --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 mb-5">
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-display font-bold text-gray-100">Add exercise</h3>
                <span class="text-[11px] text-emerald-400 shrink-0" x-show="workoutId" x-cloak>● Live · auto-saving</span>
            </div>

            {{-- Camera / photo capture — big primary thumb target --}}
            <label class="mt-4 block cursor-pointer">
                <input type="file" accept="image/*" capture="environment" class="hidden"
                       x-ref="photo" @change="identify($event)" :disabled="!aiReady || busy">
                <span class="flex items-center justify-center gap-2.5 rounded-2xl bg-gradient-to-r from-indigo-500 to-cyan-400 active:from-indigo-400 active:to-cyan-300 px-4 h-16 text-base font-semibold text-white transition"
                      :class="(!aiReady || busy) && 'opacity-50 pointer-events-none'">
                    <svg class="h-7 w-7 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span x-text="busy ? 'Identifying…' : 'Snap the machine'"></span>
                </span>
            </label>

            {{-- Type it instead --}}
            <div class="mt-3 flex items-center gap-3">
                <div class="flex-1 h-px bg-white/5"></div>
                <span class="text-[11px] uppercase tracking-wide text-gray-600">or type it</span>
                <div class="flex-1 h-px bg-white/5"></div>
            </div>
            <form @submit.prevent="addExercise(manualName)" class="mt-3 flex gap-2">
                <input type="text" x-model="manualName" placeholder="Exercise name"
                       class="flex-1 min-w-0 h-12 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                <button type="submit" :disabled="!manualName || busy"
                        class="shrink-0 h-12 rounded-xl bg-white/10 active:bg-white/20 px-5 text-sm font-semibold text-gray-100 transition disabled:opacity-40">Add</button>
            </form>

            {{-- Identification result --}}
            <template x-if="pending">
                <div class="mt-4 rounded-2xl border border-indigo-500/30 bg-indigo-500/5 p-4">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm text-gray-400">Looks like</span>
                        <span class="font-semibold text-indigo-200" x-text="pending.name || 'not sure'"></span>
                        <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded"
                              :class="{'bg-emerald-500/20 text-emerald-300': pending.confidence==='high','bg-amber-500/20 text-amber-300': pending.confidence==='medium','bg-rose-500/20 text-rose-300': pending.confidence==='low'}"
                              x-text="pending.confidence"></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1" x-text="pending.note"></p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button @click="addExercise(pending.name)" x-show="pending.name"
                                class="h-11 rounded-xl bg-indigo-500 active:bg-indigo-400 px-4 text-sm font-semibold text-white">
                            Add “<span x-text="pending.name"></span>”
                        </button>
                        <template x-for="alt in pending.alternates" :key="alt">
                            <button @click="addExercise(alt)"
                                    class="h-11 rounded-xl bg-white/5 active:bg-white/10 px-4 text-sm text-gray-200" x-text="alt"></button>
                        </template>
                        <button @click="pending=null" class="h-11 rounded-xl px-4 text-sm text-gray-500 active:text-gray-300">Dismiss</button>
                    </div>
                </div>
            </template>
        </div>

        {{-- ===== Running session ===== --}}
        <template x-if="exercises.length === 0">
            <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.02] p-8 text-center">
                <p class="text-gray-500 text-sm">No exercises yet.</p>
                <p class="text-gray-600 text-xs mt-1">Snap a machine or type one to begin.</p>
            </div>
        </template>

        <div class="space-y-4">
            <template x-for="(ex, i) in exercises" :key="ex.id">
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h4 class="font-semibold text-gray-100 truncate" x-text="ex.name"></h4>
                            <span class="text-xs text-gray-500 capitalize" x-text="ex.muscle_group"></span>
                        </div>
                        <span class="text-xs text-gray-500 shrink-0 nums"><span x-text="ex.sets.length"></span> sets</span>
                    </div>

                    {{-- logged sets --}}
                    <div class="mt-3 space-y-1.5" x-show="ex.sets.length">
                        <template x-for="(s, j) in ex.sets" :key="j">
                            <div class="flex items-center gap-3 text-sm text-gray-300 bg-gray-950/60 rounded-lg px-3 py-2">
                                <span class="text-gray-600 w-5 shrink-0 nums" x-text="(j+1)+'.'"></span>
                                <span class="nums"><span class="font-semibold text-gray-100" x-text="s.reps"></span> reps</span>
                                <span class="nums"><span class="font-semibold text-gray-100" x-text="s.weight"></span> {{ $weightUnit }}</span>
                                <span x-show="s.rpe" class="text-gray-500 nums ml-auto">RPE <span x-text="s.rpe"></span></span>
                            </div>
                        </template>
                    </div>

                    {{-- add-set form --}}
                    <form @submit.prevent="addSet(i)" class="mt-3">
                        <div class="flex items-end gap-2">
                            <label class="flex-1 min-w-0"><span class="block text-[11px] uppercase tracking-wide text-gray-500 mb-0.5">Reps</span>
                                <input type="number" min="0" x-model.number="draft[ex.id].reps" inputmode="numeric"
                                       class="w-full h-12 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0"></label>
                            <label class="flex-1 min-w-0"><span class="block text-[11px] uppercase tracking-wide text-gray-500 mb-0.5">Weight ({{ $weightUnit }})</span>
                                <input type="number" min="0" step="0.5" x-model.number="draft[ex.id].weight" inputmode="decimal"
                                       class="w-full h-12 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0"></label>
                            <label class="w-16 shrink-0"><span class="block text-[11px] uppercase tracking-wide text-gray-500 mb-0.5">RPE</span>
                                <input type="number" min="0" max="10" step="0.5" x-model.number="draft[ex.id].rpe" inputmode="decimal"
                                       class="w-full h-12 rounded-xl bg-gray-950 border border-white/10 px-2 text-base text-gray-100 focus:border-indigo-500 focus:ring-0"></label>
                        </div>
                        <button type="submit"
                                class="mt-2.5 w-full h-12 rounded-xl bg-emerald-500/90 active:bg-emerald-400 text-base font-semibold text-white transition">Add set</button>
                    </form>
                </div>
            </template>
        </div>

        {{-- ===== Finish ===== --}}
        <form method="POST" action="{{ route('workouts.live.finish') }}" class="mt-6 flex flex-col sm:flex-row sm:items-center gap-3" x-show="workoutId" x-cloak>
            @csrf
            <input type="hidden" name="workout_id" :value="workoutId">
            <input type="text" name="name" placeholder="Name this session (optional)"
                   class="flex-1 min-w-0 h-12 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
            <button type="submit" class="w-full sm:w-auto h-12 rounded-xl bg-indigo-500 active:bg-indigo-400 px-5 text-sm font-semibold text-white transition">Finish session</button>
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
                // Voice logging (Web Speech API — on-device; no audio leaves the phone).
                voiceSupported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
                listening: false,
                heard: '',
                spoken: '',
                _rec: null,
                _final: '',
                // Live stats.
                startedAt: initial.startedAt ? new Date(initial.startedAt) : null,
                elapsedMin: -1,

                init() {
                    // ensure a draft input object exists per exercise
                    this.exercises.forEach(e => this.ensureDraft(e.id));
                    // Tick the session duration once a minute (and immediately).
                    this.tickDuration();
                    setInterval(() => this.tickDuration(), 15000);
                },

                tickDuration() {
                    if (!this.startedAt) { this.elapsedMin = -1; return; }
                    this.elapsedMin = Math.max(0, Math.floor((Date.now() - this.startedAt.getTime()) / 60000));
                },
                get durationLabel() {
                    if (this.elapsedMin < 0) return '';
                    const h = Math.floor(this.elapsedMin / 60), m = this.elapsedMin % 60;
                    return h ? `${h}h ${m}m` : `${m} min`;
                },
                get totalSets() { return this.exercises.reduce((a, e) => a + e.sets.length, 0); },
                get totalReps() { return this.exercises.reduce((a, e) => a + e.sets.reduce((b, s) => b + (s.reps || 0), 0), 0); },
                get totalVolume() { return this.exercises.reduce((a, e) => a + e.sets.reduce((b, s) => b + (s.reps || 0) * (s.weight || 0), 0), 0); },
                get exerciseCount() { return this.exercises.length; },

                toggleVoice() {
                    if (!this.voiceSupported) { this.error = 'Voice not supported on this browser — type the set instead.'; return; }
                    if (this.listening) { try { this._rec && this._rec.stop(); } catch (e) {} return; }
                    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
                    const rec = new SR();
                    rec.lang = 'en-US'; rec.interimResults = true; rec.maxAlternatives = 1; rec.continuous = false;
                    this.heard = ''; this.error = ''; this.lastAdded = null; this._final = '';
                    rec.onresult = (e) => {
                        let txt = '';
                        for (let i = 0; i < e.results.length; i++) txt += e.results[i][0].transcript;
                        this.heard = txt.trim();
                        if (e.results[e.results.length - 1].isFinal) this._final = txt.trim();
                    };
                    rec.onerror = (e) => { this.error = e.error === 'not-allowed' ? 'Mic permission denied.' : ('Mic error: ' + e.error); this.listening = false; };
                    rec.onend = () => {
                        this.listening = false;
                        const t = this._final || this.heard;
                        if (t) { this._final = ''; this.voiceLog(t); }
                    };
                    this._rec = rec; this.listening = true;
                    try { rec.start(); } catch (e) { this.listening = false; this.error = e.message; }
                },

                async voiceLog(transcript) {
                    if (!transcript) return;
                    this.busy = true; this.error = '';
                    try {
                        const res = await this.post('{{ route('workouts.live.voice') }}', { transcript, workout_id: this.workoutId });
                        if (!res.ok) { this.error = res.message || "Didn't catch that."; this.spoken = ''; return; }
                        // "Finish workout" → seal duration and go to the summary, hands-free.
                        if (res.action === 'finish' && res.redirect) { this.spoken = res.spoken || ''; window.location.href = res.redirect; return; }
                        // The server returns the full session — re-render from the truth (handles
                        // add / update / delete uniformly, so stats + cards stay correct).
                        if (res.session) {
                            this.workoutId = res.session.workout_id;
                            if (!this.startedAt) { this.startedAt = new Date(); this.tickDuration(); }
                            this.exercises = res.session.exercises.map(e => ({ ...e, sets: e.sets || [] }));
                            this.exercises.forEach(e => this.ensureDraft(e.id));
                        }
                        this.spoken = res.spoken || '';
                    } catch (e) { this.error = e.message; }
                    finally { this.busy = false; }
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
                        if (!this.startedAt) { this.startedAt = new Date(); this.tickDuration(); }
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
