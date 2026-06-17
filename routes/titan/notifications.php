<?php

/*
 * Titan · notifications routes. Runs inside the auth group (see routes/web.php).
 * The "notifications" build owns this file exclusively.
 *
 * ORCHESTRATOR: add 'notifications' to the $domain foreach in routes/web.php so this
 * file is required.
 */

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->name('notifications.')->group(function () {
    // Notification center page + bell-dropdown feed.
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('/feed', [NotificationController::class, 'feed'])->name('feed');

    // Read state.
    Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('readAll');
    Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');

    // Web Push subscription lifecycle.
    Route::post('/subscribe', [NotificationController::class, 'subscribe'])->name('subscribe');
    Route::delete('/subscribe', [NotificationController::class, 'unsubscribe'])->name('unsubscribe');

    // Settings: coaching intensity + per-reminder toggles + a test push.
    Route::get('/settings', [NotificationController::class, 'settings'])->name('settings');
    Route::post('/settings', [NotificationController::class, 'updateSettings'])->name('settings.update');
    Route::post('/test', [NotificationController::class, 'test'])->name('test');
});
