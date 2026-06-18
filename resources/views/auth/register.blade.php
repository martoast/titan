<x-guest-layout title="Create your account — Titan">
    <div class="auth-head">
        <h1>Create your account</h1>
        <p>Start understanding your body today. Free to begin.</p>
    </div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="field">
            <label class="auth-label" for="name">Name</label>
            <input class="auth-input" id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" placeholder="Your name">
            @error('name') <p class="auth-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="auth-label" for="email">Email</label>
            <input class="auth-input" id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="you@example.com">
            @error('email') <p class="auth-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="auth-label" for="password">Password</label>
            <input class="auth-input" id="password" type="password" name="password" required autocomplete="new-password" placeholder="Create a password">
            @error('password') <p class="auth-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="auth-label" for="password_confirmation">Confirm password</label>
            <input class="auth-input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Repeat your password">
            @error('password_confirmation') <p class="auth-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="auth-submit" style="margin-top:0.4rem;">Create account</button>
    </form>

    <p class="auth-foot">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
</x-guest-layout>
