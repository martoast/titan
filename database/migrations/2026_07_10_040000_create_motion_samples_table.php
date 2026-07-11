<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The continuous overnight-motion time-series. The band banks one per-epoch movement magnitude
 * (motionEMA×1000, milli-g) to its ring every ~30 s while asleep + offline — the T10 frame — and the
 * phone flushes them on the morning sync as `motion_trend` summaries; each point lands here. The night
 * seal reads this dense channel for the sleep-timeline movement strip, PREFERRING it over the sparse
 * HRV-burst accel proxy (which only has a value where a burst happened to land). Unique on
 * (profile, recorded_at) so a re-sent window (retry / ring overlap) can't double-insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motion_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('motion');   // milli-g EMA magnitude (0..65535), a RELATIVE level
            $table->string('source', 32)->default('band');
            $table->timestamps();

            $table->unique(['profile_id', 'recorded_at']);   // also serves the seal's night-range query (leftmost prefix)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motion_samples');
    }
};
