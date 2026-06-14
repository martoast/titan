<x-titan-layout :title="$page->title" subtitle="Brain page">

    <div class="mb-5 flex items-center justify-between gap-3">
        <a href="/brain" class="text-sm text-gray-500 hover:text-gray-300">&larr; Back to the brain</a>
        <div class="flex items-center gap-2">
            <a href="/brain/{{ $page->slug }}/edit" class="rounded-lg bg-white/5 border border-white/10 px-4 py-1.5 text-sm font-medium text-gray-200 hover:bg-white/10 transition">Edit</a>
            <form method="POST" action="/brain/{{ $page->slug }}" onsubmit="return confirm('Delete this page?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg bg-white/5 border border-white/10 px-4 py-1.5 text-sm font-medium text-rose-300/80 hover:bg-rose-500/10 hover:text-rose-300 transition">Delete</button>
            </form>
        </div>
    </div>

    <article class="rounded-2xl border border-white/5 bg-gray-900/50 p-6">
        <header class="mb-4 pb-4 border-b border-white/5">
            <div class="flex items-center gap-2">
                @if ($page->is_pinned)<span class="text-indigo-400" title="Pinned (core memory)">★</span>@endif
                <h2 class="text-2xl font-bold text-gray-100">{{ $page->title }}</h2>
            </div>
            <div class="flex items-center gap-2 mt-2 text-xs text-gray-500">
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

        <div class="text-sm text-gray-300 leading-relaxed">
            {!! $html !!}
        </div>
    </article>

    {{-- Backlinks --}}
    <div class="mt-6">
        <h3 class="text-sm font-semibold text-gray-400 mb-2">Backlinks</h3>
        @if ($backlinks->isEmpty())
            <p class="text-sm text-gray-600">No pages link here yet.</p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($backlinks as $b)
                    <a href="/brain/{{ $b->slug }}" class="rounded-lg bg-gray-900/50 border border-white/5 px-3 py-1.5 text-sm text-gray-300 hover:border-indigo-500/40 transition">{{ $b->title }}</a>
                @endforeach
            </div>
        @endif
    </div>

</x-titan-layout>
