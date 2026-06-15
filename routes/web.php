<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : view('welcome');
});

Route::middleware(['auth'])->group(function () {
    // Titan dashboard (home once logged in).
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // Breeze account settings (name / email / password) — distinct from the
    // health Profile model.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // --- Titan feature domains ---
    // Each domain owns its own route file under routes/titan/. They run inside this
    // auth group, so every route they define is already authenticated. Builds edit
    // ONLY their own file here; never this one.
    foreach (['brain', 'biomarkers', 'body', 'meals', 'workouts', 'sleep', 'recovery', 'physique', 'coach', 'duo', 'wearables', 'simulator'] as $domain) {
        require __DIR__."/titan/{$domain}.php";
    }
});

require __DIR__.'/auth.php';
