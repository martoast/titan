<x-titan-layout :title="$page->title" subtitle="Brain page">

    <div class="mb-4">
        <a href="/brain" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-300 active:text-gray-200">&larr; Back to the brain</a>
    </div>

    {{-- Actions — full-width on mobile, side-by-side from sm: --}}
    <div class="mb-4 grid grid-cols-2 gap-2 sm:flex sm:justify-end">
        <a href="/brain/{{ $page->slug }}/edit" class="h-11 grid place-items-center rounded-xl bg-white/5 border border-white/10 px-5 text-sm font-semibold text-gray-200 transition hover:bg-white/10 active:bg-white/[0.14]">Edit</a>
        <form method="POST" action="/brain/{{ $page->slug }}" onsubmit="return confirm('Delete this page?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="w-full h-11 rounded-xl bg-white/5 border border-white/10 px-5 text-sm font-semibold text-rose-300/80 transition hover:bg-rose-500/10 hover:text-rose-300 active:bg-rose-500/20">Delete</button>
        </form>
    </div>

    <article class="glass-card p-4 md:p-6">
        <header class="mb-4 pb-4 border-b border-white/5">
            <div class="flex items-start gap-2">
                @if ($page->is_pinned)<span class="mt-1 shrink-0 text-indigo-400" title="Pinned (core memory)">★</span>@endif
                <h2 class="font-display text-xl md:text-2xl font-bold text-gray-100 break-words min-w-0">{{ $page->title }}</h2>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-2 text-xs text-gray-500">
                <span class="uppercase tracking-wide border border-white/10 rounded px-1.5 py-0.5">{{ $page->type }}</span>
                <span>Updated {{ $page->updated_at?->diffForHumans() }}</span>
            </div>
        </header>

        {{-- Lightweight, safe markdown render: escape first, then apply a few patterns. --}}
        @php
            $md = e((string) $page->content);
            // [[Wikilink]] → internal link to the slugged page.
            $md = preg_replace_callback('/\[\[([^\]]+)\]\]/', function ($m) {
                $title = trim($m[1]);
                $slug = \Illuminate\Support\Str::slug($title);
                return '<a href="/brain/'.$slug.'" class="text-indigo-300 hover:underline">'.$title.'</a>';
            }, $md);
            // **bold** and *italic*
            $md = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $md);
            $md = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $md);
            // Headings + bullets, line by line.
            $lines = explode("\n", $md);
            $html = '';
            $inList = false;
            foreach ($lines as $line) {
                $t = rtrim($line);
                if (preg_match('/^\s*[-*]\s+(.*)$/', $t, $m)) {
                    if (! $inList) { $html .= '<ul class="list-disc pl-5 space-y-1 my-2">'; $inList = true; }
                    $html .= '<li>'.$m[1].'</li>';
                    continue;
                }
                if ($inList) { $html .= '</ul>'; $inList = false; }
                if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
                    $level = min(6, strlen($m[1]) + 1);
                    $cls = $level <= 2 ? 'text-lg font-semibold mt-4 mb-1' : 'text-base font-semibold mt-3 mb-1';
                    $html .= "<h{$level} class=\"{$cls} text-gray-100\">".$m[2]."</h{$level}>";
                } elseif (trim($t) === '') {
                    $html .= '<div class="h-2"></div>';
                } else {
                    $html .= '<p class="my-1">'.$t.'</p>';
                }
            }
            if ($inList) { $html .= '</ul>'; }
        @endphp

        <div class="text-[15px] text-gray-300 leading-relaxed break-words">
            {!! $html !!}
        </div>
    </article>

    {{-- Backlinks --}}
    <div class="mt-5 md:mt-6">
        <h3 class="font-display text-sm font-bold text-gray-400 mb-2">Backlinks</h3>
        @if ($backlinks->isEmpty())
            <p class="text-sm text-gray-600">No pages link here yet.</p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($backlinks as $b)
                    <a href="/brain/{{ $b->slug }}" class="max-w-full truncate rounded-xl bg-white/[0.03] border border-white/5 px-3 py-2 text-sm text-gray-300 transition hover:border-indigo-500/40 active:bg-white/[0.06]">{{ $b->title }}</a>
                @endforeach
            </div>
        @endif
    </div>

</x-titan-layout>
