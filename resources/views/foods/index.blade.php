<x-titan-layout title="Your foods" subtitle="What you eat most, tracked from your meals">
    <div class="max-w-2xl">
        @if (count($foods))
            <div class="overflow-hidden rounded-2xl border border-white/5 bg-white/[0.03]">
                @foreach ($foods as $i => $f)
                    <div class="flex items-center gap-3 px-4 py-3 {{ $i > 0 ? 'border-t border-white/5' : '' }}">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-500/12 font-display text-sm font-bold text-emerald-300">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-display text-sm font-bold text-gray-100">{{ $f['food'] }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $f['count'] }}× · ~{{ number_format($f['avg_calories']) }} kcal · {{ $f['avg_protein_g'] }}g protein
                                @if ($f['last_eaten']) · last {{ $f['last_eaten'] }} @endif
                            </div>
                        </div>
                        <span class="shrink-0 font-display text-lg font-black text-gray-700">{{ $f['count'] }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-600">Your coach references this when planning meals or suggesting food — just ask “what do I usually eat?”</p>
        @else
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-6 text-center">
                <p class="font-display text-lg font-bold text-gray-100">No foods tracked yet</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-gray-400 leading-relaxed">Log meals — by text or by snapping a photo in the coach — and your most-eaten foods build up here.</p>
                <a href="/coach" class="mt-3 inline-block rounded-xl bg-indigo-500 px-4 py-2.5 text-sm font-bold text-white">Open the coach</a>
            </div>
        @endif
    </div>
</x-titan-layout>
