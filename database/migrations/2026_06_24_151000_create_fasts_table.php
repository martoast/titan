<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A fasting window (start → end), with a goal length. At most one active (ended_at null) per profile. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fasts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->float('goal_hours')->default(16);
            $table->timestamps();
            $table->index(['profile_id', 'ended_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('fasts'); }
};
