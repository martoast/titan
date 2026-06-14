<?php

/*
 * Titan · physique routes. Runs inside the auth group (see routes/web.php).
 * The "physique" build owns this file exclusively. Add routes below.
 *
 * The signature "living dream-physique" loop: a goal image, progress photos,
 * AI physique analysis, % to goal, and the on-demand "one step closer" morph.
 * Index lives at /photos (the sidebar links there).
 */

use App\Http\Controllers\Physique\PhysiqueController;
use Illuminate\Support\Facades\Route;

Route::controller(PhysiqueController::class)->group(function () {
    Route::get('/photos', 'index')->name('physique.index');

    // The dream-physique goal image.
    Route::post('/photos/goal', 'generateGoal')->name('physique.goal.generate');
    Route::post('/photos/goal/{goal}/activate', 'activateGoal')->name('physique.goal.activate');

    // Progress photos.
    Route::post('/photos/progress', 'storePhoto')->name('physique.photo.store');
    Route::delete('/photos/progress/{photo}', 'destroyPhoto')->name('physique.photo.destroy');

    // AI: per-photo analysis, % to goal, and the living goal image.
    Route::post('/photos/progress/{photo}/analyze', 'analyze')->name('physique.photo.analyze');
    Route::post('/photos/compare-to-goal', 'compareToGoal')->name('physique.compare');
    Route::post('/photos/living-image', 'livingImage')->name('physique.living');
});
