<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menstrual cycles — the anchor for everything cycle-aware. One row per cycle, dated from
 * its first day of bleeding (cycle day 1). `length_days` is filled in when the NEXT cycle
 * starts (start-to-start), so we learn each person's real average instead of assuming 28.
 *
 * Wellness-only: this powers awareness, coaching context and phase-aware readiness — never
 * contraception or diagnosis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menstrual_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('start_date');                              // cycle day 1 = first day of period
            $table->date('period_end_date')->nullable();            // last day of bleeding (period length)
            $table->unsignedSmallInteger('length_days')->nullable(); // start→start; set when the next cycle begins
            $table->string('source')->default('manual');            // manual | coach | assistant
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'start_date']);
            $table->index(['profile_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menstrual_cycles');
    }
};
