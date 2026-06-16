<?php

/*
 * Titan · cycle routes. Runs inside the auth group (see routes/web.php).
 * Menstrual-cycle tracking. Wellness-only — awareness, not contraception or diagnosis.
 */

use App\Http\Controllers\Wellness\CycleController;
use Illuminate\Support\Facades\Route;

Route::get('/cycle', [CycleController::class, 'index'])->name('cycle.index');
Route::post('/cycle/period', [CycleController::class, 'period'])->name('cycle.period');
Route::post('/cycle/day', [CycleController::class, 'logDay'])->name('cycle.day');
Route::post('/cycle/settings', [CycleController::class, 'settings'])->name('cycle.settings');
