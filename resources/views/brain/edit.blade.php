@php $isNew = ! $page->exists; @endphp
<x-titan-layout :title="$isNew ? 'New brain page' : 'Edit: '.$page->title" subtitle="Brain page editor">

    <div class="mb-5">
        <a href="{{ $isNew ? '/brain' : '/brain/'.$page->slug }}" class="text-sm text-gray-500 hover:text-gray-300">&larr; Cancel</a>
    </div>

    <form method="POST" action="{{ $isNew ? '/brain' : '/brain/'.$page->slug }}" class="max-w-3xl">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="rounded-2xl border border-white/5 bg-gray-900/50 p-6 space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1">Title</label>
                <input type="text" name="title" value="{{ old('title', $page->title) }}" required
                       class="w-full rounded-lg bg-gray-950/50 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500/50 focus:outline-none" />
                @error('title')<p class="text-xs text-amber-300 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Type</label>
                    <select name="type" class="rounded-lg bg-gray-950/50 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500/50 focus:outline-none">
                        @foreach (\App\Models\KnowledgePage::TYPES as $t)
                            <option value="{{ $t }}" @selected(old('type', $page->type) === $t)>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-300 pb-2">
                    <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned', $page->is_pinned))
                           class="rounded border-white/20 bg-gray-950 text-indigo-500 focus:ring-indigo-500/50" />
                    Pinned (core memory — always given to the coach)
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1">Content <span class="text-gray-600 font-normal">(Markdown · use [[Page Title]] to link)</span></label>
                <textarea name="content" rows="16"
                          class="w-full rounded-lg bg-gray-950/50 border border-white/10 px-3 py-2 text-sm font-mono text-gray-100 focus:border-indigo-500/50 focus:outline-none">{{ old('content', $page->content) }}</textarea>
                @error('content')<p class="text-xs text-amber-300 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="rounded-lg bg-indigo-500/15 border border-indigo-500/30 px-5 py-2 text-sm font-medium text-indigo-300 hover:bg-indigo-500/25 transition">
                    {{ $isNew ? 'Create page' : 'Save changes' }}
                </button>
                <a href="{{ $isNew ? '/brain' : '/brain/'.$page->slug }}" class="rounded-lg bg-white/5 border border-white/10 px-5 py-2 text-sm font-medium text-gray-300 hover:bg-white/10 transition">Cancel</a>
            </div>
        </div>
    </form>

</x-titan-layout>
