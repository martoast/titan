<?php

/*
 * Titan · fitness routes. Runs inside the auth group (see routes/web.php).
 * Cardio fitness: wearable activity sessions, VO2max, heart-rate recovery.
 */

use App\Http\Controllers\Wellness\FitnessController;
use Illuminate\Support\Facades\Route;

Route::get('/fitness', [FitnessController::class, 'index'])->name('fitness.index');
Route::get('/fitness/runs/{session}', [FitnessController::class, 'showRun'])->name('fitness.run');
Route::post('/fitness/steps', [FitnessController::class, 'logSteps'])->name('fitness.steps');
Route::post('/fitness/chair-stand', [FitnessController::class, 'chairStand'])->name('fitness.chair-stand');
