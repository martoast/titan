<?php

use App\Models\PhysiqueGoal;
use App\Models\Profile;
use App\Models\ProgressPhoto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The living goal image's history — the heartbeat of the founding wedge. Each row is
 * one weekly "step" we rendered: the user's progress photo nudged a calibrated, identity-
 * preserving increment toward their dream physique, where the size of the step is driven by
 * how consistent they were (`adherence`, 0..1) over the prior 1-2 weeks. Keeping the full
 * history (rather than a single path on profile.settings) is what powers the week-by-week
 * progression strip — the visible proof that "the you in the picture advances as you do".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('living_goal_renders', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(PhysiqueGoal::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(ProgressPhoto::class)->nullable()->constrained()->nullOnDelete();
            $table->string('image_path');                          // public disk: the rendered "one step closer"
            $table->unsignedTinyInteger('step_pct')->default(0);   // cumulative % toward goal this render depicts (0-100)
            $table->decimal('adherence', 4, 3)->nullable();        // 0..1 consistency score that drove the step
            $table->json('adherence_breakdown')->nullable();       // {workouts, meals, recovery} sub-scores for transparency
            $table->timestamps();

            $table->index(['profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('living_goal_renders');
    }
};
