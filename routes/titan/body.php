<?php

/*
 * Titan · body routes. Runs inside the auth group (see routes/web.php).
 * The "body" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Health\BodyController;
use Illuminate\Support\Facades\Route;

Route::get('/body', [BodyController::class, 'index'])->name('body.index');
Route::post('/body', [BodyController::class, 'store'])->name('body.store');
Route::delete('/body/{metric}', [BodyController::class, 'destroy'])->name('body.destroy');
