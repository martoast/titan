<x-titan-layout title="{{ $workout->name }}" subtitle="{{ $workout->performed_at->format('l, M j Y · g:i A') }}">
    <div class="mb-6">
        <a href="/workouts" class="text-sm text-gray-500 hover:text-gray-300">← Back to log</a>
    </div>

    @php
        $volume = $workout->totalVolume();
    @endphp

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Total volume</p>
            <p class="text-xl font-bold text-cyan-300 mt-1">{{ number_format($volume) }} <span class="text-sm text-gray-500">kg</span></p>
        </div>
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Working sets</p>
            <p class="text-xl font-bold text-gray-100 mt-1">{{ $workout->workingSetCount() }}</p>
        </div>
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Exercises</p>
            <p class="text-xl font-bold text-gray-100 mt-1">{{ $workout->exercises->count() }}</p>
        </div>
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4">
            <p class="text-[11px] uppercase tracking-wide text-gray-500">Duration</p>
            <p class="text-xl font-bold text-gray-100 mt-1">{{ $workout->duration_min ? $workout->duration_min.' min' : '—' }}</p>
        </div>
    </div>

    @if ($workout->notes)
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-4 mb-6">
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Notes</p>
            <p class="text-sm text-gray-300 whitespace-pre-line">{{ $workout->notes }}</p>
        </div>
    @endif

    <div class="space-y-4">
        @foreach ($workout->exercises as $we)
            <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-gray-100">{{ $we->exercise?->name ?? 'Exercise' }}</h3>
                        <p class="text-xs text-gray-500 capitalize">{{ $we->exercise?->muscle_group }} · {{ $we->exercise?->category }}</p>
                    </div>
                </div>

                @if ($we->notes)
                    <p class="text-sm text-gray-400 mb-3">{{ $we->notes }}</p>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-1 pr-4 font-medium">Set</th>
                                <th class="py-1 pr-4 font-medium">Reps</th>
                                <th class="py-1 pr-4 font-medium">Weight</th>
                                <th class="py-1 pr-4 font-medium">RPE</th>
                                <th class="py-1 pr-4 font-medium">Volume</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-200">
                            @foreach ($we->sets as $set)
                                <tr class="border-t border-white/5 {{ $set->is_warmup ? 'text-gray-500' : '' }}">
                                    <td class="py-1.5 pr-4">
                                        {{ $set->set_number }}
                                        @if ($set->is_warmup)
                                            <span class="ml-1 text-[10px] uppercase rounded bg-white/5 px-1 py-0.5 text-gray-500">warmup</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 pr-4">{{ $set->reps }}</td>
                                    <td class="py-1.5 pr-4">{{ rtrim(rtrim(number_format($set->weight_kg, 1), '0'), '.') }} kg</td>
                                    <td class="py-1.5 pr-4">{{ $set->rpe !== null ? rtrim(rtrim(number_format($set->rpe, 1), '0'), '.') : '—' }}</td>
                                    <td class="py-1.5 pr-4">{{ $set->is_warmup ? '—' : number_format($set->volume()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</x-titan-layout>
