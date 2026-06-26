<?php

/*
 * Titan · "What you take" (supplements & medications) routes. Runs inside the auth group
 * (see routes/web.php). The "stack" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Stack\StackController;
use Illuminate\Support\Facades\Route;

Route::get('/stack', [StackController::class, 'index'])->name('stack.index');

// AJAX: type-ahead catalog search + snap-a-bottle / snap-the-shelf.
Route::get('/stack/search', [StackController::class, 'search'])->name('stack.search');
Route::post('/stack/scan', [StackController::class, 'scanPhoto'])->name('stack.scan');

// Logging a dose from the daily checklist (+ undo).
Route::post('/stack/{item}/intake', [StackController::class, 'logItem'])->name('stack.intake');
Route::delete('/stack/intake/{event}', [StackController::class, 'undo'])->name('stack.undo');

// Manage the protocol.
Route::post('/stack', [StackController::class, 'store'])->name('stack.store');
Route::patch('/stack/{item}', [StackController::class, 'update'])->name('stack.update');
Route::delete('/stack/{item}', [StackController::class, 'destroy'])->name('stack.destroy');
