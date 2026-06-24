<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The output of the correlation engine: per-user "behavior X moves outcome Y by Z%" findings,
 * recomputed nightly. `significant` rows are surfaced to the user (insight feed / coach / Discovery
 * push). `discovered_at` marks when we first told them, so a Discovery push fires only once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('behavior_key');
            $table->string('outcome_key');                  // hrv | rhr | sleep_quality | sleep_hours
            $table->unsignedSmallInteger('n_with');
            $table->unsignedSmallInteger('n_without');
            $table->float('median_with');
            $table->float('median_without');
            $table->float('pct_change');                    // (median_with − median_without) / median_without
            $table->float('effect_size');                   // Cliff's delta [-1,1]
            $table->float('p_value');
            $table->float('q_value');                       // BH-adjusted
            $table->string('confidence')->default('low');   // low | medium | high
            $table->boolean('significant')->default(false);
            $table->timestamp('discovered_at')->nullable(); // first time surfaced (Discovery push)
            $table->timestamps();

            $table->unique(['profile_id', 'behavior_key', 'outcome_key']);
            $table->index(['profile_id', 'significant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_impacts');
    }
};
