<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-scoped coach chats. A conversation is now keyed to ONE local calendar day per profile
 * (`day`), so the coach page reads like a journal you can scroll back through by date instead
 * of one endless thread. The unique index is what makes the find-or-create race-safe: the web
 * request and the eight proactive reaction jobs all resolve "today's chat" concurrently.
 *
 * `day` stays nullable so a pre-existing thread survives the migration untouched; the
 * `coach:backfill-day-chats` command is what re-files the old rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->date('day')->nullable()->after('profile_id');
            $table->unique(['profile_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'day']);
            $table->dropColumn('day');
        });
    }
};
