<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coach → band command queue. Commands the coach issues (buzz to find the band, sync now) are
 * queued here; the band drains them on its next check-in (GET /api/devices/commands) and executes
 * them — so the chat genuinely controls the device, not just reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->json('pending_commands')->nullable()->after('firmware');
        });
    }

    public function down(): void
    {
        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->dropColumn('pending_commands');
        });
    }
};
