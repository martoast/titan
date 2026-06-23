<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            ],
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
