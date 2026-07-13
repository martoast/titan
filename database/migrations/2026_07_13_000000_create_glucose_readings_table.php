<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continuous-glucose readings (CGM_INTEGRATION P1). Source-agnostic: a CGM emits a reading every 1–5 min
 * from Nightscout / Apple HealthKit / (later) Dexcom, all upserting here so every surface reads one table.
 * mg/dL is the canonical unit; display converts to mmol/L per the user's locale. Not a medical device —
 * Titan visualizes the user's OWN device data for wellness, never for insulin dosing or diagnosis.
 * @see App\Models\GlucoseReading @see App\Support\GlucoseMetrics
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glucose_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('taken_at');                       // UTC instant of the reading
            $table->unsignedSmallInteger('mg_dl');               // canonical unit (0–600 covers any CGM)
            $table->string('trend', 20)->nullable();             // rising/rising_fast/flat/falling/… as reported
            $table->string('source', 20);                        // nightscout | healthkit | dexcom
            $table->string('device')->nullable();
            $table->json('raw')->nullable();                     // the provider's original entry, for debugging
            $table->timestamps();
            // Dedup + fast range reads: one reading per (profile, instant), tolerant of backfill/out-of-order.
            $table->unique(['profile_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glucose_readings');
    }
};
