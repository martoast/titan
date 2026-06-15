<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-hour activity counts (24 values, midnight→midnight) for a day, so we can compute the
 * non-parametric circadian rest-activity rhythm metrics (IS/IV/RA/M10/L5). The daily totals
 * alone have no within-day shape; the rhythm lives in the hourly profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_activity', function (Blueprint $table) {
            $table->json('hourly')->nullable()->after('distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('daily_activity', function (Blueprint $table) {
            $table->dropColumn('hourly');
        });
    }
};
