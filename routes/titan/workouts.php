<?php

/*
 * Titan · workouts routes. Runs inside the auth group (see routes/web.php).
 * The "workouts" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Workouts\WorkoutController;
use Illuminate\Support\Facades\Route;

Route::get('/workouts', [WorkoutController::class, 'index'])->name('workouts.index');
Route::get('/workouts/create', [WorkoutController::class, 'create'])->name('workouts.create');
Route::post('/workouts', [WorkoutController::class, 'store'])->name('workouts.store');
Route::get('/workouts/{workout}', [WorkoutController::class, 'show'])->name('workouts.show');
