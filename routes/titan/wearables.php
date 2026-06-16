<?php

/*
 * Titan · devices (wearable / biosignal) routes. Runs inside the auth group (see
 * routes/web.php). The "wearables" build owns this file exclusively.
 *
 * This is the human-facing /devices page (pair, status, disconnect). The signed
 * machine ingestion endpoints live in routes/api.php.
 */

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Health\AppleHealthController;
use App\Http\Controllers\Wellness\DeviceController;
use App\Http\Controllers\Wellness\PolarController;
use Illuminate\Support\Facades\Route;

// Assistant access: personal API tokens for an external agent (Claude/MCP) to run the account.
Route::get('/connect', [ApiTokenController::class, 'index'])->name('connect.index');
Route::post('/connect/tokens', [ApiTokenController::class, 'store'])->name('connect.tokens.store');
Route::delete('/connect/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('connect.tokens.destroy');

Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
Route::get('/devices/firmware', [DeviceController::class, 'firmware'])->name('devices.firmware');
Route::get('/devices/bridge', [DeviceController::class, 'bridge'])->name('devices.bridge');
Route::get('/devices/validate', [DeviceController::class, 'validate'])->name('devices.validate');
Route::post('/devices/hrv-preview', [DeviceController::class, 'hrvPreview'])->name('devices.hrv-preview');
Route::post('/devices/pair', [DeviceController::class, 'pair'])->name('devices.pair');
Route::delete('/devices/{connection}', [DeviceController::class, 'destroy'])->name('devices.destroy');

// Apple Health — import an "Export All Health Data" export.zip (no cloud API exists;
// the export file is the practical path). The importer stream-parses export.xml.
Route::post('/devices/apple-health/import', [AppleHealthController::class, 'upload'])->name('devices.apple-health.import');

// Polar AccessLink — free official OAuth2 connect flow (enabled when POLAR_* creds set).
Route::get('/devices/polar/connect', [PolarController::class, 'connect'])->name('devices.polar.connect');
Route::get('/devices/polar/callback', [PolarController::class, 'callback'])->name('devices.polar.callback');
