<?php

/*
 * Titan · meals routes. Runs inside the auth group (see routes/web.php).
 * The "meals" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Meals\MealController;
use App\Http\Controllers\Meals\MealSuggestionController;
use Illuminate\Support\Facades\Route;

Route::get('/meals', [MealController::class, 'index'])->name('meals.index');

// AI meal ideas: suggest sized to the next meal, view a recipe, log it. Pantry = what's in the kitchen.
Route::post('/meals/pantry', [MealSuggestionController::class, 'pantry'])->name('meals.pantry');
Route::post('/meals/suggest', [MealSuggestionController::class, 'suggest'])->name('meals.suggest');
Route::get('/meals/suggestions/{suggestion}', [MealSuggestionController::class, 'recipe'])->name('meals.recipe');
Route::post('/meals/suggestions/{suggestion}/log', [MealSuggestionController::class, 'log'])->name('meals.suggestion.log');
Route::get('/meals/add', [MealController::class, 'create'])->name('meals.create');
Route::post('/meals/analyze', [MealController::class, 'analyze'])->name('meals.analyze');
Route::post('/meals/parse-text', [MealController::class, 'parseText'])->name('meals.parseText');
Route::post('/meals', [MealController::class, 'store'])->name('meals.store');
Route::delete('/meals/{meal}', [MealController::class, 'destroy'])->name('meals.destroy');
Route::post('/meals/targets', [MealController::class, 'targets'])->name('meals.targets');
