<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // EVERY request arrives through the Cloudflare tunnel, which connects to host Caddy
        // on 127.0.0.1 — so without this, $request->ip() is 127.0.0.1 for every visitor on
        // earth and `throttle:10,1` on /api/login is ONE GLOBAL BUCKET: ten attempts a minute
        // across all users and the eleventh real person gets a 429. Nothing else can reach
        // the app's port (it binds 127.0.0.1 behind Caddy), so trusting any proxy is safe.
        // Caddy's :3010 block must keep `header_up X-Forwarded-Proto https`, or Laravel
        // believes Caddy's own plain-HTTP scheme and every generated URL becomes http://.
        $middleware->trustProxies(at: '*');

        // Personal-API-token auth for the assistant/MCP surface (stateless bearer tokens).
        $middleware->alias([
            'auth.token' => \App\Http\Middleware\AuthenticateApiToken::class,
            'auth.any' => \App\Http\Middleware\AuthenticateSessionOrToken::class,
            'onboarded' => \App\Http\Middleware\EnsureOnboarded::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
