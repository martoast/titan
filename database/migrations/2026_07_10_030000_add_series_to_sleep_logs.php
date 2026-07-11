<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SLEEP TIMELINE v2 (movement & peaks): the two sparse, gap-honest per-epoch overlay series aligned to the
// SAME epoch grid as `hypnogram` — hr_series ({i,v} bpm) and motion_series ({i,v} restlessness), each
// downsampled to ≤180 points by max-pool at seal time. Persisted like the hypnogram so the read is cheap.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->json('hr_series')->nullable()->after('hypnogram');
            $table->json('motion_series')->nullable()->after('hr_series');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->dropColumn(['hr_series', 'motion_series']);
        });
    }
};
