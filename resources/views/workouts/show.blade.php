<x-titan-layout title="{{ $workout->name }}" subtitle="{{ $workout->performed_at->format('l, M j Y · g:i A') }}">
    <div class="mb-5">
        <a href="/workouts" class="text-sm text-gray-500 active:text-gray-300">← Back to log</a>
    </div>

    @php
        $volume = $workout->totalVolume();
    @endphp

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 md:gap-4 mb-5">
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Total volume</p>
            <p class="font-display text-xl font-bold text-cyan-300 nums mt-1">{{ number_format($volume) }} <span class="text-sm font-normal text-gray-500">kg</span></p>
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

    <div class="space-y-4">
        @foreach ($workout->exercises as $we)
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                <div class="mb-3">
                    <h3 class="font-semibold text-gray-100">{{ $we->exercise?->name ?? 'Exercise' }}</h3>
                    <p class="text-xs text-gray-500 capitalize">{{ $we->exercise?->muscle_group }} · {{ $we->exercise?->category }}</p>
                </div>

                @if ($we->notes)
                    <p class="text-sm text-gray-400 mb-3">{{ $we->notes }}</p>
                @endif

                {{-- Column header (hidden on narrow screens — sets stack instead) --}}
                <div class="hidden sm:grid grid-cols-5 gap-2 text-[11px] uppercase tracking-wide text-gray-500 px-3 mb-1">
                    <span>Set</span>
                    <span>Reps</span>
                    <span>Weight</span>
                    <span>RPE</span>
                    <span class="text-right">Volume</span>
                </div>

                <div class="space-y-1.5">
                    @foreach ($we->sets as $set)
                        <div class="rounded-xl bg-gray-950/50 border border-white/5 px-3 py-2 {{ $set->is_warmup ? 'opacity-60' : '' }}">
                            {{-- Mobile: stacked label/value chips. sm+: aligned 5-col row --}}
                            <div class="sm:grid sm:grid-cols-5 sm:gap-2 sm:items-center">
                                <div class="flex items-center justify-between sm:block">
                                    <span class="text-sm text-gray-300 nums">Set {{ $set->set_number }}</span>
                                    @if ($set->is_warmup)
                                        <span class="text-[10px] uppercase rounded bg-white/5 px-1.5 py-0.5 text-gray-500">warmup</span>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between mt-1.5 sm:mt-0 sm:block">
                                    <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">Reps</span>
                                    <span class="text-sm text-gray-200 nums">{{ $set->reps }}</span>
                                </div>
                                <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block">
                                    <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">Weight</span>
                                    <span class="text-sm text-gray-200 nums">{{ rtrim(rtrim(number_format($set->weight_kg, 1), '0'), '.') }} kg</span>
                                </div>
                                <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block">
                                    <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">RPE</span>
                                    <span class="text-sm text-gray-200 nums">{{ $set->rpe !== null ? rtrim(rtrim(number_format($set->rpe, 1), '0'), '.') : '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between mt-1 sm:mt-0 sm:block sm:text-right">
                                    <span class="text-[11px] uppercase tracking-wide text-gray-500 sm:hidden">Volume</span>
                                    <span class="text-sm font-medium text-gray-100 nums">{{ $set->is_warmup ? '—' : number_format($set->volume()) }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-titan-layout>
