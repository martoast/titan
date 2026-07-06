@props(['pad' => 'p-4'])
{{-- Glass card — the web twin of the iOS GlassCard (white/0.045 fill, sheen, hairline
     stroke, 22px corners, soft shadow). Padding overridable via :pad. --}}
<div {{ $attributes->merge(['class' => "glass-card $pad"]) }}>
    {{ $slot }}
</div>
