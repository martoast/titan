<?php

use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot-ish row: one exercise as performed within one workout, in a given order,
 * with its own optional note. Owns the set rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Workout::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Exercise::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workout_id', 'order']);
            $table->index('exercise_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_exercises');
    }
};
