<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared, global exercise library (NOT profile-scoped). Every profile's workouts
 * reference rows here. Seeded with ~30 common movements across muscle groups by
 * ExerciseLibrarySeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('muscle_group');              // e.g. chest, back, legs, shoulders...
            $table->string('category');                  // compound | isolation | cardio
            $table->string('equipment')->nullable();     // barbell, dumbbell, machine, bodyweight...
            $table->timestamps();

            $table->index('muscle_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
