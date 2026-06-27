<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-workout HRV (RMSSD, ms) from a chest strap's beat-to-beat RR intervals.
 *
 * Only a strap (e.g. Polar H10) that transmits RR intervals can produce this — wrist PPG can't give
 * trustworthy beat-to-beat timing under motion. So it's nullable and purely additive: workouts without
 * a strap (or with a strap that omits RR) simply leave it null. A parasympathetic-load signal the
 * wrist can't match during exercise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->decimal('workout_hrv_ms', 6, 2)->nullable()->after('hr_quality');
        });
    }

    public function down(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->dropColumn('workout_hrv_ms');
        });
    }
};
