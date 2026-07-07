<?php

/*
 * Titan · sleep routes. Runs inside the auth group (see routes/web.php).
 * The "sleep" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Wellness\SleepController;
use Illuminate\Support\Facades\Route;

Route::get('/sleep', [SleepController::class, 'index'])->name('sleep.index');
Route::post('/sleep', [SleepController::class, 'store'])->name('sleep.store');

// No-wearable quick logging (water-style): last night's hours + daytime naps that add to it.
Route::post('/sleep/night', [SleepController::class, 'night'])->name('sleep.night');
Route::post('/sleep/nap', [SleepController::class, 'nap'])->name('sleep.nap');
Route::delete('/sleep/nap/{sleepLog}', [SleepController::class, 'removeNap'])->name('sleep.nap.remove');
