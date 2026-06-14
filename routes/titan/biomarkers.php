<?php

/*
 * Titan · biomarkers routes. Runs inside the auth group (see routes/web.php).
 * The "biomarkers" build owns this file exclusively. Add routes below.
 */

use App\Http\Controllers\Health\BiomarkerController;
use Illuminate\Support\Facades\Route;

Route::get('/biomarkers', [BiomarkerController::class, 'index'])->name('biomarkers.index');
Route::post('/biomarkers', [BiomarkerController::class, 'store'])->name('biomarkers.store');
Route::post('/biomarkers/upload', [BiomarkerController::class, 'upload'])->name('biomarkers.upload');
Route::delete('/biomarkers/{reading}', [BiomarkerController::class, 'destroy'])->name('biomarkers.destroy');
