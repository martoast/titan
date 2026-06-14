<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One night's sleep for a profile. `slept_at` is the calendar date of the night
 * (the morning you woke up). `duration_min` is total time asleep; the optional
 * stage breakdown (deep/rem/light/awake, in minutes) feeds the stacked bar when a
 * wearable provides it. `quality` is a 1-100 score. Bedtime/wake are clock times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sleep_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('slept_at')->index();
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedTinyInteger('quality')->nullable();   // 1-100
            $table->unsignedSmallInteger('deep_min')->nullable();
            $table->unsignedSmallInteger('rem_min')->nullable();
            $table->unsignedSmallInteger('light_min')->nullable();
            $table->unsignedSmallInteger('awake_min')->nullable();
            $table->time('bedtime')->nullable();
            $table->time('wake_time')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sleep_logs');
    }
};
