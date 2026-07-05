<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A watch-confirmed NAP is a bounded session (button start → button stop), not "a night". Two columns
 * let a nap live in sleep_logs alongside the real night on the SAME calendar date without clobbering
 * it (the (profile_id, slept_at) natural key collides otherwise):
 *   • session_start — the nap's exact bedtime datetime; naps are keyed on it (one row per session)
 *   • is_nap        — flags a nap so recovery math (SleepCoach debt/need) can exclude it while the
 *                     Sleep page still surfaces it
 * Both nullable/default so every existing night row is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->boolean('is_nap')->default(false)->index()->after('slept_at');
            $table->dateTime('session_start')->nullable()->after('is_nap');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->dropColumn(['is_nap', 'session_start']);
        });
    }
};
