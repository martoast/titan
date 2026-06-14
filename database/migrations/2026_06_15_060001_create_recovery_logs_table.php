<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A daily recovery / stress snapshot for a profile. `logged_at` is the calendar date.
 * HRV (ms) and resting HR (bpm) come from a wearable when available; stress, soreness,
 * mood and energy are subjective 1-10 self-ratings. These feed the readiness heuristic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('logged_at')->index();
            $table->unsignedSmallInteger('hrv_ms')->nullable();
            $table->unsignedSmallInteger('resting_hr')->nullable();
            $table->unsignedTinyInteger('stress')->nullable();    // 1-10
            $table->unsignedTinyInteger('soreness')->nullable();  // 1-10
            $table->unsignedTinyInteger('mood')->nullable();      // 1-10
            $table->unsignedTinyInteger('energy')->nullable();    // 1-10
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_logs');
    }
};
