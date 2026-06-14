@php $isNew = ! $page->exists; @endphp
<x-titan-layout :title="$isNew ? 'New brain page' : 'Edit: '.$page->title" subtitle="Brain page editor">

    <div class="mb-4">
        <a href="{{ $isNew ? '/brain' : '/brain/'.$page->slug }}" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-300 active:text-gray-200">&larr; Cancel</a>
    </div>

    <form method="POST" action="{{ $isNew ? '/brain' : '/brain/'.$page->slug }}" class="max-w-3xl">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-6 space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">Title</label>
                <input type="text" name="title" value="{{ old('title', $page->title) }}" required
                       class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500/50 focus:outline-none" />
                @error('title')<p class="text-xs text-amber-300 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="space-y-4 sm:space-y-0 sm:flex sm:items-end sm:gap-4">
                <div class="w-full sm:w-auto">
                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Type</label>
                    <select name="type" class="w-full sm:w-auto h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                        @foreach (\App\Models\KnowledgePage::TYPES as $t)
                            <option value="{{ $t }}" @selected(old('type', $page->type) === $t)>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2.5 text-sm text-gray-300 sm:pb-2.5">
                    <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned', $page->is_pinned))
                           class="h-5 w-5 shrink-0 rounded border-white/20 bg-gray-950 text-indigo-500 focus:ring-indigo-500/50" />
                    Pinned (core memory — always given to the coach)
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">Content <span class="text-gray-600 font-normal">(Markdown · use [[Page Title]] to link)</span></label>
                <textarea name="content" rows="16"
                          class="w-full rounded-xl bg-gray-950 border border-white/10 px-3 py-3 text-base font-mono text-gray-100 focus:border-indigo-500/50 focus:outline-none">{{ old('content', $page->content) }}</textarea>
                @error('content')<p class="text-xs text-amber-300 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 gap-2 sm:flex sm:items-center">
                <button type="submit" class="w-full sm:w-auto h-12 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-6 text-sm font-semibold text-white transition active:opacity-90">
                    {{ $isNew ? 'Create page' : 'Save changes' }}
                </button>
                <a href="{{ $isNew ? '/brain' : '/brain/'.$page->slug }}" class="w-full sm:w-auto h-12 grid place-items-center rounded-xl bg-white/5 border border-white/10 px-6 text-sm font-semibold text-gray-300 transition hover:bg-white/10 active:bg-white/[0.14]">Cancel</a>
            </div>
        </div>
    </form>

</x-titan-layout>
