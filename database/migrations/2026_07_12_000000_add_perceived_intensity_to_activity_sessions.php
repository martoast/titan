<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manually-logged workouts (source='manual') carry no HR/PPG, so their training load can't be derived
 * from sensor data. Instead the user picks a perceived intensity (easy | moderate | hard); the store
 * endpoint turns that + duration into an sRPE-style TRIMP estimate so a manual workout still registers
 * on the strain ring. Nullable — every sensor-sealed session leaves it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->string('perceived_intensity', 12)->nullable()->after('activity_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->dropColumn('perceived_intensity');
        });
    }
};
