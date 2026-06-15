<x-titan-layout title="Notifications" subtitle="Briefings, nudges & recovery alerts">
    <div class="mx-auto max-w-2xl" x-data="notificationCenter()">
        {{-- Header actions --}}
        <div class="flex items-center justify-between mb-4">
            <div class="text-sm text-gray-400">
                <span x-show="unread > 0" x-text="unread + ' unread'"></span>
                <span x-show="unread === 0" x-cloak>All caught up</span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="enablePush()" x-show="canPrompt" x-cloak
                        class="rounded-xl bg-indigo-500/15 text-indigo-200 text-xs font-semibold px-3 py-2 active:bg-indigo-500/25 transition">
                    Enable push
                </button>
                <button type="button" @click="markAllRead()"
                        class="rounded-xl bg-white/5 text-gray-300 text-xs font-semibold px-3 py-2 active:bg-white/10 transition">
                    Mark all read
                </button>
            </div>
        </div>

        @if ($notifications->isEmpty())
            <div class="rounded-2xl border border-white/5 bg-white/[0.02] px-6 py-16 text-center">
                <div class="mx-auto h-12 w-12 rounded-2xl bg-white/5 flex items-center justify-center mb-4">
                    <svg class="h-6 w-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                </div>
                <p class="text-gray-300 font-medium">No notifications yet</p>
                <p class="text-gray-500 text-sm mt-1">Your morning briefing, evening nudge and recovery alerts will land here.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($notifications as $n)
                    <a href="{{ $n->url ?? '#' }}"
                       @if (! $n->url) @click.prevent="markRead({{ $n->id }}, $el)" @else @click="markRead({{ $n->id }}, $el)" @endif
                       data-notif="{{ $n->id }}"
                       class="block rounded-2xl border px-4 py-3.5 transition active:scale-[0.99]
                              {{ $n->read_at ? 'border-white/5 bg-white/[0.02]' : 'border-indigo-500/20 bg-indigo-500/[0.06]' }}">
                        <div class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-transparent' : 'bg-indigo-400' }}" data-dot></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="font-display font-semibold text-gray-100 truncate">{{ $n->title }}</p>
                                    <span class="shrink-0 text-[11px] text-gray-500">{{ $n->created_at?->diffForHumans(short: true) }}</span>
                                </div>
                                <p class="text-sm text-gray-400 mt-0.5 line-clamp-3">{{ $n->body }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-titan-layout>
