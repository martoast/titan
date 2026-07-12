<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History for the Titan Longevity Index so PACE OF AGING is a real trend, not a one-shot. One row per
 * captured day (weekly recompute + on fresh bloodwork / a new fitness test). Mirrors weekly_snapshots.
 * @see App\Support\LongevityIndex
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('longevity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->date('captured_on');
            $table->decimal('titan_age', 5, 1);
            $table->decimal('chronological_age', 5, 1);
            $table->decimal('delta', 5, 1);                 // titan − chrono (negative = younger)
            $table->string('confidence', 12)->nullable();   // high | medium | low
            $table->json('metrics')->nullable();            // the full assess() payload for that day
            $table->timestamps();
            $table->unique(['profile_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('longevity_snapshots');
    }
};
