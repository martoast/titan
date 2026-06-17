<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A frozen snapshot of one training week — the raw measures (sessions, sets, avg calories/protein,
 * sleep, recovery, weight) plus an overall 0–100 week score. Persisting these turns the weekly review
 * from a one-off readout into a real trend line: true week-over-week deltas, a multi-week trajectory,
 * and a streak of consistent weeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('week_start');                      // Monday of the week
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('metrics');                         // the raw per-domain measures
            $table->string('headline')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_snapshots');
    }
};
