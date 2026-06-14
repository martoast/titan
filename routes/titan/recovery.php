<?php

/*
 * Titan · recovery routes. Runs inside the auth group (see routes/web.php).
 * The "recovery" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Wellness\RecoveryController;
use Illuminate\Support\Facades\Route;

Route::get('/recovery', [RecoveryController::class, 'index'])->name('recovery.index');
Route::post('/recovery', [RecoveryController::class, 'store'])->name('recovery.store');
