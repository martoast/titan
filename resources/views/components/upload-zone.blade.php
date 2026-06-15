@props([
    'name' => 'file',
    'accept' => 'image/*',
    'kind' => 'image',              // 'image' → thumbnail preview · 'file' → document tile
    'label' => 'Tap to add a file',
    'hint' => null,
    'required' => false,
])

{{-- A big, obvious tap-to-upload zone with a live preview. Replaces the tiny,
     confusing native file input. Self-contained Alpine scope (fileName/filePreview)
     so it nests safely inside any form. --}}
<label {{ $attributes->merge(['class' => 'block cursor-pointer select-none']) }}
       x-data="{ fileName: '', filePreview: '' }">
    <input type="file" name="{{ $name }}" accept="{{ $accept }}" @if ($required) required @endif class="sr-only"
           @change="const f = $event.target.files[0]; if (f) { fileName = f.name; @if ($kind === 'image') if (filePreview) URL.revokeObjectURL(filePreview); filePreview = URL.createObjectURL(f); @endif }">
    <div class="rounded-2xl border-2 border-dashed border-indigo-500/40 bg-indigo-500/[0.06] active:bg-indigo-500/10 hover:bg-indigo-500/[0.09] transition text-center"
         :class="fileName ? 'p-3' : 'p-7'">
        {{-- empty state --}}
        <div x-show="!fileName">
            <div class="mx-auto h-14 w-14 rounded-2xl bg-indigo-500/15 grid place-items-center mb-3">
                <svg class="h-7 w-7 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
            </div>
            <p class="font-display font-bold text-gray-100">{{ $label }}</p>
            @if ($hint)
                <p class="text-xs text-gray-500 mt-1">{{ $hint }}</p>
            @endif
        </div>
        {{-- selected state --}}
        <div x-show="fileName" x-cloak class="flex items-center gap-3 text-left">
            @if ($kind === 'image')
                <img :src="filePreview" class="h-20 w-16 object-cover rounded-lg border border-white/10 shrink-0">
            @else
                <div class="h-16 w-[3.25rem] rounded-lg bg-white/5 border border-white/10 grid place-items-center shrink-0">
                    <svg class="h-6 w-6 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
            @endif
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-100 truncate" x-text="fileName"></p>
                <p class="text-xs text-indigo-300 mt-0.5">Tap to change</p>
            </div>
        </div>
    </div>
</label>
