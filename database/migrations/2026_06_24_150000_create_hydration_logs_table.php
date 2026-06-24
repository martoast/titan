<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-day hydration entries; summed against a bodyweight-based daily target. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hydration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('logged_on')->index();
            $table->unsignedInteger('amount_ml');
            $table->string('source')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'logged_on']);
        });
    }

    public function down(): void { Schema::dropIfExists('hydration_logs'); }
};
