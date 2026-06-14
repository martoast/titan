<?php

use App\Models\Profile;
use App\Models\ProgressPhoto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An AI physique read on a progress photo. Body-fat is stored as a confidence-aware
 * RANGE (low/high) — never a single fake-precise number — alongside per-muscle-group
 * ratings (1-10) and a supportive, non-medical summary. `pct_to_goal` is filled when
 * an active PhysiqueGoal exists and we compare the latest photo to the dream image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physique_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ProgressPhoto::class)->nullable()->constrained()->nullOnDelete();
            $table->decimal('body_fat_pct_low', 5, 2)->nullable();
            $table->decimal('body_fat_pct_high', 5, 2)->nullable();
            $table->json('muscle_ratings')->nullable();    // {chest,back,shoulders,arms,legs,core}
            $table->unsignedTinyInteger('pct_to_goal')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physique_analyses');
    }
};
