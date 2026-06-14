<?php

use App\Models\Conversation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One message in a coach conversation. `role` is one of user|assistant|tool|system.
 * `content` holds the text (longtext — assistant answers + tool results can be large).
 * `tool_calls` stores the raw OpenAI tool-call payload when the assistant invoked tools,
 * so the thread can be reconstructed faithfully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Conversation::class)->constrained()->cascadeOnDelete();
            $table->string('role')->default('user'); // user | assistant | tool | system
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
