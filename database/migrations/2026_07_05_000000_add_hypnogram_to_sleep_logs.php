<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The per-30s sleep-stage sequence (the biosignal stager already computes it as hypnogram_30s) —
// persisted so the app can draw the classic Whoop hypnogram (the wavy stage timeline).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->json('hypnogram')->nullable()->after('awake_min');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->dropColumn('hypnogram');
        });
    }
};
