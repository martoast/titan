<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strava-style run route + analytics on a sealed activity. Computed by the biosignal
 * /process/route endpoint from the run's GPS track (see SealActivityJob → BiosignalClient::processRoute).
 * All nullable: a run with no GPS fix (treadmill / lost signal) simply has no route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->longText('route_polyline')->nullable();        // Google-encoded, Douglas–Peucker-simplified
            $table->json('route_bounds')->nullable();              // {min_lat,min_lon,max_lat,max_lon} → map fit
            $table->unsignedInteger('moving_time_s')->nullable();  // excludes stops (the pace denominator)
            $table->unsignedSmallInteger('avg_pace_s_per_km')->nullable();
            $table->unsignedSmallInteger('gap_s_per_km')->nullable();       // grade-adjusted pace
            $table->smallInteger('elevation_gain_m')->nullable();
            $table->smallInteger('elevation_loss_m')->nullable();
            $table->json('elevation_profile')->nullable();         // [{d_km,alt_m}] downsampled for the chart
            $table->json('splits')->nullable();                    // {km:[…], mi:[…]} per-unit pace/elev/HR
            $table->json('best_efforts')->nullable();              // {1k:{…},5k:{…},…} fastest rolling efforts
            $table->unsignedSmallInteger('relative_effort')->nullable();    // HR-zone-weighted load
        });
    }

    public function down(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'route_polyline', 'route_bounds', 'moving_time_s', 'avg_pace_s_per_km', 'gap_s_per_km',
                'elevation_gain_m', 'elevation_loss_m', 'elevation_profile', 'splits', 'best_efforts',
                'relative_effort',
            ]);
        });
    }
};
