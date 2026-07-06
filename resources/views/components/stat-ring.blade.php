@props([
    'value' => null,      // current value (null → shows "—")
    'max' => 100,         // 100 for %, 21 for strain
    'label' => null,      // caption under the ring
    'color' => 'indigo',  // titan pillar color key: indigo|cyan|mint|pink|amber|violet
    'size' => 108,        // px
    'display' => null,     // optional override for the centered number text
])
@php
    $hex = [
        'indigo' => '#6D6BF6', 'cyan' => '#22D3EE', 'mint' => '#34E5C0',
        'pink' => '#FF4D8D', 'amber' => '#FFB020', 'violet' => '#A78BFA',
    ][$color] ?? '#6D6BF6';
    $r = 46; $circ = 2 * M_PI * $r;                    // viewBox 108, r=46, stroke 9
    $pct = $value !== null && $max > 0 ? max(0, min(1, $value / $max)) : 0;
    $offset = $circ * (1 - $pct);
    $num = $display ?? ($value !== null ? (fmod((float) $value, 1.0) == 0.0 ? (int) $value : rtrim(rtrim(number_format((float) $value, 1), '0'), '.')) : '—');
    $gid = 'ring-'.$color.'-'.substr(md5($label.$size.$value), 0, 6);
@endphp
<div class="flex flex-col items-center gap-2">
    <div class="relative" style="width: {{ $size }}px; height: {{ $size }}px;">
        <svg viewBox="0 0 108 108" class="-rotate-90 w-full h-full" style="filter: drop-shadow(0 0 8px {{ $hex }}55);">
            <circle cx="54" cy="54" r="{{ $r }}" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="9"/>
            <circle cx="54" cy="54" r="{{ $r }}" fill="none" stroke="url(#{{ $gid }})" stroke-width="9" stroke-linecap="round"
                    stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $offset }}"/>
            <defs>
                <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="{{ $hex }}" stop-opacity="0.6"/>
                    <stop offset="100%" stop-color="{{ $hex }}"/>
                </linearGradient>
            </defs>
        </svg>
        <div class="absolute inset-0 grid place-items-center text-center">
            <div class="font-display font-bold text-gray-50 nums leading-none" style="font-size: {{ round($size * 0.30) }}px;">{{ $num }}</div>
        </div>
    </div>
    @if ($label)
        <div class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-gray-500">{{ $label }}</div>
    @endif
</div>
