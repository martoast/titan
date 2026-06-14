<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signature feature: a believable, identity-preserved "dream physique" image.
 * `source_photo_path` is the user's original upload; `goal_image_path` is the Nano
 * Banana render of them with ~10 lbs more lean muscle (same face/lighting/background).
 * `prompt` records exactly what produced it. The active goal anchors the "% to goal"
 * progress bar and the living-goal-image morph. Both paths are on the public disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physique_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('source_photo_path');           // public disk: original upload
            $table->string('goal_image_path');             // public disk: AI dream physique
            $table->text('prompt');                        // generation prompt used
            $table->string('description')->nullable();     // e.g. "+10 lbs lean muscle"
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physique_goals');
    }
};
