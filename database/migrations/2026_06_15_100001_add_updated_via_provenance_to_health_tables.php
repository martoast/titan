<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance column for wearable-fed health rows.
 *
 * `updated_via` records what last wrote a row's objective fields:
 *   - null            → manual / subjective entry (the default)
 *   - "biosignal:v3"  → the Python biosignal service at algo_version v3
 *   - "device:summary"→ a Shape-C provider summary (Apple Health / Polar)
 *
 * This lets the pipeline upsert objective signals (hrv_ms, resting_hr, sleep stages)
 * via updateOrCreate WITHOUT clobbering user-owned subjective ratings (stress, mood,
 * energy, soreness, notes), and lets `biosignal:reprocess` find rows below the current
 * algo version. Applied to the three tables a wearable can fill.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['recovery_logs', 'sleep_logs', 'workouts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('updated_via')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        foreach (['recovery_logs', 'sleep_logs', 'workouts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('updated_via');
            });
        }
    }
};
