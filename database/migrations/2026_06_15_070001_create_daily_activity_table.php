<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per profile per day of ambient movement — steps, MVPA minutes, active energy, floors,
 * distance. Fed by device summaries (wearable/Apple Health), the wrist step counter, or manual
 * entry. Drives the evidence-based daily step goal (Saint-Maurice 2020 / Paluch 2022: +1,000
 * steps/day ≈ −15% all-cause mortality; benefit plateaus ~7,000–8,700, not the 10k myth).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('steps')->default(0);
            $table->unsignedSmallInteger('mvpa_min')->nullable();        // moderate-to-vigorous minutes
            $table->unsignedInteger('active_kcal')->nullable();
            $table->unsignedSmallInteger('floors')->nullable();
            $table->decimal('distance_km', 6, 2)->nullable();
            $table->string('source')->default('manual');                 // titan_band | apple_health | manual …
            $table->string('updated_via')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'date']);
            $table->index(['profile_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activity');
    }
};
