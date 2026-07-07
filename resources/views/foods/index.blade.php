<x-titan-layout title="Your foods" subtitle="What you eat most, tracked from your meals">
    <div class="max-w-2xl">
        @if (count($foods))
            <x-section-header title="Most-eaten foods" :trailing="count($foods).' tracked'" />
            <x-card pad="p-0" class="overflow-hidden">
                @foreach ($foods as $i => $f)
                    <div class="flex items-center gap-3 px-4 py-3 {{ $i > 0 ? 'border-t border-white/5' : '' }}">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-chip bg-titan-cyan/12 font-display text-sm font-bold text-titan-cyan nums">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-display text-sm font-bold text-gray-100">{{ $f['food'] }}</div>
                            <div class="text-xs text-gray-500">
                                ~{{ number_format($f['avg_calories']) }} kcal · {{ $f['avg_protein_g'] }}g protein
                                @if ($f['last_eaten']) · last {{ $f['last_eaten'] }} @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-display text-base font-bold text-gray-200 nums leading-none">{{ $f['count'] }}×</div>
                            <div class="mt-0.5 text-[10px] uppercase tracking-[0.12em] text-gray-600">logged</div>
                        </div>
                    </div>
                @endforeach
            </x-card>
            <p class="mt-3 text-xs text-gray-600">Your coach references this when planning meals or suggesting food — just ask “what do I usually eat?”</p>
        @else
            <x-card pad="p-8" class="text-center">
                <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-chip bg-titan-cyan/12 text-titan-cyan">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18M3 3v18M3 7h18M7 3v4m0 8a3 3 0 106 0 3 3 0 00-6 0z"/></svg>
                </div>
                <p class="font-display text-lg font-bold text-gray-100">No foods tracked yet</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-gray-400 leading-relaxed">Log meals — by text or by snapping a photo in the coach — and your most-eaten foods build up here.</p>
                <a href="/coach" class="mt-4 inline-block rounded-chip bg-titan-indigo px-4 py-2.5 text-sm font-bold text-white active:opacity-90">Open the coach</a>
            </x-card>
        @endif
    </div>
</x-titan-layout>
