<?php

/*
 * Titan · devices (wearable / biosignal) routes. Runs inside the auth group (see
 * routes/web.php). The "wearables" build owns this file exclusively.
 *
 * This is the human-facing /devices page (pair, status, disconnect). The signed
 * machine ingestion endpoints live in routes/api.php.
 */

use App\Http\Controllers\Wellness\DeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
Route::post('/devices/pair', [DeviceController::class, 'pair'])->name('devices.pair');
Route::delete('/devices/{connection}', [DeviceController::class, 'destroy'])->name('devices.destroy');
