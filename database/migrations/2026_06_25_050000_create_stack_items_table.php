<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "What you take" — the persistent protocol. One row per thing the user takes regularly
 * (a supplement or a medication), with its dose and schedule. The dated taken/skipped log
 * lives in intake_events; this table is the definition the daily checklist reads from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('supplement');   // supplement | medication | other
            $table->string('brand')->nullable();
            $table->decimal('dose_amount', 10, 2)->nullable();
            $table->string('dose_unit')->nullable();          // IU, mg, mcg, g, ml, caps, tabs…
            $table->string('form')->nullable();               // capsule, tablet, softgel, powder, liquid, gummy
            $table->json('schedule')->nullable();             // { frequency, times[], days[], with_food }
            $table->string('dsld_id')->nullable();            // NIH DSLD label id (supplements)
            $table->string('rxcui')->nullable();              // RxNorm concept id (meds) — powers interaction checks
            $table->boolean('active')->default(true);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('photo_path')->nullable();         // snapped bottle/label
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stack_items');
    }
};
