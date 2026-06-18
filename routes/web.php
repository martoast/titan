<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/coach') : view('welcome');
});

// --- Browser-test login shortcut (LOCAL ONLY) ---
// Authenticates a user without a password so the headless browser harness can sign in. Registered
// only in the local environment and guarded again in the handler — it never exists in production.
if (app()->environment('local')) {
    Route::get('/dev/login', function (\Illuminate\Http\Request $request) {
        abort_unless(app()->environment('local'), 404);
        $user = $request->filled('email')
            ? \App\Models\User::where('email', $request->string('email'))->first()
            : \App\Models\User::query()->oldest('id')->first();
        abort_unless($user, 404, 'No user available to log in.');

        \Illuminate\Support\Facades\Auth::login($user);
        $request->session()->regenerate();

        return redirect($request->string('to', '/coach'));
    })->name('dev.login');
}

Route::middleware(['auth'])->group(function () {
    // Onboarding wizard — runs OUTSIDE the `onboarded` gate (it's where the gate sends you).
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    // Dream-physique render generated mid-wizard (AJAX) — needs to run before the profile is finalized.
    Route::post('/onboarding/physique', [OnboardingController::class, 'generatePhysique'])->name('onboarding.physique');

    // Everything else requires a completed Titan profile first.
    Route::middleware('onboarded')->group(function () {
        // Titan dashboard (home once logged in).
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Progress & trends — the visual longitudinal view around the dream physique.
        Route::get('/progress', [\App\Http\Controllers\ProgressController::class, 'index'])->name('progress');

        // Food wiki — your most-eaten foods (same data the coach pulls via my_foods).
        Route::get('/foods', [\App\Http\Controllers\FoodController::class, 'index'])->name('foods.index');

        // Research library — every deep-dive brief the coach has written.
        Route::get('/research', [\App\Http\Controllers\ResearchLibraryController::class, 'index'])->name('research.index');
        Route::get('/research/{page}', [\App\Http\Controllers\ResearchLibraryController::class, 'show'])->name('research.show');

        // Breeze account settings (name / email / password) — distinct from the
        // health Profile model.
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // --- Titan feature domains ---
        // Each domain owns its own route file under routes/titan/. They run inside this
        // auth group, so every route they define is already authenticated. Builds edit
        // ONLY their own file here; never this one.
        foreach (['brain', 'biomarkers', 'body', 'meals', 'workouts', 'sleep', 'recovery', 'fitness', 'physique', 'cycle', 'coach', 'duo', 'wearables', 'simulator', 'notifications'] as $domain) {
            require __DIR__."/titan/{$domain}.php";
        }
    });
});

require __DIR__.'/auth.php';
