<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A measurable, time-bound goal (SMART) — flagship use: a target bodyweight by a date. Paired with
 * the EWMA weight-trend so we can honestly project "at your current trend you'll hit 85 kg around
 * Sept 12 — 3 weeks ahead of your goal." One active goal per (profile, metric).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('metric');                    // weight | body_fat | steps | ...
            $table->string('direction');                 // down | up
            $table->float('start_value');
            $table->float('target_value');
            $table->date('target_date')->nullable();
            $table->string('unit')->nullable();          // kg | % | steps
            $table->string('status')->default('active'); // active | achieved | archived
            $table->timestamp('achieved_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'metric', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
