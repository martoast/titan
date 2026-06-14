<?php

/*
 * Titan · brain routes. Runs inside the auth group (see routes/web.php).
 * The "brain" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Brain\BrainController;
use Illuminate\Support\Facades\Route;

Route::get('/brain', [BrainController::class, 'index'])->name('brain.index');

// Static routes first so they aren't shadowed by the {slug} catch-all.
Route::get('/brain/create', [BrainController::class, 'create'])->name('brain.create');
Route::post('/brain', [BrainController::class, 'store'])->name('brain.store');
Route::post('/brain/dump', [BrainController::class, 'ingest'])->name('brain.dump');
Route::post('/brain/upload', [BrainController::class, 'upload'])->name('brain.upload');

Route::get('/brain/{slug}', [BrainController::class, 'show'])->name('brain.show');
Route::get('/brain/{slug}/edit', [BrainController::class, 'edit'])->name('brain.edit');
Route::put('/brain/{slug}', [BrainController::class, 'update'])->name('brain.update');
Route::delete('/brain/{slug}', [BrainController::class, 'destroy'])->name('brain.destroy');
