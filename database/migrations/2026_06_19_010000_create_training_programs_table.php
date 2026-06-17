<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generated training mesocycle — a real, followable hypertrophy program: weeks of sessions with
 * prescribed exercises, sets, reps and RIR, volume ramping MEV→MRV with a deload, and the user's
 * focus (weak-point) muscles given priority. The plan itself lives in `plan` (JSON); the row tracks
 * which program is active and which week the user is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('focus')->nullable();          // priority muscles, e.g. ["chest","shoulders"]
            $table->unsignedTinyInteger('days_per_week');
            $table->unsignedTinyInteger('weeks');
            $table->string('split');                     // human label, e.g. "Push / Pull / Legs"
            $table->string('experience')->default('intermediate');
            $table->json('plan');                        // the full weeks→days→exercises structure
            $table->json('volume')->nullable();          // per-muscle weekly set progression (for the card)
            $table->date('started_on')->nullable();
            $table->unsignedTinyInteger('current_week')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_programs');
    }
};
