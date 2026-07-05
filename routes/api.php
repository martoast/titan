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
Route::middleware('auth.any')->get('/me/strain', [\App\Http\Controllers\Api\MobileDashboardController::class, 'strain']);
Route::middleware('auth.any')->get('/me/overview', [\App\Http\Controllers\Api\MobileDashboardController::class, 'overview']);

// Native-app nutrition ("Fuel" tab): photo → AI macros (grounded + logged) + the macro-ring card,
// manual entry, and corrections. Same Meal rows the coach's macros_today / recent_meals tools read.
Route::middleware('auth.any')->prefix('me')->group(function () {
    // First-run onboarding (same engine as the web wizard).
    Route::get('/onboarding', [\App\Http\Controllers\Api\MobileOnboardingController::class, 'status']);
    Route::post('/onboarding', [\App\Http\Controllers\Api\MobileOnboardingController::class, 'store']);

    // "Daily" hub: sleep breakdown + the women's cycle view (phase, fertile window, pregnancy chance).
    Route::get('/sleep', [\App\Http\Controllers\Api\MobileSleepController::class, 'show']);
    Route::get('/hr', [\App\Http\Controllers\Api\MobileHrController::class, 'show']);
    Route::get('/cycle', [\App\Http\Controllers\Api\MobileCycleController::class, 'show']);
    Route::get('/cycle/calendar', [\App\Http\Controllers\Api\MobileCycleController::class, 'calendar']);
    Route::post('/cycle/period', [\App\Http\Controllers\Api\MobileCycleController::class, 'period']);
    Route::post('/cycle/day', [\App\Http\Controllers\Api\MobileCycleController::class, 'logDay']);

    // Edit the profile info collected at onboarding (change anything later).
    Route::get('/profile', [\App\Http\Controllers\Api\MobileProfileController::class, 'show']);
    Route::patch('/profile', [\App\Http\Controllers\Api\MobileProfileController::class, 'update']);

    // Runs — the Strava-style list + end-of-run detail (route map, splits, elevation, best efforts).
    Route::get('/runs', [\App\Http\Controllers\Api\MobileRunsController::class, 'index']);
    Route::get('/runs/{session}', [\App\Http\Controllers\Api\MobileRunsController::class, 'show']);

    // Community — opt-in social: the followed-athletes feed, leaderboard, settings, requests,
    // recap, badges, athlete discovery + profiles, follow graph, and per-activity kudos/comments.
    Route::get('/community/feed', [\App\Http\Controllers\Api\CommunityController::class, 'feed']);
    Route::get('/community/leaderboard', [\App\Http\Controllers\Api\LeaderboardController::class, 'index']);
    Route::get('/community/settings', [\App\Http\Controllers\Api\CommunityController::class, 'settings']);
    Route::patch('/community/settings', [\App\Http\Controllers\Api\CommunityController::class, 'updateSettings']);
    Route::post('/community/avatar', [\App\Http\Controllers\Api\CommunityController::class, 'uploadAvatar']);
    Route::get('/community/requests', [\App\Http\Controllers\Api\CommunityController::class, 'requests']);
    Route::post('/community/requests/{follow}/accept', [\App\Http\Controllers\Api\CommunityController::class, 'acceptRequest']);
    Route::post('/community/requests/{follow}/decline', [\App\Http\Controllers\Api\CommunityController::class, 'declineRequest']);
    Route::get('/community/recap', [\App\Http\Controllers\Api\CommunityController::class, 'recap']);
    Route::get('/achievements', [\App\Http\Controllers\Api\CommunityController::class, 'achievements']);

    Route::get('/athletes/search', [\App\Http\Controllers\Api\AthleteController::class, 'search']);
    Route::get('/athletes/{profile}', [\App\Http\Controllers\Api\AthleteController::class, 'show']);
    Route::post('/athletes/{profile}/follow', [\App\Http\Controllers\Api\AthleteController::class, 'follow']);
    Route::delete('/athletes/{profile}/follow', [\App\Http\Controllers\Api\AthleteController::class, 'unfollow']);

    Route::post('/activities/{session}/kudos', [\App\Http\Controllers\Api\ActivitySocialController::class, 'kudos']);
    Route::delete('/activities/{session}/kudos', [\App\Http\Controllers\Api\ActivitySocialController::class, 'unkudos']);
    Route::get('/activities/{session}/comments', [\App\Http\Controllers\Api\ActivitySocialController::class, 'comments']);
    Route::post('/activities/{session}/comments', [\App\Http\Controllers\Api\ActivitySocialController::class, 'comment']);
    Route::delete('/activities/comments/{comment}', [\App\Http\Controllers\Api\ActivitySocialController::class, 'deleteComment']);
    Route::patch('/activities/{session}/visibility', [\App\Http\Controllers\Api\ActivitySocialController::class, 'updateVisibility']);

    // Personalized insight feed (anomalies, goal progress, wins, behavior correlations).
    Route::get('/insights', [\App\Http\Controllers\Api\MobileInsightsController::class, 'index']);

    // Behavior journal (feeds the correlation engine) + weight trend / goal projection.
    Route::get('/journal', [\App\Http\Controllers\Api\MobileJournalController::class, 'index']);
    Route::post('/journal', [\App\Http\Controllers\Api\MobileJournalController::class, 'store']);
    Route::get('/weight', [\App\Http\Controllers\Api\MobileWeightController::class, 'show']);
    Route::post('/weight', [\App\Http\Controllers\Api\MobileWeightController::class, 'store']);

    // Live Apple HealthKit sync (works without a band — iPhone/Apple Watch data → Titan's engine).
    Route::get('/health', [\App\Http\Controllers\Api\MobileHealthController::class, 'status']);
    Route::post('/health/ingest', [\App\Http\Controllers\Api\MobileHealthController::class, 'ingest']);

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
    Route::post('/nutrition/barcode', [\App\Http\Controllers\Api\MobileNutritionController::class, 'barcode']);
    Route::post('/meals/confirm', [\App\Http\Controllers\Api\MobileNutritionController::class, 'confirm']);
    Route::post('/meals', [\App\Http\Controllers\Api\MobileNutritionController::class, 'store']);
    Route::patch('/meals/{meal}', [\App\Http\Controllers\Api\MobileNutritionController::class, 'update']);
    Route::delete('/meals/{meal}', [\App\Http\Controllers\Api\MobileNutritionController::class, 'destroy']);
    // Meal memory ("Your meals"): the profile's remembered dishes + one-tap re-log (no camera/AI).
    Route::get('/meals-library', [\App\Http\Controllers\Api\MobileNutritionController::class, 'library']);
    Route::post('/meals/relog', [\App\Http\Controllers\Api\MobileNutritionController::class, 'relog']);
    Route::patch('/meals-library/{template}/favorite', [\App\Http\Controllers\Api\MobileNutritionController::class, 'favoriteTemplate']);
    Route::delete('/meals-library/{template}', [\App\Http\Controllers\Api\MobileNutritionController::class, 'forgetTemplate']);

    // "What you take" — supplements & meds. Today's checklist card + the full protocol +
    // (informational) interaction flags. Same rows the coach's my_stack / log_intake tools read.
    Route::get('/stack', [\App\Http\Controllers\Api\MobileStackController::class, 'index']);
    Route::get('/stack/search', [\App\Http\Controllers\Api\MobileStackController::class, 'search']);
    Route::post('/stack/scan', [\App\Http\Controllers\Api\MobileStackController::class, 'scanPhoto']);
    Route::get('/stack/interactions', [\App\Http\Controllers\Api\MobileStackController::class, 'interactionList']);
    Route::post('/stack', [\App\Http\Controllers\Api\MobileStackController::class, 'store']);
    Route::patch('/stack/{item}', [\App\Http\Controllers\Api\MobileStackController::class, 'update']);
    Route::delete('/stack/{item}', [\App\Http\Controllers\Api\MobileStackController::class, 'destroy']);
    Route::post('/stack/{item}/intake', [\App\Http\Controllers\Api\MobileStackController::class, 'logItem']);
    Route::post('/stack/intake', [\App\Http\Controllers\Api\MobileStackController::class, 'logOneOff']);
    Route::delete('/stack/intake/{event}', [\App\Http\Controllers\Api\MobileStackController::class, 'undo']);

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
