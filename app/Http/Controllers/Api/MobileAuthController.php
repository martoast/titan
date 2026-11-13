<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\PushToken;
use App\Models\User;
use App\Services\Account\DeleteAccount;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Stateless auth for the native iOS app. Reuses the existing personal-API-token system
 * ({@see ApiToken}, `auth.token` middleware) rather than adding Sanctum — the native client
 * logs in with email/password once and stores the returned bearer token in the iOS Keychain,
 * then calls the `auth.token` API routes with it. See tasks/native-ios/03-architecture.md.
 */
class MobileAuthController extends Controller
{
    /**
     * POST /api/login — exchange email + password for a bearer token.
     * Body: { email, password, device_name? }. Returns { token, user }.
     */
    public function login(Request $request): JsonResponse
    {
        // Same fold as register: a phone's autocapitalise must never read as a wrong password.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Constant-ish failure (don't leak whether the email exists).
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $device = $data['device_name'] ?? 'iOS app';
        [, $plain] = ApiToken::mint($user, "ios:{$device}", ['*']);

        return response()->json([
            'token' => $plain,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'onboarded' => $user->ensureProfile()->isOnboarded(),
            ],
        ]);
    }

    /**
     * POST /api/register — create an account and return a bearer token, so the app can go
     * straight into onboarding without a second round trip.
     * Body: { name, email, password, device_name? }. Returns { token, user }.
     *
     * This exists because until 2026-11-13 the native app had NO way to create an account:
     * LoginView offered only "Sign in" and there was no register route on the API, so anybody
     * who installed Titan from the App Store had to find the website on their own. Apple does
     * not require in-app sign-up, which is why review passed — but a downloader who cannot get
     * in is the whole funnel.
     *
     * `onboarded` comes back false, which is what sends the app into OnboardingFlow.
     */
    public function register(Request $request): JsonResponse
    {
        // Phones capitalise the first letter of a text field, so a real person types
        // "Alex@gmail.com" constantly. The web form's `lowercase` rule REJECTS that with "the
        // email field must be lowercase" — a baffling 422 on somebody's first ever action — so
        // the address is folded first and the rule then only guards against anything exotic.
        // Folding also keeps one address from making two accounts that differ by case.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', Rules\Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Same event the web form fires, so any listener added later treats both doors alike.
        // It sends no mail today: User deliberately does not implement MustVerifyEmail.
        event(new Registered($user));

        $device = $data['device_name'] ?? 'iOS app';
        [, $plain] = ApiToken::mint($user, "ios:{$device}", ['*']);

        return response()->json([
            'token' => $plain,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'onboarded' => $user->ensureProfile()->isOnboarded(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/me/account — erase the account and everything personal on it (auth.token).
     * Body: { password } — re-authentication, because this cannot be undone and a stolen phone
     * must not be able to wipe somebody's history.
     *
     * Required by App Store Guideline 5.1.1(v) now that the app can create accounts: deletion
     * must be reachable inside the app and must remove the record, not deactivate it.
     */
    public function destroyAccount(Request $request, DeleteAccount $deleter): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['That password is not correct.'],
            ]);
        }

        $result = $deleter->run($user);

        return response()->json([
            'ok' => true,
            'deleted' => true,
            'user_id' => $result['user_id'],
        ]);
    }

    /**
     * POST /api/logout — revoke the bearer token used for this request (auth.token group).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');
        if ($token instanceof ApiToken) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/devices/push-token — register/refresh this device's APNs token (auth.token group).
     * Body: { token, platform?, environment? }. Idempotent on (platform, token).
     */
    public function pushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'in:ios,android'],
            'environment' => ['nullable', 'in:production,sandbox'],
        ]);

        $platform = $data['platform'] ?? 'ios';

        $row = PushToken::updateOrCreate(
            ['platform' => $platform, 'token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'environment' => $data['environment'] ?? 'production',
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['ok' => true, 'id' => $row->id]);
    }
}
