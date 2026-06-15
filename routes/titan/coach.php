<?php

/*
 * Titan · coach routes. Runs inside the auth group (see routes/web.php).
 * The "coach" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Coach\CoachController;
use Illuminate\Support\Facades\Route;

Route::get('/coach', [CoachController::class, 'index'])->name('coach.index');
Route::post('/coach', [CoachController::class, 'store'])->name('coach.store');
Route::post('/coach/briefing', [CoachController::class, 'briefing'])->name('coach.briefing');
Route::post('/coach/{conversation?}/send', [CoachController::class, 'send'])->name('coach.send');
