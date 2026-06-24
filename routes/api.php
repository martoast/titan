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

// Native-app nutrition ("Fuel" tab): photo → AI macros (grounded + logged) + the macro-ring card,
// manual entry, and corrections. Same Meal rows the coach's macros_today / recent_meals tools read.
Route::middleware('auth.any')->prefix('me')->group(function () {
    // First-run onboarding (same engine as the web wizard).
    Route::get('/onboarding', [\App\Http\Controllers\Api\MobileOnboardingController::class, 'status']);
    Route::post('/onboarding', [\App\Http\Controllers\Api\MobileOnboardingController::class, 'store']);

    // Edit the profile info collected at onboarding (change anything later).
    Route::get('/profile', [\App\Http\Controllers\Api\MobileProfileController::class, 'show']);
    Route::patch('/profile', [\App\Http\Controllers\Api\MobileProfileController::class, 'update']);

    // Personalized insight feed (anomalies, goal progress, wins, behavior correlations).
    Route::get('/insights', [\App\Http\Controllers\Api\MobileInsightsController::class, 'index']);

    // Behavior journal (feeds the correlation engine) + weight trend / goal projection.
    Route::get('/journal', [\App\Http\Controllers\Api\MobileJournalController::class, 'index']);
    Route::post('/journal', [\App\Http\Controllers\Api\MobileJournalController::class, 'store']);
    Route::get('/weight', [\App\Http\Controllers\Api\MobileWeightController::class, 'show']);
    Route::post('/weight', [\App\Http\Controllers\Api\MobileWeightController::class, 'store']);

    // Hydration + fasting.
    Route::get('/hydration', [\App\Http\Controllers\Api\MobileHydrationController::class, 'show']);
    Route::post('/hydration', [\App\Http\Controllers\Api\MobileHydrationController::class, 'store']);
    Route::get('/fasting', [\App\Http\Controllers\Api\MobileFastingController::class, 'show']);
    Route::post('/fasting/start', [\App\Http\Controllers\Api\MobileFastingController::class, 'start']);
    Route::post('/fasting/end', [\App\Http\Controllers\Api\MobileFastingController::class, 'end']);

    // Editable macro + sleep targets (also settable by telling the coach).
    Route::get('/targets', [\App\Http\Controllers\Api\MobileTargetsController::class, 'show']);
    Route::patch('/targets', [\App\Http\Controllers\Api\MobileTargetsController::class, 'update']);
    Route::delete('/targets', [\App\Http\Controllers\Api\MobileTargetsController::class, 'reset']);

    Route::get('/nutrition', [\App\Http\Controllers\Api\MobileNutritionController::class, 'index']);
    Route::post('/nutrition/scan', [\App\Http\Controllers\Api\MobileNutritionController::class, 'scan']);
    Route::post('/meals', [\App\Http\Controllers\Api\MobileNutritionController::class, 'store']);
    Route::patch('/meals/{meal}', [\App\Http\Controllers\Api\MobileNutritionController::class, 'update']);
    Route::delete('/meals/{meal}', [\App\Http\Controllers\Api\MobileNutritionController::class, 'destroy']);

    // Progress photos (private physique gallery the coach's physique tools read).
    Route::get('/progress-photos', [\App\Http\Controllers\Api\MobileProgressController::class, 'index']);
    Route::post('/progress-photos', [\App\Http\Controllers\Api\MobileProgressController::class, 'store']);
    Route::delete('/progress-photos/{photo}', [\App\Http\Controllers\Api\MobileProgressController::class, 'destroy']);
});

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
