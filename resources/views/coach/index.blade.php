<x-titan-layout title="Coach" subtitle="Your AI coach, grounded in all your data">
    @php
        $coachName = $profile->display_name ?: (auth()->user()?->name ?? 'you');
        $initialMessages = $messages->map(fn ($m) => [
            'role' => $m->role,
            'content' => (string) $m->content,
        ])->values();
        $sendUrl = '/coach/' . ($conversation?->id ?? '') . '/send';
    @endphp

    {{-- Today's briefing: the proactive coach speaking first. Latest stored morning/evening
         briefing, grounded in real data, with a regenerate button. Mobile-first, dark. --}}
    <div class="mb-4 rounded-2xl border border-indigo-500/20 bg-gradient-to-br from-indigo-500/[0.07] to-cyan-400/[0.04] p-4"
         x-data="{ briefingOpen: true }">
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
    <div class="grid grid-cols-1 lg:grid-cols-[16rem_1fr] gap-4 h-[calc(100dvh-20rem)] md:h-[calc(100dvh-16rem)]">

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
                            <div class="coach-prose whitespace-pre-wrap leading-relaxed break-words" x-html="render(m.content)"></div>
                        </div>
                    </div>
                </template>

                {{-- Typing indicator --}}
                <div x-show="loading" x-cloak class="flex justify-start">
                    <div class="rounded-2xl rounded-bl-sm bg-gray-800/60 border border-white/5 px-4 py-3">
                        <div class="flex gap-1">
                            <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay:0ms"></span>
                            <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay:150ms"></span>
                            <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay:300ms"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Composer — sits at the end of the flex column, above the bottom tab bar --}}
            <div class="border-t border-white/5 p-3">
                <form @submit.prevent="send()" class="flex items-end gap-2">
                    <textarea
                        x-ref="input"
                        x-model="draft"
                        @keydown.enter.prevent="if(!$event.shiftKey) send()"
                        rows="1"
                        placeholder="Ask your coach…"
                        class="flex-1 min-w-0 resize-none rounded-xl bg-gray-950/60 border border-white/10 focus:border-indigo-500/50 focus:ring-0 px-4 py-3 text-base text-gray-100 placeholder-gray-600 max-h-40"></textarea>
                    <button type="submit"
                            :disabled="loading || !draft.trim()"
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

    <script>
        function coachChat(cfg) {
            return {
                messages: cfg.initial || [],
                draft: '',
                loading: false,
                sendUrl: cfg.sendUrl,
                csrf: cfg.csrf,
                aiOffline: cfg.aiOffline,

                init() {
                    this.$nextTick(() => this.scrollDown());
                },

                scrollDown() {
                    const el = this.$refs.scroll;
                    if (el) el.scrollTop = el.scrollHeight;
                },

                // Minimal, safe markdown-ish rendering: escape, then **bold** and `code`.
                render(text) {
                    const esc = (text || '')
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    return esc
                        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                        .replace(/`([^`]+)`/g, '<code class="px-1 py-0.5 rounded bg-black/30 text-cyan-300">$1</code>');
                },

                async send(preset) {
                    const text = (preset !== undefined ? preset : this.draft).trim();
                    if (!text || this.loading) return;

                    this.messages.push({ role: 'user', content: text });
                    this.draft = '';
                    this.loading = true;
                    this.$nextTick(() => this.scrollDown());

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
                        this.messages.push({ role: 'assistant', content: reply });

                        // First message in a brand-new conversation: point the URL at it
                        // so a refresh keeps the thread.
                        if (data && data.conversation_id && !this.sendUrl.match(/\/coach\/\d+\/send/)) {
                            this.sendUrl = '/coach/' + data.conversation_id + '/send';
                            history.replaceState(null, '', '/coach?c=' + data.conversation_id);
                        }
                    } catch (e) {
                        this.messages.push({ role: 'assistant', content: "Couldn't reach your coach. Check your connection and try again." });
                    } finally {
                        this.loading = false;
                        this.$nextTick(() => this.scrollDown());
                    }
                },
            };
        }
    </script>
</x-titan-layout>
