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
    <form method="GET" action="/brain" class="mb-6 space-y-2 sm:space-y-0 sm:flex sm:items-center sm:gap-2">
        <input type="search" name="q" value="{{ $q }}" placeholder="Search the brain (semantic + keyword)…"
               class="w-full sm:flex-1 h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 placeholder-gray-500 focus:border-indigo-500/50 focus:outline-none" />
        <div class="grid grid-cols-2 gap-2 sm:flex sm:shrink-0">
            <button type="submit" class="h-11 rounded-xl bg-indigo-500/15 border border-indigo-500/30 px-5 text-sm font-semibold text-indigo-300 transition hover:bg-indigo-500/25 active:bg-indigo-500/30">Search</button>
            <a href="/brain/create" class="h-11 grid place-items-center rounded-xl bg-white/5 border border-white/10 px-5 text-sm font-semibold text-gray-200 transition hover:bg-white/10 active:bg-white/[0.14]">+ New page</a>
        </div>
    </form>

    @if ($q !== '')
        {{-- Search results --}}
        <div class="mb-8">
            <h3 class="font-display text-sm font-bold text-gray-400 mb-3 break-words">
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
                        <a href="/brain/{{ $p->slug }}" class="block rounded-2xl border border-white/5 bg-white/[0.03] p-4 transition hover:border-indigo-500/40 hover:bg-gray-900 active:bg-white/[0.06]">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($p->is_pinned)<span class="text-indigo-400 text-xs">★</span>@endif
                                    <span class="font-medium text-gray-100 truncate">{{ $p->title }}</span>
                                    <span class="shrink-0 text-[10px] uppercase tracking-wide text-gray-500 border border-white/10 rounded px-1.5 py-0.5">{{ $p->type }}</span>
                                </div>
                                <span class="text-xs text-gray-600 shrink-0 nums">{{ number_format($row['score'] * 100) }}%</span>
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
        <h3 class="font-display text-sm font-bold text-gray-400 mb-3">All pages ({{ $pages->count() }})</h3>
        @if ($pages->isEmpty())
            <div class="rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-gray-500">
                The brain is empty. Use a brain dump below, or
                <a href="/brain/create" class="text-indigo-400 hover:underline">create a page</a>.
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                @foreach ($pages as $p)
                    <a href="/brain/{{ $p->slug }}" class="group rounded-2xl border border-white/5 bg-white/[0.03] p-4 transition hover:border-indigo-500/40 hover:bg-gray-900 active:bg-white/[0.06]">
                        <div class="flex items-center gap-2 min-w-0">
                            @if ($p->is_pinned)<span class="shrink-0 text-indigo-400 text-xs" title="Pinned (core memory)">★</span>@endif
                            <span class="font-medium text-gray-100 truncate">{{ $p->title }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-1.5">
                            <span class="text-[10px] uppercase tracking-wide text-gray-500 border border-white/10 rounded px-1.5 py-0.5">{{ $p->type }}</span>
                            <span class="text-xs text-gray-600">{{ $p->updated_at?->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Brain dump + document upload — deferred; the page leads with what it already knows --}}
    <div x-data="{ addOpen: {{ $errors->any() ? 'true' : 'false' }} }">
    <button type="button" @click="addOpen = !addOpen" class="flex w-full items-center justify-between gap-3 rounded-2xl border border-white/5 bg-white/[0.03] px-4 py-3.5 text-left active:bg-white/[0.05]">
        <span class="flex items-center gap-2.5">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-indigo-500/15 text-indigo-300">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            </span>
            <span class="font-display font-bold text-gray-100">Add to brain</span>
        </span>
        <svg class="h-5 w-5 shrink-0 text-gray-500 transition" :class="addOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div x-show="addOpen" x-collapse x-cloak class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-4">
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100">Brain dump</h3>
            <p class="text-sm text-gray-500 mt-1 mb-3">Paste anything — training history, nutrition notes, goals, injuries. The AI librarian files it into clean wiki pages.</p>
            <form method="POST" action="/brain/dump">
                @csrf
                <textarea name="dump" rows="6" placeholder="e.g. I respond well to high-volume leg days. Family history of high cholesterol. Goal: +10 lbs lean muscle by next summer…"
                          class="w-full rounded-xl bg-gray-950 border border-white/10 px-3 py-3 text-base text-gray-100 placeholder-gray-600 focus:border-indigo-500/50 focus:outline-none"></textarea>
                <button type="submit" @unless($aiConfigured) disabled @endunless
                        class="mt-3 w-full md:w-auto h-12 rounded-xl bg-indigo-500/15 border border-indigo-500/30 px-5 text-sm font-semibold text-indigo-300 transition hover:bg-indigo-500/25 active:bg-indigo-500/30 disabled:opacity-40 disabled:cursor-not-allowed">
                    Organize into wiki
                </button>
            </form>
        </div>

        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <h3 class="font-display font-bold text-gray-100">Upload a document</h3>
            <p class="text-sm text-gray-500 mt-1 mb-3">Bloodwork PDF, lab report, doctor's notes (PDF / DOCX / TXT). Text is extracted and filed into the brain.</p>
            <form method="POST" action="/brain/upload" enctype="multipart/form-data">
                @csrf
                <x-upload-zone kind="file" name="document" accept=".pdf,.docx,.pptx,.txt,.md,.csv,.json,.rtf"
                               label="Tap to add a document" hint="PDF, Word, or text — extracted into your brain" />
                @error('document')<p class="text-xs text-amber-300 mt-2">{{ $message }}</p>@enderror
                <button type="submit" @unless($aiConfigured) disabled @endunless
                        class="mt-3 w-full md:w-auto h-12 rounded-xl bg-indigo-500/15 border border-indigo-500/30 px-5 text-sm font-semibold text-indigo-300 transition hover:bg-indigo-500/25 active:bg-indigo-500/30 disabled:opacity-40 disabled:cursor-not-allowed">
                    Extract &amp; ingest
                </button>
            </form>
        </div>
    </div>
    </div>

</x-titan-layout>
