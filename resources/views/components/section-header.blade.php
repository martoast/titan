@props(['title', 'trailing' => null])
{{-- iOS SectionHeader: uppercase tracked label + optional trailing text/link. --}}
<div class="flex items-center justify-between gap-3 mb-3">
    <h3 class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-gray-500">{{ $title }}</h3>
    @if ($trailing)
        <span class="text-xs text-gray-500">{{ $trailing }}</span>
    @endif
</div>
