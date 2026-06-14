<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Body composition + tape measurements over time. All measures are nullable so a
 * row can capture just a morning weigh-in, just a DEXA body-fat %, or a full
 * measurement session — whatever the user logged that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('body_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('body_fat_pct', 5, 2)->nullable();
            $table->decimal('waist_cm', 6, 2)->nullable();
            $table->decimal('chest_cm', 6, 2)->nullable();
            $table->decimal('arm_cm', 6, 2)->nullable();
            $table->decimal('thigh_cm', 6, 2)->nullable();
            $table->date('taken_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_metrics');
    }
};
