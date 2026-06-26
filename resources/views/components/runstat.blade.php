@props(['label', 'value', 'unit' => null, 'tone' => 'text-gray-100', 'hint' => null])

<div class="rounded-2xl border border-white/5 bg-white/[0.03] px-3 py-2.5" @if ($hint) title="{{ $hint }}" @endif>
    <div class="text-[10px] uppercase tracking-wider text-gray-500">{{ $label }}</div>
    <div class="mt-0.5 font-display nums text-[19px] font-bold leading-tight {{ $tone }}">
        {{ $value }}@if ($unit)<span class="ml-0.5 text-[11px] font-normal text-gray-500">{{ $unit }}</span>@endif
    </div>
</div>
