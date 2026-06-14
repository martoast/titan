<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consistency streaks per profile — the duo retention mechanic. One row per
 * (profile, kind), e.g. "overall", "meals_logged", "workouts". current_count is
 * the live run, longest_count the personal best, freezes_available are the
 * "skip a day without breaking the streak" tokens (default 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedInteger('current_count')->default(0);
            $table->unsignedInteger('longest_count')->default(0);
            $table->date('last_active_on')->nullable();
            $table->unsignedTinyInteger('freezes_available')->default(2);
            $table->timestamps();

            $table->unique(['profile_id', 'kind']);
            $table->index(['profile_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streaks');
    }
};
