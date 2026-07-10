<?php

use App\Models\ActivitySession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a logged strength Workout to the ActivitySession it happened in, DETERMINISTICALLY — the seal
 * associates a workout whose performed_at falls inside a sealed session's [start,end], and the coach's
 * log_workout tool links to an open/just-finished session at log time. Replaces the read-time ±20-min
 * proximity guess in MobileRunsController::strengthDetail, which attached sets to the wrong lift (or
 * doubled/dropped them) when two sessions sat close together. nullOnDelete: deleting a session detaches
 * its workouts (the sets survive), never cascades them away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->foreignIdFor(ActivitySession::class)
                ->nullable()
                ->after('profile_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(ActivitySession::class);
        });
    }
};
