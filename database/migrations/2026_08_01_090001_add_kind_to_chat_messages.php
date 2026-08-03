<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What KIND of coach message this is. NULL = an ordinary chat turn (the user asked, the coach
 * answered). `briefing` / `reaction` mark the coach's PROACTIVE messages — the morning briefing,
 * the evening nudge, and the event-driven reactions (sleep sealed, workout sealed, meal logged…).
 *
 * These used to live in a hidden "Daily Briefings" thread; now they land inline in the day's chat,
 * so the UI needs to style them apart from a reply, and the model needs to know it said them
 * unprompted rather than in answer to something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('kind', 16)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
