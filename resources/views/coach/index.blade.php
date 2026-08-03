<x-chat-shell title="Coach">
    @php
        $coachName = $profile->display_name ?: (auth()->user()?->name ?? 'you');
        $initialMessages = $messages->map(fn ($m) => [
            'role' => $m->role,
            'kind' => $m->kind,
            'at' => $m->created_at?->timezone(\App\Models\Conversation::tz())->format('g:i A'),
            'content' => (string) $m->content,
        ])->values();
        $sendUrl = $conversation ? "/coach/{$conversation->id}/send" : '/coach/send';
        $streamUrl = $conversation ? "/coach/{$conversation->id}/stream" : '/coach/stream';
        $scanUrl = $conversation ? "/coach/{$conversation->id}/scan" : '/coach/scan';
    @endphp

    <div class="flex h-full flex-col px-3 pb-2 pt-3 sm:px-4">
    {{-- Full-height chat: fills viewport minus the sticky header and bottom tab bar.
         The shell reserves bottom space (main has pb-28); we sit above it. --}}
    <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 lg:grid-cols-[16rem_1fr]"
        x-data="coachChat({
            sendUrl: '{{ $sendUrl }}',
            streamUrl: '{{ $streamUrl }}',
            scanUrl: '{{ $scanUrl }}',
            transcribeUrl: '/coach/transcribe',
            csrf: '{{ csrf_token() }}',
            initial: {{ Illuminate\Support\Js::from($initialMessages) }},
            conversationId: {{ $conversation?->id ?? 'null' }},
            hasMore: {{ ($hasMore ?? false) ? 'true' : 'false' }},
            oldestId: {{ $oldestId ?? 'null' }},
            dayLabel: {{ Illuminate\Support\Js::from($conversation?->dayLabel() ?? 'Today') }},
            dayFull: {{ Illuminate\Support\Js::from($conversation?->dayFull() ?? $todayLabel) }},
            todayFull: {{ Illuminate\Support\Js::from($todayLabel) }},
            readOnly: {{ ($readOnly ?? false) ? 'true' : 'false' }},
            tz: {{ Illuminate\Support\Js::from(\App\Models\Conversation::tz()) }},
            aiOffline: {{ $aiOffline ? 'true' : 'false' }},
        })">

        {{-- Days sidebar — desktop only. One chat per day; the date IS the thread. --}}
        <aside class="hidden lg:flex lg:flex-col rounded-2xl border border-white/5 bg-white/[0.03] overflow-hidden">
            <div class="p-3 border-b border-white/5">
                <button type="button" @click="goToday()"
                        class="w-full flex items-center justify-center gap-2 rounded-xl bg-indigo-500/15 text-indigo-300 active:bg-indigo-500/25 px-3 py-2.5 text-sm font-medium transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M3 11h18M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>
                    Today
                </button>
            </div>
            <nav class="flex-1 overflow-y-auto p-2 space-y-1">
                @forelse ($conversations as $c)
                    <a href="/coach?c={{ $c->id }}"
                       @click.prevent="openChat({{ $c->id }})"
                       title="{{ $c->dayFull() }}"
                       class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm transition"
                       :class="activeId === {{ $c->id }} ? 'bg-white/10 text-gray-100' : 'text-gray-400 hover:text-gray-100 hover:bg-white/5'">
                        <span class="truncate">{{ $c->dayLabel() }}</span>
                        <span class="shrink-0 text-[11px] tabular-nums text-gray-600">{{ $c->message_count }}</span>
                    </a>
                @empty
                    <p class="px-3 py-2 text-xs text-gray-600">No chats yet — say something and today's chat starts.</p>
                @endforelse
            </nav>
        </aside>

        {{-- Chat panel --}}
        <section
            class="relative flex flex-col rounded-2xl border border-white/5 bg-white/[0.03] overflow-hidden min-h-0">

            {{-- Switching conversations: overlay anchored to the PANEL (not the scroll content, which
                 would scroll the loader off-screen on a long thread) so it always covers the view. --}}
            <div x-show="loadingChat" x-cloak x-transition.opacity
                 class="absolute inset-0 z-30 flex items-center justify-center bg-[#0c0e12]/85 backdrop-blur-sm">
                <div class="flex flex-col items-center gap-3">
                    <span class="relative grid h-12 w-12 place-items-center">
                        <span class="absolute inset-0 rounded-full bg-gradient-to-br from-indigo-500 to-cyan-400 opacity-30 animate-ping"></span>
                        <span class="relative grid h-12 w-12 place-items-center rounded-full bg-gradient-to-br from-indigo-500 to-cyan-400">
                            <svg class="h-6 w-6 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </span>
                    </span>
                    <span class="text-xs font-medium text-gray-400">Loading conversation…</span>
                </div>
            </div>

            {{-- Mobile: day picker + jump-to-today (collapses the sidebar) --}}
            <div class="lg:hidden flex items-center gap-2 p-2.5 border-b border-white/5"
                 x-data="{ open: false }" @click.outside="open = false">
                <div class="relative flex-1 min-w-0">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between gap-2 rounded-xl border border-white/10 bg-gray-950/40 h-11 px-3 text-sm text-gray-200 active:bg-white/5">
                        <span class="truncate" x-text="dayLabel"></span>
                        <svg class="h-4 w-4 shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top
                         class="absolute z-20 left-0 right-0 mt-1.5 max-h-72 overflow-y-auto rounded-xl border border-white/10 bg-gray-900 shadow-xl shadow-black/40 p-1.5 space-y-0.5">
                        @forelse ($conversations as $c)
                            <a href="/coach?c={{ $c->id }}"
                               @click.prevent="openChat({{ $c->id }}); open = false"
                               class="flex items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-sm transition"
                               :class="activeId === {{ $c->id }} ? 'bg-white/10 text-gray-100' : 'text-gray-300 active:bg-white/5'">
                                <span class="truncate">{{ $c->dayLabel() }}</span>
                                <span class="shrink-0 text-[11px] tabular-nums text-gray-600">{{ $c->message_count }}</span>
                            </a>
                        @empty
                            <p class="px-3 py-2 text-xs text-gray-600">No chats yet.</p>
                        @endforelse
                    </div>
                </div>
                <button type="button" @click="goToday()" title="Jump to today"
                        class="shrink-0 h-11 w-11 grid place-items-center rounded-xl bg-indigo-500/15 text-indigo-300 active:bg-indigo-500/25 transition">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M3 11h18M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>
                </button>
            </div>

            @if ($aiOffline)
                <div class="px-4 py-2 bg-amber-500/10 border-b border-amber-500/20 text-sm text-amber-300">
                    The coach is offline — the AI service isn't configured yet. You can still browse past chats.
                </div>
            @endif

            {{-- Messages --}}
            <div x-ref="scroll" class="relative flex-1 overflow-y-auto p-4 space-y-4 min-h-0">
                {{-- Loading older messages (scroll-up pagination) --}}
                <div x-show="loadingMore" x-cloak class="flex justify-center py-1.5">
                    <svg class="h-4 w-4 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"/></svg>
                </div>

                {{-- Empty state: greeting + starter prompts --}}
                <template x-if="messages.length === 0 && !loadingChat">
                    <div class="max-w-xl mx-auto text-center py-8">
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

                {{-- Which day you're reading. Sticks to the top so it stays answered while you scroll. --}}
                <div x-show="messages.length > 0" x-cloak class="sticky top-0 z-10 -mx-4 -mt-4 mb-1 px-4 py-2 bg-[#0c0e12]/80 backdrop-blur">
                    <p class="text-center text-[11px] font-medium uppercase tracking-wider text-gray-500" x-text="dayFull"></p>
                </div>

                {{-- Conversation --}}
                <template x-for="(m, i) in messages" :key="i">
                    <div :class="m.role === 'user' ? 'flex flex-col items-end' : 'flex flex-col items-start'">
                        {{-- A proactive push (briefing / sleep / workout / meal) — the coach spoke first,
                             so it's labelled rather than passed off as a reply to something you asked. --}}
                        <template x-if="m.kind">
                            <span class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-cyan-500/10 border border-cyan-400/20 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-cyan-300">
                                <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a6 6 0 00-6 6v3.6l-1.4 2.1A1 1 0 003.4 15h13.2a1 1 0 00.8-1.3L16 11.6V8a6 6 0 00-6-6zM8 17a2 2 0 004 0H8z"/></svg>
                                <span x-text="m.kind === 'briefing' ? 'Briefing' : 'Coach update'"></span>
                            </span>
                        </template>
                        <div :class="m.role === 'user'
                                ? 'max-w-[85%] rounded-2xl rounded-br-sm bg-indigo-500/20 border border-indigo-500/30 px-4 py-2.5 text-sm text-gray-100'
                                : (m.kind
                                    ? 'max-w-[85%] rounded-2xl rounded-bl-sm bg-cyan-500/[0.07] border border-cyan-400/15 px-4 py-2.5 text-sm text-gray-200'
                                    : 'max-w-[85%] rounded-2xl rounded-bl-sm bg-gray-800/60 border border-white/5 px-4 py-2.5 text-sm text-gray-200')">
                            {{-- Attached image (rendered natively — the markdown sanitizer strips blob: URLs) --}}
                            <template x-if="m.image">
                                <img :src="m.image" alt="Attached photo" loading="lazy"
                                     @click="window.openLightbox && window.openLightbox(m.image)"
                                     :class="m.content ? 'mb-2' : ''"
                                     class="max-h-72 w-full cursor-zoom-in rounded-xl border border-white/10 object-cover">
                            </template>
                            <div class="coach-prose leading-relaxed break-words" x-html="render(m.content)" x-show="m.content"></div>
                        </div>
                        {{-- When it was said — so a day you scroll back to reads as a timeline. --}}
                        <span x-show="m.at" x-cloak class="mt-1 px-1 text-[10px] tabular-nums text-gray-600" x-text="m.at"></span>
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

            {{-- A past day is a record, not a place to write: anything you send belongs to today. --}}
            <div x-show="readOnly" x-cloak class="border-t border-white/5 p-3">
                <button type="button" @click="goToday()"
                        class="w-full flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-gray-300 active:bg-white/10 transition">
                    <svg class="h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                    <span>You're reading <span class="text-gray-400" x-text="dayLabel"></span> — go to today to send</span>
                </button>
            </div>

            {{-- Composer — sits at the end of the flex column, above the bottom tab bar --}}
            <div x-show="!readOnly" class="border-t border-white/5 p-3">
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
                    {{-- Snap-to-log: attach a meal / bloodwork / body photo. No `capture` → the OS lets
                         you choose Photo Library *or* camera (capture forced the camera and hid the library). --}}
                    <input x-ref="photo" type="file" accept="image/*" class="hidden"
                           @change="if ($event.target.files[0]) { attachPhoto($event.target.files[0]); $event.target.value = ''; }">
                    <button type="button" @click="$refs.photo.click()" :disabled="loading"
                            title="Attach a meal, bloodwork or body photo"
                            class="shrink-0 h-12 w-12 grid place-items-center rounded-xl bg-white/5 border border-white/10 text-gray-300 active:bg-white/10 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            :class="pendingPhoto ? 'ring-2 ring-indigo-400/50 text-indigo-300' : ''">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </button>
                    {{-- Voice → text: record, watch the live waveform, transcribe into the input --}}
                    <button type="button" @click="toggleMic()" :disabled="loading || transcribing"
                            :title="recording ? 'Stop recording' : 'Record a voice message'"
                            class="shrink-0 h-12 w-12 grid place-items-center rounded-xl border transition disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="recording ? 'bg-rose-500/20 border-rose-400/50 text-rose-300' : 'bg-white/5 border-white/10 text-gray-300 active:bg-white/10'">
                        <svg x-show="!recording && !transcribing" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 003-3V6a3 3 0 10-6 0v6a3 3 0 003 3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-14 0M12 18v3"/></svg>
                        <svg x-show="recording" x-cloak class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2.5"/></svg>
                        <svg x-show="transcribing" x-cloak class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" stroke-opacity="0.25"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                    </button>
                    <div class="relative flex-1 min-w-0">
                        <textarea
                            x-ref="input"
                            x-model="draft"
                            @keydown.enter.prevent="if(!$event.shiftKey) send()"
                            rows="1"
                            :placeholder="pendingPhoto ? 'Add a note (optional)…' : 'Ask your coach…'"
                            class="w-full resize-none rounded-xl bg-gray-950/60 border border-white/10 focus:border-indigo-500/50 focus:ring-0 px-4 py-3 text-base text-gray-100 placeholder-gray-600 max-h-40"></textarea>
                        {{-- live recording waveform overlay --}}
                        <div x-show="recording" x-cloak @click="stopMic()"
                             class="absolute inset-0 flex items-center gap-3 px-4 rounded-xl bg-gray-950/95 border border-rose-400/40 cursor-pointer select-none">
                            <span class="shrink-0 h-2.5 w-2.5 rounded-full bg-rose-400 animate-pulse"></span>
                            <canvas x-ref="wave" class="flex-1 h-8 min-w-0"></canvas>
                            <span class="shrink-0 text-xs tabular-nums text-gray-400" x-text="recTime"></span>
                            <span class="shrink-0 text-xs font-medium text-rose-300">Tap to stop</span>
                        </div>
                        {{-- transcribing overlay --}}
                        <div x-show="transcribing" x-cloak
                             class="absolute inset-0 flex items-center gap-2 px-4 rounded-xl bg-gray-950/95 border border-indigo-400/30 text-sm text-indigo-300 select-none">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" stroke-opacity="0.25"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                            Transcribing your message…
                        </div>
                    </div>
                    <button type="submit" aria-label="Send message"
                            :disabled="loading || (!draft.trim() && !pendingPhoto)"
                            class="shrink-0 h-12 w-12 grid place-items-center rounded-xl bg-indigo-500 active:bg-indigo-400 disabled:opacity-40 disabled:cursor-not-allowed text-white transition">
                        <span x-show="!loading">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </span>
                        <span x-show="loading" x-cloak>…</span>
                    </button>
                </form>
                <p x-show="micError" x-cloak x-text="micError" @click="micError=''" class="mt-2 text-[11px] text-rose-300 cursor-pointer"></p>
                <p x-show="!micError" class="mt-2 text-[11px] text-gray-600">Coaching, not medical advice. For clinical concerns, see a doctor.</p>
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
                transcribeUrl: cfg.transcribeUrl,
                csrf: cfg.csrf,
                aiOffline: cfg.aiOffline,

                // ---- voice → text ----
                recording: false,      // mic is live
                transcribing: false,   // clip is being transcribed
                recSecs: 0,            // recording timer (seconds)
                micError: '',
                _mr: null, _chunks: [], _stream: null, _audioCtx: null, _raf: null, _timer: null,
                get recTime() { const s = this.recSecs; return Math.floor(s/60) + ':' + String(s%60).padStart(2,'0'); },

                activeId: cfg.conversationId || null,
                loadingChat: false,    // switching to another day
                loadingMore: false,    // fetching older messages (scroll up)
                hasMore: cfg.hasMore || false,
                oldestId: cfg.oldestId || null,

                // ---- which day is open ----
                dayLabel: cfg.dayLabel || 'Today',
                dayFull: cfg.dayFull || cfg.todayFull,
                todayFull: cfg.todayFull,
                readOnly: cfg.readOnly || false,   // a past day: readable, not writable
                tz: cfg.tz,

                // Stamp a just-sent message the way the server will stamp it on reload. Uses the APP
                // timezone, not the device's — otherwise a message reads one time now and a different
                // time after a refresh whenever the two zones disagree.
                nowLabel() {
                    try {
                        return new Date().toLocaleTimeString('en-US', { timeZone: this.tz, hour: 'numeric', minute: '2-digit' });
                    } catch (e) {
                        return new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                    }
                },

                showJump: false,

                init() {
                    this.$nextTick(() => { this.scrollDown(); this.enhance(); });
                    const el = this.$refs.scroll;
                    if (el) el.addEventListener('scroll', () => {
                        this.showJump = !this.nearBottom();
                        if (el.scrollTop < 80) this.loadOlder();
                    }, { passive: true });
                },

                // Switch to another day without a full page reload — load its latest page.
                async openChat(id) {
                    if (!id || id === this.activeId || this.loadingChat) return;
                    this.loadingChat = true; this.suggestions = []; this.draft = '';
                    try {
                        const res = await fetch('/coach/' + id + '/messages', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        if (!res.ok) throw new Error('load failed');
                        const d = await res.json();
                        this.messages = d.messages.map(m => ({ role: m.role, kind: m.kind, at: m.at, content: m.content }));
                        this.oldestId = d.oldest_id;
                        this.hasMore = d.has_more;
                        this.activeId = id;
                        this.dayLabel = d.day_label;
                        this.dayFull = d.day_full;
                        this.readOnly = d.read_only;
                        this.sendUrl = '/coach/' + id + '/send';
                        this.streamUrl = '/coach/' + id + '/stream';
                        this.scanUrl = '/coach/' + id + '/scan';
                        history.replaceState(null, '', '/coach?c=' + id);
                        this.$nextTick(() => { this.scrollDown(); this.enhance(); });
                    } catch (e) {
                        // Fall back to a full navigation if the AJAX load fails.
                        window.location = '/coach?c=' + id;
                    }
                    this.loadingChat = false;
                },

                // Back to today's chat. A full navigation, because today may not exist as a row yet
                // (an empty day is created on the first message, not on page view) — and the server is
                // the only thing that knows which row that is.
                goToday() {
                    if (!this.readOnly && this.dayLabel === 'Today') { this.scrollDown(); return; }
                    window.location = '/coach';
                },

                // Prepend the previous page of messages when the user scrolls to the top.
                async loadOlder() {
                    if (!this.hasMore || this.loadingMore || this.loadingChat || !this.activeId || !this.oldestId) return;
                    this.loadingMore = true;
                    const el = this.$refs.scroll;
                    const prevHeight = el ? el.scrollHeight : 0;
                    try {
                        const res = await fetch('/coach/' + this.activeId + '/messages?before=' + this.oldestId, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        const d = await res.json();
                        const older = d.messages.map(m => ({ role: m.role, kind: m.kind, at: m.at, content: m.content }));
                        if (older.length) {
                            this.messages = older.concat(this.messages);
                            this.oldestId = d.oldest_id || this.oldestId;
                            this.hasMore = d.has_more;
                            // Keep the viewport anchored where the user was reading.
                            this.$nextTick(() => { if (el) el.scrollTop = el.scrollHeight - prevHeight; this.enhance(); });
                        } else {
                            this.hasMore = false;
                        }
                    } catch (e) { /* leave hasMore so they can retry by scrolling */ }
                    this.loadingMore = false;
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
                    this.activeId = id;
                    history.replaceState(null, '', '/coach?c=' + id);
                },

                // Downscale + re-encode to JPEG before upload. iPhones shoot 12MP HEIC/JPEG (3–8 MB);
                // vision models only need ~1600px, so this cuts the file to a few hundred KB AND converts
                // HEIC → JPEG (the backend's `image` rule + the vision API reject HEIC). Falls back to the
                // original file if anything in the canvas path fails (e.g. desktop browser can't decode HEIC).
                async compressImage(file, maxDim = 1600, quality = 0.82) {
                    if (!file || !file.type || !file.type.startsWith('image/')) return file;
                    try {
                        let src = null, w = 0, h = 0;
                        // Prefer createImageBitmap (fast, off-thread); fall back to <img> for broader format support.
                        try {
                            src = await createImageBitmap(file);
                            w = src.width; h = src.height;
                        } catch (_) {
                            const url = URL.createObjectURL(file);
                            try {
                                src = await new Promise((res, rej) => {
                                    const im = new Image();
                                    im.onload = () => res(im); im.onerror = rej; im.src = url;
                                });
                                w = src.naturalWidth; h = src.naturalHeight;
                            } finally { URL.revokeObjectURL(url); }
                        }
                        if (!w || !h) return file;
                        const scale = Math.min(1, maxDim / Math.max(w, h));
                        const cw = Math.round(w * scale), ch = Math.round(h * scale);
                        const canvas = document.createElement('canvas');
                        canvas.width = cw; canvas.height = ch;
                        canvas.getContext('2d').drawImage(src, 0, 0, cw, ch);
                        if (src.close) src.close();
                        const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', quality));
                        if (!blob) return file;
                        // Don't upsize a small original — keep whichever is smaller.
                        if (blob.size >= file.size && /jpe?g/i.test(file.type)) return file;
                        return new File([blob], 'photo.jpg', { type: 'image/jpeg' });
                    } catch (_) {
                        return file;
                    }
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
                    const original = this.pendingPhoto;
                    if (!original || this.loading) return;
                    const caption = this.draft.trim();
                    const preview = this.pendingPreview;   // the bubble keeps this object URL
                    this.suggestions = [];
                    // Render the photo natively in the bubble via `image` — markdown's sanitizer drops blob: URLs.
                    this.messages.push({ role: 'user', at: this.nowLabel(), content: caption, image: preview });
                    this.draft = '';
                    this.pendingPhoto = null;
                    this.pendingPreview = '';          // ownership passed to the bubble; don't revoke it
                    this.loading = true;
                    this.streaming = false;
                    this.toolStatus = 'Reading your photo';
                    this.$nextTick(() => { this.enhance(); this.scrollDown(); });

                    // Shrink + convert to JPEG so it goes over the wire fast and the vision API can read it.
                    const file = await this.compressImage(original);
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
                        this.messages.push({ role: 'assistant', at: this.nowLabel(), content: (data && data.reply) || "Couldn't read that photo — try again." });
                        this.$nextTick(() => { this.enhance(); this.scrollDown(); });
                        if (data && data.conversation_id) this.bindConversation(data.conversation_id);
                    } catch (e) {
                        this.messages.push({ role: 'assistant', at: this.nowLabel(), content: "Couldn't upload that photo. Check your connection and try again." });
                        this.$nextTick(() => { this.enhance(); this.scrollDown(); });
                    } finally {
                        this.finishSend();
                    }
                },

                // ---- Voice → text: record a clip, show a live waveform, transcribe, drop into the input ----
                async toggleMic() {
                    if (this.recording) { this.stopMic(); return; }
                    if (this.transcribing || this.loading) return;
                    this.micError = '';
                    if (!navigator.mediaDevices || !window.MediaRecorder) { this.micError = 'Voice input isn’t supported on this browser.'; return; }
                    try {
                        this._stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    } catch (e) {
                        this.micError = 'Microphone access was blocked. Allow it and try again.';
                        return;
                    }
                    this._chunks = [];
                    const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find(t => window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) || '';
                    try {
                        this._mr = new MediaRecorder(this._stream, mime ? { mimeType: mime } : {});
                    } catch (e) { this._mr = new MediaRecorder(this._stream); }
                    this._mr.ondataavailable = (e) => { if (e.data && e.data.size) this._chunks.push(e.data); };
                    this._mr.onstop = () => this.onRecStop();
                    this._mr.start();
                    this.recording = true;
                    this.recSecs = 0;
                    this._timer = setInterval(() => {
                        this.recSecs++;
                        if (this.recSecs >= 120) this.stopMic();   // safety cap at 2 min
                    }, 1000);
                    this.startWave();
                },

                stopMic() {
                    if (this._mr && this._mr.state !== 'inactive') { try { this._mr.stop(); } catch (e) {} }
                    this.recording = false;
                    if (this._timer) { clearInterval(this._timer); this._timer = null; }
                    this.stopWave();
                },

                startWave() {
                    try {
                        this._audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                        const analyser = this._audioCtx.createAnalyser();
                        analyser.fftSize = 256; analyser.smoothingTimeConstant = 0.75;
                        this._audioCtx.createMediaStreamSource(this._stream).connect(analyser);
                        const data = new Uint8Array(analyser.frequencyBinCount);
                        const BARS = 28;
                        const draw = () => {
                            this._raf = requestAnimationFrame(draw);
                            const canvas = this.$refs.wave;
                            if (!canvas) return;
                            analyser.getByteFrequencyData(data);
                            const dpr = Math.min(window.devicePixelRatio || 1, 2);
                            const w = canvas.width = canvas.clientWidth * dpr;
                            const h = canvas.height = canvas.clientHeight * dpr;
                            const ctx = canvas.getContext('2d');
                            ctx.clearRect(0, 0, w, h);
                            const step = Math.floor(data.length / BARS), bw = w / BARS;
                            for (let i = 0; i < BARS; i++) {
                                const v = (data[i * step] || 0) / 255;
                                const bh = Math.max(3 * dpr, v * h);
                                const x = i * bw + bw * 0.25;
                                const g = ctx.createLinearGradient(0, (h - bh) / 2, 0, (h + bh) / 2);
                                g.addColorStop(0, 'rgba(129,140,248,' + (0.55 + v * 0.45) + ')');
                                g.addColorStop(1, 'rgba(34,211,238,' + (0.55 + v * 0.45) + ')');
                                ctx.fillStyle = g;
                                ctx.fillRect(x, (h - bh) / 2, bw * 0.5, bh);
                            }
                        };
                        draw();
                    } catch (e) { /* waveform is cosmetic — recording still works */ }
                },

                stopWave() {
                    if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
                    if (this._audioCtx) { try { this._audioCtx.close(); } catch (e) {} this._audioCtx = null; }
                },

                async onRecStop() {
                    if (this._stream) { this._stream.getTracks().forEach(t => t.stop()); this._stream = null; }
                    const type = (this._chunks[0] && this._chunks[0].type) || 'audio/webm';
                    const blob = new Blob(this._chunks, { type });
                    this._chunks = [];
                    if (!blob.size) return;
                    this.transcribing = true;
                    const ext = type.includes('mp4') ? 'mp4' : type.includes('ogg') ? 'ogg' : 'webm';
                    const fd = new FormData();
                    fd.append('audio', blob, 'voice.' + ext);
                    try {
                        const res = await fetch(this.transcribeUrl, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            body: fd,
                        });
                        const data = await res.json();
                        if (data && data.ok && data.text) {
                            this.draft = (this.draft.trim() ? this.draft.trim() + ' ' : '') + data.text;
                            this.$nextTick(() => { if (this.$refs.input) { this.$refs.input.focus(); this.$refs.input.setSelectionRange(this.draft.length, this.draft.length); } });
                        } else {
                            this.micError = (data && data.error) || 'Couldn’t transcribe that — try again.';
                        }
                    } catch (e) {
                        this.micError = 'Transcription failed. Check your connection and try again.';
                    } finally {
                        this.transcribing = false;
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

                    this.messages.push({ role: 'user', at: this.nowLabel(), content: text });
                    this.draft = '';
                    this.suggestions = [];
                    this.loading = true;
                    this.streaming = false;
                    this.toolStatus = '';
                    this.$nextTick(() => this.scrollDown());

                    // The assistant bubble we stream into — created lazily on the first token.
                    let idx = null;
                    const target = () => { if (idx === null) idx = this.messages.push({ role: 'assistant', at: this.nowLabel(), content: '' }) - 1; return idx; };

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
                        this.messages.push({ role: 'assistant', at: this.nowLabel(), content: "Couldn't stream a reply — please try again." });
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
                            this.messages.push({ role: 'assistant', at: this.nowLabel(), content: "Couldn't reach your coach. Check your connection and try again." });
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
                        this.messages.push({ role: 'assistant', at: this.nowLabel(), content: reply });
                        this.$nextTick(() => { this.enhance(); if (wasNear) this.scrollDown(); else this.showJump = true; });
                        if (data && data.conversation_id) this.bindConversation(data.conversation_id);
                    } catch (e) {
                        this.messages.push({ role: 'assistant', at: this.nowLabel(), content: "Couldn't reach your coach. Check your connection and try again." });
                        this.$nextTick(() => { this.enhance(); this.scrollDown(); });
                    }
                },
            };
        }
    </script>
</x-chat-shell>
