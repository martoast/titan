<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Funnels a brand-new user through the onboarding wizard before they can use the app.
 * If their profile hasn't been onboarded yet, every gated route redirects to /onboarding.
 * The onboarding routes themselves run OUTSIDE this middleware, so there's no loop.
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->ensureProfile()->isOnboarded()) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
