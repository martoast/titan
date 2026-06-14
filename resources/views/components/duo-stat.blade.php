@props([
    'label' => '',
    'a' => null,        // left competitor value (comparison row mode)
    'b' => null,        // right competitor value (comparison row mode)
    'aWin' => false,    // highlight left value as the leader
    'bWin' => false,    // highlight right value as the leader
    'value' => null,    // single-tile fallback mode
])

@if ($value !== null)
    {{-- Single stat tile (label above value, stacked) --}}
    <div class="rounded-xl border border-white/5 bg-black/20 px-3 py-2.5">
        <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $label }}</div>
        <div class="mt-0.5 font-display text-lg font-bold text-gray-100 nums">{{ $value }}</div>
    </div>
@else
    {{-- Head-to-head comparison row: A value | LABEL | B value --}}
    <div class="grid grid-cols-3 items-center gap-2 rounded-xl border border-white/5 bg-black/20 px-3 py-2.5">
        <div class="text-left font-display text-lg font-bold nums {{ $aWin ? 'text-indigo-300' : 'text-gray-100' }}">
            {{ $a }}
        </div>
        <div class="text-center text-[10px] uppercase leading-tight tracking-wide text-gray-500">
            {{ $label }}
        </div>
        <div class="text-right font-display text-lg font-bold nums {{ $bWin ? 'text-cyan-300' : 'text-gray-100' }}">
            {{ $b }}
        </div>
    </div>
@endif
