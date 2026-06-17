<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device telemetry on the connection — so the coach can see the BAND, not just its data: battery, the
 * firmware it's running, and when it was last physically heard from. The Titan firmware already tracks
 * battery; this lets it ride in on each sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->unsignedTinyInteger('battery_pct')->nullable()->after('last_payload_type');
            $table->string('firmware')->nullable()->after('battery_pct');
        });
    }

    public function down(): void
    {
        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->dropColumn(['battery_pct', 'firmware']);
        });
    }
};
