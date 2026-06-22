<x-titan-layout title="{{ $workout->name }}" subtitle="{{ $workout->performed_at->format('l, M j Y · g:i A') }}">
    <div class="mb-5">
        <a href="/workouts" class="text-sm text-gray-500 active:text-gray-300">← Back to log</a>
    </div>

    @php
        $volume = $workout->totalVolume();
        // Band-detected sessions arrive with reps from the wrist but no load — prompt for weights.
        $fromBand = str_starts_with((string) $workout->updated_via, 'biosignal');
        $needsWeights = $fromBand && $workout->exercises->flatMap->sets->every(fn ($s) => (float) $s->weight_kg === 0.0);
    @endphp

    @if (session('status'))
        <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 md:gap-4 mb-5">
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Total volume</p>
            <p class="font-display text-xl font-bold text-cyan-300 nums mt-1">{{ number_format(\App\Support\Units::weightOut($volume, $profile, 0)) }} <span class="text-sm font-normal text-gray-500">{{ $weightUnit }}</span></p>
        </div>
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Working sets</p>
            <p class="font-display text-xl font-bold text-gray-100 nums mt-1">{{ $workout->workingSetCount() }}</p>
        </div>
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Exercises</p>
            <p class="font-display text-xl font-bold text-gray-100 nums mt-1">{{ $workout->exercises->count() }}</p>
        </div>
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Duration</p>
            <p class="font-display text-xl font-bold text-gray-100 nums mt-1">{{ $workout->duration_min ? $workout->duration_min.' min' : '—' }}</p>
        </div>
    </div>

    @if ($workout->notes)
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 mb-5">
            <p class="text-[11px] uppercase tracking-wide text-gray-500 mb-1">Notes</p>
            <p class="text-sm text-gray-300 whitespace-pre-line">{{ $workout->notes }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('workouts.sets.update', $workout) }}"
          x-data="{ editing: {{ $needsWeights ? 'true' : 'false' }} }">
        @csrf
        @method('PUT')

        {{-- Header: title + the edit/save controls --}}
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-[11px] uppercase tracking-wider text-gray-500">Exercises</h2>
            <div class="flex items-center gap-2">
                <button type="button" x-show="!editing" @click="editing = true"
                        class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-200 active:bg-white/10">
                    {{ $needsWeights ? 'Add weights' : 'Edit' }}
                </button>
                <button type="submit" x-show="editing" x-cloak
                        class="rounded-lg bg-cyan-500/90 px-3 py-1.5 text-xs font-semibold text-gray-950 active:bg-cyan-400">Save</button>
                <a href="{{ route('workouts.show', $workout) }}" x-show="editing" x-cloak
                   class="rounded-lg border border-white/10 px-3 py-1.5 text-xs font-semibold text-gray-400">Cancel</a>
            </div>
        </div>

        @if ($needsWeights)
            <div class="mb-4 rounded-xl bg-cyan-500/10 border border-cyan-500/20 px-4 py-3 text-sm text-cyan-200">
                Detected from your band — reps counted at the wrist. Add the load you lifted to track volume + progression.
            </div>
        @endif

        <div class="space-y-4">
            @foreach ($workout->exercises as $we)
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                    <div class="mb-3">
                        <h3 class="font-semibold text-gray-100">{{ $we->exercise?->name ?? 'Exercise' }}</h3>
                        <p class="text-xs text-gray-500 capitalize">{{ $we->exercise?->muscle_group }} · {{ $we->exercise?->category }}</p>
                    </div>

                    <div class="hidden sm:grid grid-cols-5 gap-2 text-[11px] uppercase tracking-wide text-gray-500 px-3 mb-1">
                        <span>Set</span><span>Reps</span><span>Weight</span><span>RPE</span><span class="text-right">Volume</span>
                    </div>

                    <div class="space-y-1.5">
                        @foreach ($we->sets as $set)
                            <div class="rounded-xl bg-gray-950/50 border border-white/5 px-3 py-2 {{ $set->is_warmup ? 'opacity-60' : '' }}">
                                <div class="sm:grid sm:grid-cols-5 sm:gap-2 sm:items-center">
                                    <div class="flex items-center justify-between sm:block">
                                        <span class="text-sm text-gray-300 nums">Set {{ $set->set_number }}</span>
                                        @if ($set->is_warmup)
                                            <span class="text-[10px] uppercase rounded bg-white/5 px-1.5 py-0.5 text-gray-500">warmup</span>
                                        @endif
                                    </div>

                                    {{-- Reps --}}
                                    <div class="flex items-center justify-between mt-1.5 sm:mt-0 sm:block">
                                        <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">Reps</span>
                                        <span x-show="!editing" class="text-sm text-gray-200 nums">{{ $set->reps }}</span>
                                        <input x-show="editing" x-cloak type="number" min="0" max="1000"
                                               name="sets[{{ $set->id }}][reps]" value="{{ $set->reps }}"
                                               class="w-16 rounded-lg bg-gray-900 border border-white/10 px-2 py-1 text-sm text-gray-100 nums focus:border-cyan-500/50 focus:outline-none">
                                    </div>

                                    {{-- Weight --}}
                                    <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block">
                                        <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">Weight</span>
                                        <span x-show="!editing" class="text-sm text-gray-200 nums">{{ \App\Support\Units::weight($set->weight_kg, $profile) }}</span>
                                        <div x-show="editing" x-cloak class="flex items-center gap-1">
                                            <input type="number" step="0.5" min="0" max="1000" inputmode="decimal"
                                                   name="sets[{{ $set->id }}][weight_kg]"
                                                   value="{{ (float) $set->weight_kg !== 0.0 ? \App\Support\Units::num(\App\Support\Units::weightOut($set->weight_kg, $profile)) : '' }}"
                                                   placeholder="0" class="w-20 rounded-lg bg-gray-900 border border-white/10 px-2 py-1 text-sm text-gray-100 nums focus:border-cyan-500/50 focus:outline-none">
                                            <span class="text-xs text-gray-500">{{ $weightUnit }}</span>
                                        </div>
                                    </div>

                                    {{-- RPE --}}
                                    <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block">
                                        <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">RPE</span>
                                        <span x-show="!editing" class="text-sm text-gray-200 nums">{{ $set->rpe !== null ? rtrim(rtrim(number_format($set->rpe, 1), '0'), '.') : '—' }}</span>
                                        <input x-show="editing" x-cloak type="number" step="0.5" min="1" max="10"
                                               name="sets[{{ $set->id }}][rpe]" value="{{ $set->rpe !== null ? rtrim(rtrim(number_format($set->rpe, 1), '0'), '.') : '' }}"
                                               placeholder="—" class="w-16 rounded-lg bg-gray-900 border border-white/10 px-2 py-1 text-sm text-gray-100 nums focus:border-cyan-500/50 focus:outline-none">
                                    </div>

                                    {{-- Volume --}}
                                    <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block sm:text-right">
                                        <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">Volume</span>
                                        <span class="text-sm font-medium text-gray-100 nums">{{ $set->is_warmup ? '—' : number_format(\App\Support\Units::weightOut($set->volume(), $profile, 0)) }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </form>
</x-titan-layout>
