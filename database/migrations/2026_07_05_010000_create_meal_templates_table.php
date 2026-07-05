<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's personal meal MEMORY — the distinct dishes they actually eat, so a meal they've had
 * before can be re-logged in one tap (with its saved photo + macros) instead of re-scanned and
 * re-estimated every time. Every logged Meal auto-upserts here, deduped on a normalized name key;
 * `times_logged` + `last_eaten_at` rank the "your usuals" list. Macros/photo reflect the most recent
 * (i.e. latest-corrected) instance. Distinct from `food_facts` (per-ingredient nutrition cache).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('key');                          // normalized name → dedup within a profile
            $table->string('name');                         // display name (latest wins)
            $table->string('photo_path')->nullable();       // representative photo (public disk)
            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 1)->default(0);
            $table->decimal('carbs_g', 6, 1)->default(0);
            $table->decimal('fat_g', 6, 1)->default(0);
            $table->unsignedInteger('times_logged')->default(0);
            $table->dateTime('last_eaten_at')->nullable();
            $table->string('source')->default('manual');    // where it first came from
            $table->boolean('favorite')->default(false);    // user can pin a usual to the top
            $table->timestamps();

            $table->unique(['profile_id', 'key']);          // one memory per distinct dish per profile
            $table->index(['profile_id', 'last_eaten_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_templates');
    }
};
