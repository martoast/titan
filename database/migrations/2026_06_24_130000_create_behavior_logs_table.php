<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The behavior journal — the labeling layer for Titan's correlation engine. Each row is one
 * lifestyle factor the user logged for a given day (alcohol, caffeine-late, stress, meditation…).
 * A nightly job joins these to next-day recovery/sleep to learn "what helps / hurts YOU".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->date('logged_on')->index();
            $table->string('key');                       // catalog key, e.g. "alcohol"
            $table->decimal('value', 8, 2)->nullable();  // optional magnitude (e.g. drinks); null = simple yes
            $table->string('source')->nullable();        // coach | app
            $table->timestamps();

            $table->unique(['profile_id', 'logged_on', 'key']);
            $table->index(['profile_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_logs');
    }
};
