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
use Illuminate\Support\Facades\Route;

// Assistant / MCP surface — personal-API-token auth (Bearer). One generic tool dispatcher gives an
// external agent full account control; write tools additionally require the token's 'write' ability.
Route::middleware('auth.token')->group(function () {
    Route::get('/me', [AssistantController::class, 'me']);
    Route::get('/tools', [AssistantController::class, 'tools']);
    Route::post('/tool', [AssistantController::class, 'call']);
});

Route::prefix('devices')->group(function () {
    // Device → server: signed biosignal batches (HMAC auth, no session).
    Route::post('/ingest', [DeviceIngestionController::class, 'ingest']);

    // Owner-operated management (web/session auth).
    Route::middleware('auth')->group(function () {
        Route::post('/pair', [DeviceIngestionController::class, 'pair']);
        Route::get('/ingestions', [DeviceIngestionController::class, 'ingestions']);
        Route::delete('/{connection}', [DeviceIngestionController::class, 'destroy']);
    });
});
