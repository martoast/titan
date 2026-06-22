<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One health profile per user — the unit everything in Titan hangs off of. Each
 * brother gets a profile; the two together form the "duo". Profile-scoped tables
 * (biomarkers, meals, workouts, sleep, photos, the brain wiki, conversations) all
 * reference profile_id so a person's entire long-term record lives under one roof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('sex')->nullable();            // male | female | other
            $table->decimal('height_cm', 5, 1)->nullable();   // cm, one decimal (imperial converts to e.g. 180.3)
            $table->text('primary_goal')->nullable();     // e.g. "+10 lbs lean muscle, longevity"
            $table->string('coach_tone')->default('balanced'); // tough_love | balanced | gentle
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
