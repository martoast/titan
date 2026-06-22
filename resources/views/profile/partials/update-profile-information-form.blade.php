@php
    $inputClass = 'mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:ring-0';
    $labelClass = 'block text-xs font-medium text-gray-500';
@endphp
<section>
    <header>
        <h2 class="font-display font-bold text-gray-100">{{ __('Profile Information') }}</h2>
        <p class="mt-0.5 text-sm text-gray-400">{{ __("Update your account's name and email.") }}</p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-5 space-y-4">
        @csrf
        @method('patch')

        <div>
            <label for="name" class="{{ $labelClass }}">{{ __('Name') }}</label>
            <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name" class="{{ $inputClass }}">
            @error('name')<p class="mt-1.5 text-xs text-rose-300">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="email" class="{{ $labelClass }}">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username" class="{{ $inputClass }}">
            @error('email')<p class="mt-1.5 text-xs text-rose-300">{{ $message }}</p>@enderror

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <p class="mt-2 text-sm text-gray-400">
                    {{ __('Your email address is unverified.') }}
                    <button form="send-verification" class="font-medium text-indigo-300 underline underline-offset-2 active:text-indigo-200">{{ __('Re-send verification email.') }}</button>
                </p>
                @if (session('status') === 'verification-link-sent')
                    <p class="mt-2 text-sm font-medium text-emerald-300">{{ __('A new verification link has been sent.') }}</p>
                @endif
            @endif
        </div>

        <div class="flex items-center gap-4 pt-1">
            <button type="submit" class="h-11 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-6 font-semibold text-white active:opacity-90 transition">{{ __('Save') }}</button>
            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)" class="text-sm text-emerald-300">{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
