<?php

/*
 * Titan API routes. Stateless, no session/CSRF (they run on the `api` middleware
 * group). Registered in bootstrap/app.php via withRouting(api: …).
 *
 * The device ingestion endpoint authenticates each request with a per-device HMAC
 * signature (X-Device-Id + X-Titan-Signature) — NOT web auth — so it lives outside
 * the auth group. The management endpoints (pair / revoke / catch-up) are operated by
 * the logged-in owner and run under `auth`.
 */

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\DeviceIngestionController;
use App\Http\Controllers\Api\MobileAuthController;
use Illuminate\Support\Facades\Route;

// Native iOS app auth — email/password → bearer token (reuses the ApiToken system). Throttled.
Route::post('/login', [MobileAuthController::class, 'login'])->middleware('throttle:10,1');

// Assistant / MCP surface + native-app session — personal-API-token auth (Bearer). One generic
// tool dispatcher gives an external agent full account control; write tools additionally require
// the token's 'write' ability. The iOS app authenticates here with its stored bearer token.
Route::middleware('auth.token')->group(function () {
    Route::get('/me', [AssistantController::class, 'me']);
    Route::get('/tools', [AssistantController::class, 'tools']);
    Route::post('/tool', [AssistantController::class, 'call']);

    // Native-app session management + push registration.
    Route::post('/logout', [MobileAuthController::class, 'logout']);
    Route::post('/devices/push-token', [MobileAuthController::class, 'pushToken']);
});

// Read-only dashboard aggregate for the native app (readiness + recovery + sleep + activity).
Route::middleware('auth.any')->get('/me/dashboard', [\App\Http\Controllers\Api\MobileDashboardController::class, 'dashboard']);
Route::middleware('auth.any')->get('/me/trends', [\App\Http\Controllers\Api\MobileDashboardController::class, 'trends']);

Route::prefix('devices')->group(function () {
    // Device → server: signed biosignal batches (HMAC auth, no session).
    Route::post('/ingest', [DeviceIngestionController::class, 'ingest']);

    // Server → device: the active activity to sense for (HMAC auth). The band polls this
    // on connection so the coach's "starting a run" can switch it into the right mode.
    Route::get('/activity', [DeviceIngestionController::class, 'activity']);

    // Server → device: pending coach commands (buzz to find it, sync now). Drained on read.
    Route::get('/commands', [DeviceIngestionController::class, 'commands']);

    // Owner-operated management — web session OR native-app bearer token.
    Route::middleware('auth.any')->group(function () {
        Route::post('/pair', [DeviceIngestionController::class, 'pair']);
        Route::get('/ingestions', [DeviceIngestionController::class, 'ingestions']);
        Route::delete('/{connection}', [DeviceIngestionController::class, 'destroy']);
    });
});

// Coach — native-app access to the same CoachController the web UI uses (the web keeps its own
// session routes in routes/titan/coach.php). Bearer-token or session via auth.any.
Route::middleware('auth.any')->prefix('coach')->group(function () {
    Route::get('/{conversation}/messages', [\App\Http\Controllers\Coach\CoachController::class, 'messages']);
    Route::post('/send', [\App\Http\Controllers\Coach\CoachController::class, 'send']);
    Route::post('/{conversation}/send', [\App\Http\Controllers\Coach\CoachController::class, 'send']);
    Route::post('/stream', [\App\Http\Controllers\Coach\CoachController::class, 'stream']);
    Route::post('/{conversation}/stream', [\App\Http\Controllers\Coach\CoachController::class, 'stream']);
    Route::post('/briefing', [\App\Http\Controllers\Coach\CoachController::class, 'briefing']);
    Route::post('/scan', [\App\Http\Controllers\Coach\CoachController::class, 'scan']);
    Route::post('/transcribe', [\App\Http\Controllers\Coach\CoachController::class, 'transcribe']);
});
