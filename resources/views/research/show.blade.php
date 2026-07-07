<x-titan-layout :title="$title" subtitle="Research brief">
    <div class="max-w-2xl">
        <a href="/research" class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-gray-100">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Research library
        </a>
        <article class="glass-card p-5 sm:p-6">
            <div class="coach-prose research-prose">{!! $html !!}</div>
            <p class="mt-6 border-t border-white/5 pt-3 text-xs text-gray-600">Saved {{ $date }}</p>
        </article>
    </div>
</x-titan-layout>
