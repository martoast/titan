<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Context compaction: when a conversation grows long, the older turns are condensed into a running
 * `summary` and `summary_through_id` marks how far it covers. The coach then replays the summary plus
 * the messages after the cutoff — so a single chat can run indefinitely without the token context
 * blowing up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('title');
            $table->unsignedBigInteger('summary_through_id')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_through_id']);
        });
    }
};
