<?php

use App\Models\WorkoutExercise;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One set of one exercise within a workout: reps × weight, optional RPE, and a
 * warmup flag (warmups are excluded from working-volume / top-set calculations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(WorkoutExercise::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('set_number')->default(1);
            $table->unsignedSmallInteger('reps')->default(0);
            $table->decimal('weight_kg', 6, 2)->default(0);
            $table->decimal('rpe', 3, 1)->nullable();
            $table->boolean('is_warmup')->default(false);
            $table->timestamps();

            $table->index('workout_exercise_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_sets');
    }
};
