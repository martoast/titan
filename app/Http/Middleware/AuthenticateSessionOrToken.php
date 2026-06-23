<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts EITHER a logged-in web session (the browser/Blade UI) OR a personal-API-token bearer
 * (the native iOS app) — so one set of routes (device management, coach) serves both clients
 * without duplication. Bearer wins if present; otherwise falls back to the web guard.
 *
 * Register as `auth.any` (optionally `auth.any:write` to require the token's 'write' ability;
 * session users are full owners and bypass the ability gate). Differs from `auth.token`, which is
 * bearer-ONLY (the MCP surface).
 */
class AuthenticateSessionOrToken
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        if ($bearer = $request->bearerToken()) {
            $token = ApiToken::where('token_hash', hash('sha256', $bearer))->first();
            if (! $token || ! $token->user) {
                return response()->json(['error' => 'invalid_token'], 401);
            }
            if ($ability && ! $token->can($ability)) {
                return response()->json(['error' => 'insufficient_scope', 'required' => $ability], 403);
            }
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
            Auth::setUser($token->user);
            $request->setUserResolver(fn () => $token->user);
            $request->attributes->set('api_token', $token);

            return $next($request);
        }

        if ($user = Auth::guard('web')->user()) {
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);

            return $next($request);
        }

        return response()->json(['error' => 'unauthenticated'], 401);
    }
}
