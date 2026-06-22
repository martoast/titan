@php
    $inputClass = 'mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:ring-0';
    $labelClass = 'block text-xs font-medium text-gray-500';
@endphp
<section>
    <header>
        <h2 class="font-display font-bold text-gray-100">{{ __('Update Password') }}</h2>
        <p class="mt-0.5 text-sm text-gray-400">{{ __('Use a long, random password to stay secure.') }}</p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-5 space-y-4">
        @csrf
        @method('put')

        <div>
            <label for="update_password_current_password" class="{{ $labelClass }}">{{ __('Current Password') }}</label>
            <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password" class="{{ $inputClass }}">
            @foreach ($errors->updatePassword->get('current_password') as $message)<p class="mt-1.5 text-xs text-rose-300">{{ $message }}</p>@endforeach
        </div>

        <div>
            <label for="update_password_password" class="{{ $labelClass }}">{{ __('New Password') }}</label>
            <input id="update_password_password" name="password" type="password" autocomplete="new-password" class="{{ $inputClass }}">
            @foreach ($errors->updatePassword->get('password') as $message)<p class="mt-1.5 text-xs text-rose-300">{{ $message }}</p>@endforeach
        </div>

        <div>
            <label for="update_password_password_confirmation" class="{{ $labelClass }}">{{ __('Confirm Password') }}</label>
            <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="{{ $inputClass }}">
            @foreach ($errors->updatePassword->get('password_confirmation') as $message)<p class="mt-1.5 text-xs text-rose-300">{{ $message }}</p>@endforeach
        </div>

        <div class="flex items-center gap-4 pt-1">
            <button type="submit" class="h-11 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-6 font-semibold text-white active:opacity-90 transition">{{ __('Save') }}</button>
            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)" class="text-sm text-emerald-300">{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
