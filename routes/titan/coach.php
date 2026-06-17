<?php

/*
 * Titan · coach routes. Runs inside the auth group (see routes/web.php).
 * The "coach" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Coach\CoachController;
use Illuminate\Support\Facades\Route;

Route::get('/coach', [CoachController::class, 'index'])->name('coach.index');
Route::get('/coach/{conversation}/messages', [CoachController::class, 'messages'])->name('coach.messages');
Route::post('/coach', [CoachController::class, 'store'])->name('coach.store');
Route::post('/coach/briefing', [CoachController::class, 'briefing'])->name('coach.briefing');
// An optional {conversation?} mid-path can't match an empty segment, so a brand-new chat
// (no conversation yet) needs its own param-less route. Both point at the same action.
Route::post('/coach/send', [CoachController::class, 'send']);
Route::post('/coach/{conversation}/send', [CoachController::class, 'send'])->name('coach.send');
Route::post('/coach/stream', [CoachController::class, 'stream']);
Route::post('/coach/{conversation}/stream', [CoachController::class, 'stream'])->name('coach.stream');
Route::post('/coach/scan', [CoachController::class, 'scan']);
Route::post('/coach/{conversation}/scan', [CoachController::class, 'scan'])->name('coach.scan');
