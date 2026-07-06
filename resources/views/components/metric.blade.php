@props([
    'value' => '—',
    'unit' => null,
    'label' => null,
    'color' => 'cyan',   // titan pillar key
    'icon' => null,      // optional raw SVG path `d`
])
@php
    $hex = [
        'indigo' => '#6D6BF6', 'cyan' => '#22D3EE', 'mint' => '#34E5C0',
        'pink' => '#FF4D8D', 'amber' => '#FFB020', 'violet' => '#A78BFA',
    ][$color] ?? '#22D3EE';
@endphp
{{-- One stat: optional icon, big value + unit, small label — the iOS Metric chip. --}}
<div class="flex-1 min-w-0">
    <div class="flex items-center gap-1.5 mb-1">
        @if ($icon)
            <svg class="h-3.5 w-3.5 shrink-0" style="color: {{ $hex }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
        @endif
        <span class="text-[0.65rem] font-bold uppercase tracking-[0.12em] text-gray-500 truncate">{{ $label }}</span>
    </div>
    <div class="flex items-baseline gap-1">
        <span class="font-display text-2xl font-bold text-gray-50 nums">{{ $value }}</span>
        @if ($unit)<span class="text-xs text-gray-500">{{ $unit }}</span>@endif
    </div>
</div>
