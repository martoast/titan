<x-titan-layout title="The Brain" subtitle="Your AI long-term memory — everything it knows about you">

    @if (session('brain_error'))
        <div class="mb-4 rounded-lg bg-amber-500/10 border border-amber-500/20 px-4 py-3 text-sm text-amber-300">
            {{ session('brain_error') }}
        </div>
    @endif

    @unless ($aiConfigured)
        <div class="mb-4 rounded-lg bg-gray-800/60 border border-white/5 px-4 py-2 text-xs text-gray-400">
            AI is offline — search falls back to keyword matching, and brain-dump / document ingestion is paused.
        </div>
    @endunless

    {{-- Search --}}
    <form method="GET" action="/brain" class="mb-6">
        <div class="flex gap-2">
            <input type="search" name="q" value="{{ $q }}" placeholder="Search the brain (semantic + keyword)…"
                   class="flex-1 rounded-lg bg-gray-900/60 border border-white/10 px-4 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500/50 focus:outline-none" />
            <button type="submit" class="rounded-lg bg-indigo-500/15 border border-indigo-500/30 px-5 py-2.5 text-sm font-medium text-indigo-300 hover:bg-indigo-500/25 transition">Search</button>
            <a href="/brain/create" class="rounded-lg bg-white/5 border border-white/10 px-5 py-2.5 text-sm font-medium text-gray-200 hover:bg-white/10 transition">+ New page</a>
        </div>
    </form>

    @if ($q !== '')
        {{-- Search results --}}
        <div class="mb-8">
            <h3 class="text-sm font-semibold text-gray-400 mb-3">
                {{ count($results) }} result{{ count($results) === 1 ? '' : 's' }} for “{{ $q }}”
            </h3>
            @if ($searchError)
                <p class="text-sm text-amber-300">{{ $searchError }}</p>
            @elseif (empty($results))
                <p class="text-sm text-gray-500">No matching pages.</p>
            @else
                <div class="space-y-2">
                    @foreach ($results as $row)
                        @php $p = $row['page']; @endphp
                        <a href="/brain/{{ $p->slug }}" class="block rounded-xl border border-white/5 bg-gray-900/50 p-4 hover:border-indigo-500/40 hover:bg-gray-900 transition">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($p->is_pinned)<span class="text-indigo-400 text-xs">★</span>@endif
                                    <span class="font-medium text-gray-100 truncate">{{ $p->title }}</span>
                                    <span class="text-[10px] uppercase tracking-wide text-gray-500 border border-white/10 rounded px-1.5 py-0.5">{{ $p->type }}</span>
                                </div>
                                <span class="text-xs text-gray-600 shrink-0">{{ number_format($row['score'] * 100) }}%</span>
                            </div>
                            @if ($row['snippet'])
                                <p class="text-sm text-gray-500 mt-1.5 line-clamp-2">{{ $row['snippet'] }}</p>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- All pages (pinned first) --}}
    <div class="mb-8">
        <h3 class="text-sm font-semibold text-gray-400 mb-3">All pages ({{ $pages->count() }})</h3>
        @if ($pages->isEmpty())
            <div class="rounded-xl border border-dashed border-white/10 p-8 text-center text-sm text-gray-500">
                The brain is empty. Use a brain dump below, or
                <a href="/brain/create" class="text-indigo-400 hover:underline">create a page</a>.
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($pages as $p)
                    <a href="/brain/{{ $p->slug }}" class="group rounded-xl border border-white/5 bg-gray-900/50 p-4 hover:border-indigo-500/40 hover:bg-gray-900 transition">
                        <div class="flex items-center gap-2">
                            @if ($p->is_pinned)<span class="text-indigo-400 text-xs" title="Pinned (core memory)">★</span>@endif
                            <span class="font-medium text-gray-100 truncate">{{ $p->title }}</span>
                        </div>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-[10px] uppercase tracking-wide text-gray-500 border border-white/10 rounded px-1.5 py-0.5">{{ $p->type }}</span>
                            <span class="text-xs text-gray-600">{{ $p->updated_at?->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Brain dump + document upload --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <h3 class="font-semibold text-gray-100">Brain dump</h3>
            <p class="text-sm text-gray-500 mt-1 mb-3">Paste anything — training history, nutrition notes, goals, injuries. The AI librarian files it into clean wiki pages.</p>
            <form method="POST" action="/brain/dump">
                @csrf
                <textarea name="dump" rows="6" placeholder="e.g. I respond well to high-volume leg days. Family history of high cholesterol. Goal: +10 lbs lean muscle by next summer…"
                          class="w-full rounded-lg bg-gray-950/50 border border-white/10 px-3 py-2 text-sm text-gray-100 placeholder-gray-600 focus:border-indigo-500/50 focus:outline-none"></textarea>
                <button type="submit" @unless($aiConfigured) disabled @endunless
                        class="mt-3 rounded-lg bg-indigo-500/15 border border-indigo-500/30 px-4 py-2 text-sm font-medium text-indigo-300 hover:bg-indigo-500/25 transition disabled:opacity-40 disabled:cursor-not-allowed">
                    Organize into wiki
                </button>
            </form>
        </div>

        <div class="rounded-xl border border-white/5 bg-gray-900/50 p-5">
            <h3 class="font-semibold text-gray-100">Upload a document</h3>
            <p class="text-sm text-gray-500 mt-1 mb-3">Bloodwork PDF, lab report, doctor's notes (PDF / DOCX / TXT). Text is extracted and filed into the brain.</p>
            <form method="POST" action="/brain/upload" enctype="multipart/form-data">
                @csrf
                <input type="file" name="document" accept=".pdf,.docx,.pptx,.txt,.md,.csv,.json,.rtf"
                       class="block w-full text-sm text-gray-400 file:mr-3 file:rounded-lg file:border-0 file:bg-white/5 file:px-4 file:py-2 file:text-sm file:font-medium file:text-gray-200 hover:file:bg-white/10" />
                @error('document')<p class="text-xs text-amber-300 mt-2">{{ $message }}</p>@enderror
                <button type="submit" @unless($aiConfigured) disabled @endunless
                        class="mt-3 rounded-lg bg-indigo-500/15 border border-indigo-500/30 px-4 py-2 text-sm font-medium text-indigo-300 hover:bg-indigo-500/25 transition disabled:opacity-40 disabled:cursor-not-allowed">
                    Extract &amp; ingest
                </button>
            </form>
        </div>
    </div>

</x-titan-layout>
