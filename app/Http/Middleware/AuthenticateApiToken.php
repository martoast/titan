<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless bearer-token auth for the assistant/MCP API. Resolves `Authorization: Bearer titan_…`
 * to its owning user (no session), enforces an optional ability, and stamps last-used. Use as
 * `auth.token` or `auth.token:write` on api routes.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['error' => 'missing_token', 'message' => 'Provide a Bearer API token.'], 401);
        }

        $token = ApiToken::where('token_hash', hash('sha256', $bearer))->first();
        if (! $token || ! $token->user) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        if ($ability && ! $token->can($ability)) {
            return response()->json(['error' => 'insufficient_ability', 'required' => $ability], 403);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        // Make $request->user() / auth()->user() resolve to the token owner for this request only.
        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
