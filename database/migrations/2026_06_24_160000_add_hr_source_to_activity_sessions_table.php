<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record how a workout's heart rate was derived, so the app can be honest about it.
 *
 *   hr_source  = 'ppg_inmotion' — recomputed server-side from raw PPG + accel with motion-artifact
 *                                 suppression (the accurate path, available when the band streamed
 *                                 raw PPG live).
 *              = 'onchip'       — the watch's on-chip bpm register (sport-mode), used when no raw
 *                                 PPG was available (offline workout).
 *              = 'chest_strap'  — a paired BLE HR strap (reference-grade).
 *   hr_quality = 0..1 coverage/confidence of the in-motion estimate (fraction of windows that
 *                cleared the confidence bar). Drives the "estimated / reliable" badge in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->string('hr_source')->nullable()->after('max_hr');
            $table->decimal('hr_quality', 4, 3)->nullable()->after('hr_source');
        });
    }

    public function down(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->dropColumn(['hr_source', 'hr_quality']);
        });
    }
};
