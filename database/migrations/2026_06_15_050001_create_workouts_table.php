<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single training session for a profile. Holds the ordered exercises performed
 * (workout_exercises) and their sets (workout_sets). Total volume is derived from
 * sets at read time (sum of reps × weight_kg).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->dateTime('performed_at');
            $table->string('name');                      // e.g. "Push Day"
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('duration_min')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workouts');
    }
};
