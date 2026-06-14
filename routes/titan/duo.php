<?php

/*
 * Titan · duo routes. Runs inside the auth group (see routes/web.php).
 * The "duo" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Duo\DuoController;
use Illuminate\Support\Facades\Route;

Route::get('/duo', [DuoController::class, 'index'])->name('duo.index');
