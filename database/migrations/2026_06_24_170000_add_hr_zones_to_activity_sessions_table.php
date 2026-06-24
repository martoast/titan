<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minutes spent in each heart-rate zone over a session: {z1..z5} as % of HRmax (50-60/60-70/70-80/
 * 80-90/90+). Average HR badly understates intermittent work (lifting: hard sets + long rests), so
 * the PEAK (max_hr, already stored) and TIME IN THE HARD ZONES are what reveal a brutal session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->json('hr_zones')->nullable()->after('hr_quality');
        });
    }

    public function down(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->dropColumn('hr_zones');
        });
    }
};
