<x-chat-shell title="Coach">
    @php
        $coachName = $profile->display_name ?: (auth()->user()?->name ?? 'you');
        $initialMessages = $messages->map(fn ($m) => [
            'role' => $m->role,
            'content' => (string) $m->content,
        ])->values();
        $sendUrl = $conversation ? "/coach/{$conversation->id}/send" : '/coach/send';
        $streamUrl = $conversation ? "/coach/{$conversation->id}/stream" : '/coach/stream';
        $scanUrl = $conversation ? "/coach/{$conversation->id}/scan" : '/coach/scan';
    @endphp

    <div class="flex h-full flex-col px-3 pb-2 pt-3 sm:px-4">
    {{-- Today's briefing: the proactive coach speaking first. Collapsed by default so the chat
         leads; one tap expands it. Grounded in real data, with a regenerate button. --}}
    <div class="mb-3 shrink-0 rounded-2xl border border-indigo-500/20 bg-gradient-to-br from-indigo-500/[0.07] to-cyan-400/[0.04] p-3.5"
         x-data="{ briefingOpen: false }">
        <div class="flex items-start gap-3">
            <div class="shrink-0 h-9 w-9 rounded-xl bg-gradient-to-br from-indigo-500 to-cyan-400 grid place-items-center">
                <svg class="h-5 w-5 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2">
                    <button type="button" @click="briefingOpen = !briefingOpen" class="flex items-center gap-1.5 min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-300/80">Today's briefing</p>
                        <svg class="h-3.5 w-3.5 text-indigo-300/60 transition" :class="briefingOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <form method="POST" action="/coach/briefing" class="shrink-0" x-show="briefingOpen">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-white/5 hover:bg-white/10 active:bg-white/10 border border-white/10 px-2.5 py-1.5 text-xs text-gray-300 transition">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Regenerate
                        </button>
                    </form>
                </div>
                <div x-show="briefingOpen" x-collapse>
                    @if (session('status'))
                        <p class="mt-2 text-xs text-cyan-300/90">{{ session('status') }}</p>
                    @endif
                    @if ($latestBriefing)
                        <p class="mt-1.5 text-sm leading-relaxed text-gray-200 whitespace-pre-wrap break-words">{{ $latestBriefing->content }}</p>
                        <p class="mt-2 text-[11px] text-gray-500">{{ $latestBriefing->created_at?->diffForHumans() }}</p>
                    @else
                        <p class="mt-1.5 text-sm text-gray-400">No briefing yet. Tap <span class="text-gray-300">Regenerate</span> to get a grounded read on your recovery, sleep and nutrition — or it'll arrive each morning.</p>
                    @endif
                </div>
                <p x-show="!briefingOpen" x-cloak class="mt-1 text-xs text-gray-500 truncate">{{ $latestBriefing ? \Illuminate\Support\Str::limit($latestBriefing->content, 60) : 'Tap to expand your daily briefing' }}</p>
            </div>
        </div>
    </div>

    {{-- Full-height chat: fills viewport minus the sticky header and bottom tab bar.
         The shell reserves bottom space (main has pb-28); we sit above it. --}}
    <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 lg:grid-cols-[16rem_1fr]">

        {{-- Conversations sidebar — desktop only --}}
        <aside class="hidden lg:flex lg:flex-col rounded-2xl border border-white/5 bg-white/[0.03] overflow-hidden">
            <div class="p-3 border-b border-white/5">
                <form method="POST" action="/coach">
                    @csrf
                    <button type="submit"
                            class="w-full flex items-center justify-center gap-2 rounded-xl bg-indigo-500/15 text-indigo-300 active:bg-indigo-500/25 px-3 py-2.5 text-sm font-medium transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        New chat
                    </button>
                </form>
            </div>
            <nav class="flex-1 overflow-y-auto p-2 space-y-1">
                @forelse ($conversations as $c)
                    @php $active = $conversation && $c->id === $conversation->id; @endphp
                    <a href="/coach?c={{ $c->id }}"
                       class="block truncate rounded-lg px-3 py-2 text-sm transition
                              {{ $active ? 'bg-white/10 text-gray-100' : 'text-gray-400 hover:text-gray-100 hover:bg-white/5' }}">
                        {{ $c->displayTitle() }}
                    </a>
                @empty
                    <p class="px-3 py-2 text-xs text-gray-600">No conversations yet.</p>
                @endforelse
            </nav>
        </aside>

        {{-- Chat panel --}}
        <section
            x-data="coachChat({
                sendUrl: '{{ $sendUrl }}',
                streamUrl: '{{ $streamUrl }}',
                scanUrl: '{{ $scanUrl }}',
                csrf: '{{ csrf_token() }}',
                initial: {{ Illuminate\Support\Js::from($initialMessages) }},
                aiOffline: {{ $aiOffline ? 'true' : 'false' }},
            })"
            class="flex flex-col rounded-2xl border border-white/5 bg-white/[0.03] overflow-hidden min-h-0">

            {{-- Mobile: conversation switcher + new chat (collapses the sidebar) --}}
            <div class="lg:hidden flex items-center gap-2 p-2.5 border-b border-white/5"
                 x-data="{ open: false }" @click.outside="open = false">
                <div class="relative flex-1 min-w-0">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between gap-2 rounded-xl border border-white/10 bg-gray-950/40 h-11 px-3 text-sm text-gray-200 active:bg-white/5">
                        <span class="truncate">{{ $conversation?->displayTitle() ?? 'New conversation' }}</span>
                        <svg class="h-4 w-4 shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top
                         class="absolute z-20 left-0 right-0 mt-1.5 max-h-72 overflow-y-auto rounded-xl border border-white/10 bg-gray-900 shadow-xl shadow-black/40 p-1.5 space-y-0.5">
                        @forelse ($conversations as $c)
                            @php $active = $conversation && $c->id === $conversation->id; @endphp
                            <a href="/coach?c={{ $c->id }}"
                               class="block truncate rounded-lg px-3 py-2.5 text-sm transition
                                      {{ $active ? 'bg-white/10 text-gray-100' : 'text-gray-300 active:bg-white/5' }}">
                                {{ $c->displayTitle() }}
                            </a>
                        @empty
                            <p class="px-3 py-2 text-xs text-gray-600">No conversations yet.</p>
                        @endforelse
                    </div>
                </div>
                <form method="POST" action="/coach" class="shrink-0">
                    @csrf
                    <button type="submit" title="New chat"
                            class="h-11 w-11 grid place-items-center rounded-xl bg-indigo-500/15 text-indigo-300 active:bg-indigo-500/25 transition">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    </button>
                </form>
            </div>

            @if ($aiOffline)
                <div class="px-4 py-2 bg-amber-500/10 border-b border-amber-500/20 text-sm text-amber-300">
                    The coach is offline — the AI service isn't configured yet. You can still browse past chats.
                </div>
            @endif

            {{-- Messages --}}
            <div x-ref="scroll" class="flex-1 overflow-y-auto p-4 space-y-4 min-h-0">
                {{-- Empty state: greeting + starter prompts --}}
                <template x-if="messages.length === 0">
                    <div class="max-w-xl mx-auto text-center py-8">
                        <div class="mx-auto mb-4 h-12 w-12 rounded-full bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center">
                            <svg class="h-6 w-6 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-4A7.97 7.97 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </div>
                        <h2 class="font-display text-lg font-bold text-gray-100">Hey {{ $coachName }} — I'm your coach.</h2>
                        <p class="text-sm text-gray-500 mt-1">I can see your biomarkers, meals, training, sleep and physique. Ask me anything.</p>
                        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($starters as $s)
                                <button type="button"
                                        @click="send('{{ addslashes($s) }}')"
                                        class="text-left rounded-xl border border-white/5 bg-gray-900/60 active:border-indigo-500/40 active:bg-gray-900 px-4 py-3 text-sm text-gray-300 transition">
                                    {{ $s }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </template>

                {{-- Conversation --}}
                <template x-for="(m, i) in messages" :key="i">
                    <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <div :class="m.role === 'user'
                                ? 'max-w-[85%] rounded-2xl rounded-br-sm bg-indigo-500/20 border border-indigo-500/30 px-4 py-2.5 text-sm text-gray-100'
                                : 'max-w-[85%] rounded-2xl rounded-bl-sm bg-gray-800/60 border border-white/5 px-4 py-2.5 text-sm text-gray-200'">
                            <div class="coach-prose leading-relaxed break-words" x-html="render(m.content)"></div>
                        </div>
                    </div>
                </template>

                {{-- Typing / tool-activity indicator (hidden once tokens start streaming in) --}}
                <div x-show="loading && !streaming" x-cloak class="flex justify-start">
                    <div class="rounded-2xl rounded-bl-sm bg-gray-800/60 border border-white/5 px-4 py-3">
                        {{-- Knowledge-base activity: an animated neural glyph (flows IN to retrieve, OUT to ingest) --}}
                        <div x-show="toolStatus && toolKnowledge" x-cloak class="tbrain" :class="toolMode === 'ingest' ? 'tbrain--ingest' : 'tbrain--retrieve'">
                            <svg class="tbrain-svg" viewBox="0 0 40 32" fill="none" aria-hidden="true">
                                <path class="tbrain-link" d="M8 16 L20 8"/>
                                <path class="tbrain-link" d="M8 16 L20 24"/>
                                <path class="tbrain-link" d="M20 8 L32 16"/>
                                <path class="tbrain-link" d="M20 24 L32 16"/>
                                <path class="tbrain-link" d="M20 8 L20 24"/>
                                <circle class="tbrain-node" style="--d:0ms"   cx="8"  cy="16" r="3"/>
                                <circle class="tbrain-node" style="--d:120ms" cx="20" cy="8"  r="3"/>
                                <circle class="tbrain-node" style="--d:240ms" cx="20" cy="24" r="3"/>
                                <circle class="tbrain-node" style="--d:360ms" cx="32" cy="16" r="3"/>
                                <circle class="tbrain-node tbrain-core" style="--d:180ms" cx="20" cy="16" r="3.6"/>
                            </svg>
                            <span class="tbrain-label" x-text="toolStatus + '…'"></span>
                        </div>
                        {{-- Live status while the coach pulls your data (non-knowledge tools) --}}
                        <div x-show="toolStatus && !toolKnowledge" x-cloak class="flex items-center gap-2 text-xs text-indigo-300/90">
                            <svg class="h-3.5 w-3.5 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"/></svg>
                            <span x-text="toolStatus + '…'"></span>
                        </div>
                        {{-- Bouncing dots before any status arrives --}}
                        <div x-show="!toolStatus" class="flex gap-1">
                            <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay:0ms"></span>
                            <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay:150ms"></span>
                            <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay:300ms"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Jump to latest — appears when you've scrolled up --}}
            <div class="relative">
                <button type="button" x-show="showJump" x-cloak @click="scrollDown()"
                        x-transition.opacity
                        class="absolute -top-12 right-4 z-10 grid h-10 w-10 place-items-center rounded-full bg-gray-800/90 border border-white/10 text-gray-200 shadow-lg shadow-black/40 backdrop-blur active:bg-gray-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7-7-7M12 3v18"/></svg>
                </button>
            </div>

            {{-- Suggested follow-ups — tappable chips, no typing needed --}}
            <div x-show="suggestions.length" x-cloak class="px-3 pt-2 -mb-1">
                <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
                    <template x-for="(s, i) in suggestions" :key="i">
                        <button type="button" @click="send(s)"
                                class="shrink-0 rounded-full border border-indigo-500/30 bg-indigo-500/10 px-3.5 py-1.5 text-xs text-indigo-200 active:bg-indigo-500/20 transition"
                                x-text="s"></button>
                    </template>
                </div>
            </div>

            {{-- Composer — sits at the end of the flex column, above the bottom tab bar --}}
            <div class="border-t border-white/5 p-3">
                {{-- Attached photo preview: pick a photo, add "this is what I ate", then send --}}
                <div x-show="pendingPreview" x-cloak class="mb-2 flex items-center gap-3">
                    <div class="relative shrink-0">
                        <img :src="pendingPreview" alt="" class="h-16 w-16 rounded-xl object-cover border border-white/10">
                        <button type="button" @click="clearPhoto()" aria-label="Remove photo"
                                class="absolute -top-1.5 -right-1.5 grid h-5 w-5 place-items-center rounded-full bg-gray-800 border border-white/15 text-gray-300 active:bg-gray-700">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 leading-snug">Add a note like <span class="text-gray-400">“this is what I ate”</span> — then send and I'll log the macros.</p>
                </div>

                <form @submit.prevent="send()" class="flex items-end gap-2">
                    {{-- Snap-to-log: attach a meal / bloodwork / body photo --}}
                    <input x-ref="photo" type="file" accept="image/*" capture="environment" class="hidden"
                           @change="if ($event.target.files[0]) { attachPhoto($event.target.files[0]); $event.target.value = ''; }">
                    <button type="button" @click="$refs.photo.click()" :disabled="loading"
                            title="Attach a meal, bloodwork or body photo"
                            class="shrink-0 h-12 w-12 grid place-items-center rounded-xl bg-white/5 border border-white/10 text-gray-300 active:bg-white/10 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            :class="pendingPhoto ? 'ring-2 ring-indigo-400/50 text-indigo-300' : ''">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </button>
                    <textarea
                        x-ref="input"
                        x-model="draft"
                        @keydown.enter.prevent="if(!$event.shiftKey) send()"
                        rows="1"
                        :placeholder="pendingPhoto ? 'Add a note (optional)…' : 'Ask your coach…'"
                        class="flex-1 min-w-0 resize-none rounded-xl bg-gray-950/60 border border-white/10 focus:border-indigo-500/50 focus:ring-0 px-4 py-3 text-base text-gray-100 placeholder-gray-600 max-h-40"></textarea>
                    <button type="submit"
                            :disabled="loading || (!draft.trim() && !pendingPhoto)"
                            class="shrink-0 h-12 w-12 grid place-items-center rounded-xl bg-indigo-500 active:bg-indigo-400 disabled:opacity-40 disabled:cursor-not-allowed text-white transition">
                        <span x-show="!loading">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </span>
                        <span x-show="loading" x-cloak>…</span>
                    </button>
                </form>
                <p class="mt-2 text-[11px] text-gray-600">Coaching, not medical advice. For clinical concerns, see a doctor.</p>
            </div>
        </section>
    </div>
    </div>

    <script>
        function coachChat(cfg) {
            return {
                messages: cfg.initial || [],
                draft: '',
                loading: false,
                streaming: false,      // true once tokens start arriving
                toolStatus: '',        // "Reading your day", etc. while a tool runs
                toolName: '',          // raw tool name → drives the knowledge-base animation
                get toolKnowledge() { return ['search_knowledge', 'save_knowledge', 'remember', 'forget', 'memory_book'].includes(this.toolName); },
                get toolMode() { return ['save_knowledge', 'remember', 'forget'].includes(this.toolName) ? 'ingest' : 'retrieve'; },
                suggestions: [],       // tappable follow-up chips
                pendingPhoto: null,    // a photo attached but not yet sent
                pendingPreview: '',    // its object URL for the preview thumbnail
                sendUrl: cfg.sendUrl,
                streamUrl: cfg.streamUrl,
                scanUrl: cfg.scanUrl,
                csrf: cfg.csrf,
                aiOffline: cfg.aiOffline,

                showJump: false,

                init() {
                    this.$nextTick(() => { this.scrollDown(); this.enhance(); });
                    const el = this.$refs.scroll;
                    if (el) el.addEventListener('scroll', () => { this.showJump = !this.nearBottom(); }, { passive: true });
                },

                nearBottom() {
                    const el = this.$refs.scroll;
                    return el ? (el.scrollHeight - el.scrollTop - el.clientHeight < 120) : true;
                },

                scrollDown() {
                    const el = this.$refs.scroll;
                    if (el) el.scrollTop = el.scrollHeight;
                    this.showJump = false;
                },

                // Code-copy buttons + tap-to-zoom images, applied to freshly-rendered messages.
                enhance() {
                    if (window.coachEnhance) window.coachEnhance(this.$refs.scroll);
                },

                // Full, sanitised GFM markdown (headings, lists, tables, code, links, images, thinking).
                render(text) {
                    return window.renderMarkdown ? window.renderMarkdown(text) : (text || '');
                },

                // Point both endpoints (and the browser URL) at a freshly-created conversation,
                // so a refresh keeps the thread.
                bindConversation(id) {
                    if (!id || /\/coach\/\d+\/stream/.test(this.streamUrl)) return;
                    this.sendUrl = '/coach/' + id + '/send';
                    this.streamUrl = '/coach/' + id + '/stream';
                    this.scanUrl = '/coach/' + id + '/scan';
                    history.replaceState(null, '', '/coach?c=' + id);
                },

                // Attach a photo to the composer (preview it); it sends when they hit send.
                attachPhoto(file) {
                    if (!file) return;
                    if (this.pendingPreview) URL.revokeObjectURL(this.pendingPreview);
                    this.pendingPhoto = file;
                    this.pendingPreview = URL.createObjectURL(file);
                    this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
                },
                clearPhoto() {
                    if (this.pendingPreview) URL.revokeObjectURL(this.pendingPreview);
                    this.pendingPhoto = null;
                    this.pendingPreview = '';
                },

                // Snap-to-log: send the attached photo + the typed caption; the coach reads it,
                // logs it (meal macros / bloodwork / body photo) and replies with the result.
                async sendPhoto() {
                    const file = this.pendingPhoto;
                    if (!file || this.loading) return;
                    const caption = this.draft.trim();
                    this.suggestions = [];
                    this.messages.push({ role: 'user', content: (caption ? caption + '\n\n' : '') + '![photo](' + this.pendingPreview + ')' });
                    this.draft = '';
                    this.pendingPhoto = null;          // keep pendingPreview alive for the bubble image
                    this.pendingPreview = '';
                    this.loading = true;
                    this.streaming = false;
                    this.toolStatus = 'Reading your photo';
                    this.$nextTick(() => { this.enhance(); this.scrollDown(); });

                    const fd = new FormData();
                    fd.append('photo', file);
                    if (caption) fd.append('message', caption);
                    try {
                        const res = await fetch(this.scanUrl, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            body: fd,
                        });
                        const data = await res.json();
                        this.messages.push({ role: 'assistant', content: (data && data.reply) || "Couldn't read that photo — try again." });
                        this.$nextTick(() => { this.enhance(); this.scrollDown(); });
                        if (data && data.conversation_id) this.bindConversation(data.conversation_id);
                    } catch (e) {
                        this.messages.push({ role: 'assistant', content: "Couldn't upload that photo. Check your connection and try again." });
                        this.$nextTick(() => { this.enhance(); this.scrollDown(); });
                    } finally {
                        this.finishSend();
                    }
                },

                // Parse one SSE frame ("event: x\ndata: {...}") into { event, data }.
                parseSse(raw) {
                    let event = 'message', dataStr = '';
                    for (const line of raw.split('\n')) {
                        if (line.startsWith('event:')) event = line.slice(6).trim();
                        else if (line.startsWith('data:')) dataStr += line.slice(5).trim();
                    }
                    let data = {};
                    if (dataStr) { try { data = JSON.parse(dataStr); } catch (_) { data = { text: dataStr }; } }
                    return { event, data };
                },

                finishSend() { this.loading = false; this.streaming = false; this.toolStatus = ''; this.toolName = ''; },

                async send(preset) {
                    // A photo is attached → send it (with the typed note as the caption).
                    if (preset === undefined && this.pendingPhoto && !this.loading) {
                        return this.sendPhoto();
                    }
                    const text = (preset !== undefined ? preset : this.draft).trim();
                    if (!text || this.loading) return;

                    this.messages.push({ role: 'user', content: text });
                    this.draft = '';
                    this.suggestions = [];
                    this.loading = true;
                    this.streaming = false;
                    this.toolStatus = '';
                    this.$nextTick(() => this.scrollDown());

                    // The assistant bubble we stream into — created lazily on the first token.
                    let idx = null;
                    const target = () => { if (idx === null) idx = this.messages.push({ role: 'assistant', content: '' }) - 1; return idx; };

                    let res;
                    try {
                        res = await fetch(this.streamUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'text/event-stream',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({ message: text }),
                        });
                    } catch (e) {
                        // Never reached the server — safe to retry via the plain JSON endpoint.
                        await this.sendFallback(text);
                        this.finishSend();
                        return;
                    }

                    // Rejected before streaming (CSRF/validation) → message wasn't saved; safe fallback.
                    if (!res.ok) { await this.sendFallback(text); this.finishSend(); return; }
                    if (!res.body) {
                        this.messages.push({ role: 'assistant', content: "Couldn't stream a reply — please try again." });
                        this.$nextTick(() => this.scrollDown());
                        this.finishSend();
                        return;
                    }

                    try {
                        const reader = res.body.getReader();
                        const decoder = new TextDecoder();
                        let buf = '', raf = false;
                        const keepPinned = () => {
                            if (raf) return; raf = true;
                            requestAnimationFrame(() => { raf = false; if (this.nearBottom()) this.scrollDown(); });
                        };

                        while (true) {
                            const { done, value } = await reader.read();
                            if (done) break;
                            buf += decoder.decode(value, { stream: true });

                            let sep;
                            while ((sep = buf.indexOf('\n\n')) !== -1) {
                                const frame = buf.slice(0, sep); buf = buf.slice(sep + 2);
                                const { event, data } = this.parseSse(frame);

                                if (event === 'delta') {
                                    this.streaming = true;
                                    this.messages[target()].content += (data.text || '');
                                    keepPinned();
                                } else if (event === 'tool') {
                                    this.toolStatus = data.label || 'Working';
                                    this.toolName = data.name || '';
                                } else if (event === 'meta') {
                                    this.bindConversation(data.conversation_id);
                                } else if (event === 'done') {
                                    if (data.content) this.messages[target()].content = data.content;
                                } else if (event === 'suggestions') {
                                    this.suggestions = Array.isArray(data.items) ? data.items : [];
                                } else if (event === 'error') {
                                    this.messages[target()].content = data.message || 'Something went wrong — please try again.';
                                }
                            }
                        }
                        this.$nextTick(() => { this.enhance(); if (this.nearBottom()) this.scrollDown(); else this.showJump = true; });
                    } catch (e) {
                        // Mid-stream drop: keep whatever streamed; otherwise show a generic error.
                        if (idx === null) {
                            this.messages.push({ role: 'assistant', content: "Couldn't reach your coach. Check your connection and try again." });
                            this.$nextTick(() => this.scrollDown());
                        } else {
                            this.$nextTick(() => this.enhance());
                        }
                    } finally {
                        this.finishSend();
                    }
                },

                // Non-streaming fallback: the original JSON send, used when streaming can't start.
                async sendFallback(text) {
                    try {
                        const res = await fetch(this.sendUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({ message: text, ajax: true }),
                        });
                        const data = await res.json();
                        const reply = (data && data.reply) || "Something went wrong — please try again.";
                        const wasNear = this.nearBottom();
                        this.messages.push({ role: 'assistant', content: reply });
                        this.$nextTick(() => { this.enhance(); if (wasNear) this.scrollDown(); else this.showJump = true; });
                        if (data && data.conversation_id) this.bindConversation(data.conversation_id);
                    } catch (e) {
                        this.messages.push({ role: 'assistant', content: "Couldn't reach your coach. Check your connection and try again." });
                        this.$nextTick(() => { this.enhance(); this.scrollDown(); });
                    }
                },
            };
        }
    </script>
</x-chat-shell>
