<?php

/*
 * Titan · sleep routes. Runs inside the auth group (see routes/web.php).
 * The "sleep" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Wellness\SleepController;
use Illuminate\Support\Facades\Route;

Route::get('/sleep', [SleepController::class, 'index'])->name('sleep.index');
Route::post('/sleep', [SleepController::class, 'store'])->name('sleep.store');
