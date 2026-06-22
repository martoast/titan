<section x-data="{ open: {{ $errors->userDeletion->isNotEmpty() ? 'true' : 'false' }} }" @keydown.escape.window="open = false">
    <header>
        <h2 class="font-display font-bold text-rose-200">{{ __('Delete Account') }}</h2>
        <p class="mt-0.5 text-sm text-gray-400">{{ __('Deleting your account permanently removes all of its data. Download anything you want to keep first.') }}</p>
    </header>

    <button type="button" @click="open = true"
            class="mt-4 h-11 rounded-xl bg-rose-500/90 px-5 text-sm font-semibold text-white active:bg-rose-500 transition">
        {{ __('Delete Account') }}
    </button>

    {{-- Confirm modal --}}
    <div x-show="open" x-cloak x-transition.opacity @click="open = false" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm"></div>
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-3" x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed inset-x-4 top-1/2 z-50 mx-auto max-w-md -translate-y-1/2 rounded-2xl border border-white/10 bg-[#0c0e12] p-5 shadow-2xl shadow-black/60">
        <h3 class="font-display text-lg font-bold text-gray-100">{{ __('Delete your account?') }}</h3>
        <p class="mt-1.5 text-sm text-gray-400">{{ __('This permanently deletes all your data. Enter your password to confirm.') }}</p>

        <form method="post" action="{{ route('profile.destroy') }}" class="mt-4">
            @csrf
            @method('delete')
            <label for="password" class="sr-only">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" placeholder="{{ __('Password') }}"
                   class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 placeholder-gray-600 focus:border-rose-500 focus:ring-0">
            @foreach ($errors->userDeletion->get('password') as $message)<p class="mt-1.5 text-xs text-rose-300">{{ $message }}</p>@endforeach

            <div class="mt-5 flex items-center justify-end gap-3">
                <button type="button" @click="open = false" class="h-11 rounded-xl border border-white/10 bg-white/5 px-5 text-sm font-medium text-gray-200 active:bg-white/10">{{ __('Cancel') }}</button>
                <button type="submit" class="h-11 rounded-xl bg-rose-500/90 px-5 text-sm font-semibold text-white active:bg-rose-500">{{ __('Delete Account') }}</button>
            </div>
        </form>
    </div>
</section>
