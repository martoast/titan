<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coach memory — the atomic, durable facts a real coach carries about YOU: injuries and
 * limitations, equipment and gym access, schedule, food preferences and dislikes, exercises you
 * love or hate, what's worked for your body, life context, and the commitments you've made.
 *
 * Distinct from the Brain (KnowledgePage) wiki of health notes: these are short, categorized facts
 * that are ALWAYS injected into the coach's working memory so it never re-asks and weaves them into
 * every reply, program and nudge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('category')->default('misc');     // injury, preference, dislike, equipment, …
            $table->text('content');
            $table->unsignedTinyInteger('importance')->default(2);  // 1 low · 2 normal · 3 critical (always injected)
            $table->string('source')->default('coach');      // coach | user
            $table->timestamp('last_referenced_at')->nullable();
            $table->timestamp('archived_at')->nullable();     // soft "forget"
            $table->timestamps();

            $table->index(['profile_id', 'archived_at', 'importance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_memories');
    }
};
