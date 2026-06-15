<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cardio / wearable activity session — distinct from `workouts` (strength training with
 * exercises + sets). Sealed from the band's workout windows by SealActivityJob: the accel
 * classifier names the activity, and the biosignal service computes TRIMP / calories / VO2max /
 * heart-rate recovery. One row per session, keyed on (profile_id, started_at) for idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('source')->default('titan_band');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedSmallInteger('duration_min')->nullable();

            // Classification (Pillar 2).
            $table->string('activity_type')->nullable();        // run / walk / cycle / stairs / rest / other
            $table->decimal('activity_confidence', 4, 2)->nullable();

            // Effort + GPS metrics.
            $table->decimal('distance_km', 6, 2)->nullable();
            $table->unsignedSmallInteger('avg_hr')->nullable();
            $table->unsignedSmallInteger('max_hr')->nullable();
            $table->decimal('trimp', 6, 1)->nullable();
            $table->unsignedInteger('calories_kcal')->nullable();

            // Fitness (Pillar 3).
            $table->decimal('vo2max', 4, 1)->nullable();
            $table->string('fitness_level')->nullable();
            $table->decimal('hrr_bpm', 5, 1)->nullable();       // heart-rate recovery (60 s)

            $table->string('updated_via')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'started_at']);
            $table->index(['profile_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_sessions');
    }
};
