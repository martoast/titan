<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a session's distance was derived: 'gps' (integrated track), 'steps' (cadence×height estimate
 * when there was no GPS lock), or null. Lets the UI honestly label an estimated distance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->string('distance_source', 16)->nullable()->after('distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('activity_sessions', fn (Blueprint $t) => $t->dropColumn('distance_source'));
    }
};
