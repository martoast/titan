<?php

/*
 * Titan · workouts routes. Runs inside the auth group (see routes/web.php).
 * The "workouts" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Workouts\LiveWorkoutController;
use App\Http\Controllers\Workouts\WorkoutController;
use Illuminate\Support\Facades\Route;

Route::get('/workouts', [WorkoutController::class, 'index'])->name('workouts.index');
Route::get('/workouts/create', [WorkoutController::class, 'create'])->name('workouts.create');
Route::post('/workouts', [WorkoutController::class, 'store'])->name('workouts.store');

// Real-time "snap the machine" live session. Static paths before the {workout} catch-all.
Route::get('/workouts/live', [LiveWorkoutController::class, 'page'])->name('workouts.live');
Route::post('/workouts/live/identify', [LiveWorkoutController::class, 'identify'])->name('workouts.live.identify');
Route::post('/workouts/live/exercise', [LiveWorkoutController::class, 'addExercise'])->name('workouts.live.exercise');
Route::post('/workouts/live/set', [LiveWorkoutController::class, 'addSet'])->name('workouts.live.set');
Route::post('/workouts/live/finish', [LiveWorkoutController::class, 'finish'])->name('workouts.live.finish');

Route::get('/workouts/{workout}', [WorkoutController::class, 'show'])->name('workouts.show');
Route::put('/workouts/{workout}/sets', [WorkoutController::class, 'updateSets'])->name('workouts.sets.update');
