<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached "worth knowing" findings about a profile's active stack — pairwise interactions and
 * timing notes (drug↔drug, drug↔supplement, supplement↔supplement). Recomputed when the stack
 * changes. Strictly INFORMATIONAL (literature/label data, severity + source), never advice —
 * the UI pins a "not medical advice, check with your pharmacist/clinician" disclaimer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interaction_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('a_item_id')->nullable()->constrained('stack_items')->nullOnDelete();
            $table->foreignId('b_item_id')->nullable()->constrained('stack_items')->nullOnDelete();
            $table->string('a_name');
            $table->string('b_name');
            $table->string('severity')->default('info');   // info | timing | moderate | major
            $table->text('summary');
            $table->string('source')->nullable();          // seed | openFDA label | DDInter
            $table->dateTime('checked_at');
            $table->timestamps();

            $table->index(['profile_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interaction_flags');
    }
};
