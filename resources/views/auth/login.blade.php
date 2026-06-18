<x-guest-layout title="Sign in — Titan">
    <div class="auth-head">
        <h1>Welcome back</h1>
        <p>Sign in to pick up where your body left off.</p>
    </div>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="field">
            <label class="auth-label" for="email">Email</label>
            <input class="auth-input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="you@example.com">
            @error('email') <p class="auth-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="auth-label" for="password">Password</label>
            <input class="auth-input" id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
            @error('password') <p class="auth-error">{{ $message }}</p> @enderror
        </div>

        <div class="auth-row">
            <label class="checkbox"><input type="checkbox" name="remember"> Remember me</label>
            @if (Route::has('password.request'))
                <a class="link" href="{{ route('password.request') }}">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="auth-submit">Sign in</button>
    </form>

    <p class="auth-foot">New to Titan? <a href="{{ route('register') }}">Create an account</a></p>
</x-guest-layout>
