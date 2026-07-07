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
        <div class="mb-4 rounded-chip bg-titan-mint/10 border border-titan-mint/20 px-4 py-3 text-sm text-titan-mint">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 md:gap-4 mb-5">
        <x-card pad="p-4"><x-metric :value="number_format(\App\Support\Units::weightOut($volume, $profile, 0))" :unit="$weightUnit" label="Total volume" color="cyan" icon="M3 20h18M7 20V10m5 10V4m5 16v-7" /></x-card>
        <x-card pad="p-4"><x-metric :value="$workout->workingSetCount()" label="Working sets" color="cyan" icon="M4 6h16M4 12h16M4 18h16" /></x-card>
        <x-card pad="p-4"><x-metric :value="$workout->exercises->count()" label="Exercises" color="cyan" icon="M6.5 6.5l11 11M5 9l2-2m10 10l2-2M3 11l2 2m14-2l-2 2" /></x-card>
        <x-card pad="p-4"><x-metric :value="$workout->duration_min ? $workout->duration_min : '—'" :unit="$workout->duration_min ? 'min' : null" label="Duration" color="cyan" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></x-card>
    </div>

    @if ($workout->notes)
        <x-card pad="p-4" class="mb-5">
            <p class="text-[11px] uppercase tracking-wide text-gray-500 mb-1">Notes</p>
            <p class="text-sm text-gray-300 whitespace-pre-line">{{ $workout->notes }}</p>
        </x-card>
    @endif

    <form method="POST" action="{{ route('workouts.sets.update', $workout) }}"
          x-data="{ editing: {{ $needsWeights ? 'true' : 'false' }} }">
        @csrf
        @method('PUT')

        {{-- Header: title + the edit/save controls --}}
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-gray-500">Exercises</h2>
            <div class="flex items-center gap-2">
                <button type="button" x-show="!editing" @click="editing = true"
                        class="rounded-chip border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-200 active:bg-white/10">
                    {{ $needsWeights ? 'Add weights' : 'Edit' }}
                </button>
                <button type="submit" x-show="editing" x-cloak
                        class="rounded-chip bg-titan-cyan px-3 py-1.5 text-xs font-semibold text-titan-bg active:opacity-90">Save</button>
                <a href="{{ route('workouts.show', $workout) }}" x-show="editing" x-cloak
                   class="rounded-chip border border-white/10 px-3 py-1.5 text-xs font-semibold text-gray-400">Cancel</a>
            </div>
        </div>

        @if ($needsWeights)
            <div class="mb-4 rounded-chip bg-titan-cyan/10 border border-titan-cyan/20 px-4 py-3 text-sm text-cyan-200">
                Detected from your band — reps counted at the wrist. Add the load you lifted to track volume + progression.
            </div>
        @endif

        <div class="space-y-4">
            @foreach ($workout->exercises as $we)
                <x-card pad="p-4 md:p-5">
                    <div class="mb-3">
                        <h3 class="font-semibold text-gray-100">{{ $we->exercise?->name ?? 'Exercise' }}</h3>
                        <p class="text-xs text-gray-500 capitalize">{{ $we->exercise?->muscle_group }} · {{ $we->exercise?->category }}</p>
                    </div>

                    <div class="hidden sm:grid grid-cols-5 gap-2 text-[11px] uppercase tracking-wide text-gray-500 px-3 mb-1">
                        <span>Set</span><span>Reps</span><span>Weight</span><span>RPE</span><span class="text-right">Volume</span>
                    </div>

                    <div class="space-y-1.5">
                        @foreach ($we->sets as $set)
                            <div class="rounded-chip bg-black/20 border border-white/5 px-3 py-2 {{ $set->is_warmup ? 'opacity-60' : '' }}">
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
                                               class="w-16 rounded-chip bg-titan-bg border border-white/10 px-2 py-1 text-sm text-gray-100 nums focus:border-titan-cyan/50 focus:outline-none">
                                    </div>

                                    {{-- Weight --}}
                                    <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block">
                                        <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">Weight</span>
                                        <span x-show="!editing" class="text-sm text-gray-200 nums">{{ \App\Support\Units::weight($set->weight_kg, $profile) }}</span>
                                        <div x-show="editing" x-cloak class="flex items-center gap-1">
                                            <input type="number" step="0.5" min="0" max="1000" inputmode="decimal"
                                                   name="sets[{{ $set->id }}][weight_kg]"
                                                   value="{{ (float) $set->weight_kg !== 0.0 ? \App\Support\Units::num(\App\Support\Units::weightOut($set->weight_kg, $profile)) : '' }}"
                                                   placeholder="0" class="w-20 rounded-chip bg-titan-bg border border-white/10 px-2 py-1 text-sm text-gray-100 nums focus:border-titan-cyan/50 focus:outline-none">
                                            <span class="text-xs text-gray-500">{{ $weightUnit }}</span>
                                        </div>
                                    </div>

                                    {{-- RPE --}}
                                    <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block">
                                        <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">RPE</span>
                                        <span x-show="!editing" class="text-sm text-gray-200 nums">{{ $set->rpe !== null ? rtrim(rtrim(number_format($set->rpe, 1), '0'), '.') : '—' }}</span>
                                        <input x-show="editing" x-cloak type="number" step="0.5" min="1" max="10"
                                               name="sets[{{ $set->id }}][rpe]" value="{{ $set->rpe !== null ? rtrim(rtrim(number_format($set->rpe, 1), '0'), '.') : '' }}"
                                               placeholder="—" class="w-16 rounded-chip bg-titan-bg border border-white/10 px-2 py-1 text-sm text-gray-100 nums focus:border-titan-cyan/50 focus:outline-none">
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
                </x-card>
            @endforeach
        </div>
    </form>
</x-titan-layout>
