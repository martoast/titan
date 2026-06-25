<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 24/7 heart-rate time-series. The band streams HR continuously when connected and a light
 * one-point-per-minute trend when offline+idle (firmware reconcileHrm duty-cycle). The phone
 * aggregates those to ~1 point/minute and uploads them as `hr_trend` summaries; each point lands
 * here. Drives the all-day HR graph + a resting-HR baseline. Unique on (profile, recorded_at) so a
 * re-sent window can't double-insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('bpm');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('source', 32)->default('band');
            $table->timestamps();

            $table->unique(['profile_id', 'recorded_at']);   // also serves day-range queries (leftmost prefix)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_samples');
    }
};
