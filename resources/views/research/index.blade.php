<x-titan-layout title="Research library" subtitle="Every deep dive your coach has written">
    <div class="max-w-2xl space-y-3">
        @forelse ($briefs as $b)
            <a href="/research/{{ $b['id'] }}"
               class="block glass-card p-4 transition hover:border-white/15 hover:bg-white/[0.05]">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-indigo-500/15 text-indigo-300">📚</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-3">
                            <h2 class="truncate font-display text-base font-bold text-gray-100">{{ $b['title'] }}</h2>
                            <span class="shrink-0 text-[0.7rem] text-gray-600">{{ $b['date'] }}</span>
                        </div>
                        <p class="mt-1 text-sm leading-snug text-gray-500">{{ $b['excerpt'] }}</p>
                    </div>
                </div>
            </a>
        @empty
            <div class="glass-card p-6 text-center">
                <p class="font-display text-lg font-bold text-gray-100">No research yet</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-gray-400 leading-relaxed">
                    Ask your coach to <em>research</em> anything — a training style, a supplement, a diet — and the
                    full brief lands here, saved forever.
                </p>
                <a href="/coach" class="mt-3 inline-block rounded-xl bg-indigo-500 px-4 py-2.5 text-sm font-bold text-white">Open the coach</a>
            </div>
        @endforelse
    </div>
</x-titan-layout>
