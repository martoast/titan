<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily cycle log — flow, symptoms, mood/energy, and optional basal body temperature.
 * One row per day. These let the coach correlate how someone FEELS with the phase they're
 * in, and (with BBT) help confirm ovulation. Sexual-activity is optional and private — kept
 * only for honest fertility-awareness context, never shared or judged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('logged_on');
            $table->string('flow')->nullable();              // none | spotting | light | medium | heavy
            $table->json('symptoms')->nullable();            // ["cramps","headache","bloating",…]
            $table->unsignedTinyInteger('mood')->nullable();    // 1–5
            $table->unsignedTinyInteger('energy')->nullable();  // 1–5
            $table->decimal('bbt_c', 4, 2)->nullable();      // basal body temperature (°C)
            $table->boolean('intimacy')->nullable();         // optional, private — fertility-awareness context
            $table->string('source')->default('manual');     // manual | coach | assistant
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'logged_on']);
            $table->index(['profile_id', 'logged_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_logs');
    }
};
