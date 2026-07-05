<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lifecycle status for assistant messages generated in the BACKGROUND. When a user sends a
 * message the server persists a placeholder assistant row (`pending`) and a queue job fills it in
 * (`streaming` → `complete`, or `failed`). This is what lets a reply keep generating after the phone
 * suspends, and lets a reopened client tell a still-cooking reply from a finished one. Null on every
 * legacy/user/tool row — treated as already-complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // pending | streaming | complete | failed  (null = not a background turn / done)
            $table->string('status')->nullable()->after('content');
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
