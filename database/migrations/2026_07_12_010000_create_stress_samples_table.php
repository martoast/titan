<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic stress reads → the stress-over-day strip (like the sleep movement strip). Mirrors
 * motion_samples: one row per sampled minute, insertOrIgnore-safe on (profile_id, recorded_at), with
 * recorded_at in the app-tz wall-clock convention so it buckets by local day the same way the strip
 * reader queries it. `stress` is the 0–3 score ×100 (0..300) to stay integer; StressMonitor derives it.
 *
 * @see App\Support\StressMonitor  @see database/migrations/*create_motion_samples_table (the template)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stress_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('stress');            // 0..300 = the 0–3.00 score ×100
            $table->string('source', 32)->default('derived');  // derived (HR+motion) | burst (HRV) later
            $table->timestamps();
            $table->unique(['profile_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stress_samples');
    }
};
