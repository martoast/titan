<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split "we don't trust this night" from "we don't trust its stage breakdown".
 *
 * `low_confidence` means the SIGNAL was thin — poor contact, mostly holes, a degenerate sliver — so the
 * duration itself is an estimate, and SleepDebt/SleepWeek correctly refuse to score the night at all.
 *
 * A physiologically impossible stage LAYOUT is a different failure. Tester B's 2026-08-04 night was measured
 * fine (186 windows, no gap over 6.5 min): she really did sleep 9h12m, and that duration belongs in her
 * ledger. Only the deep/REM/light split is wrong. Flagging the whole night would have dropped a real 9.2h
 * night out of her debt (0.0h → 0.5h), her weekly score and her streak — trading one inaccuracy for
 * another.
 *
 * So the stage verdict gets its own column: the breakdown is presented as an estimate while the night keeps
 * counting for everything that only needs its duration.
 *
 * Backward-compatible (nullable-with-default, additive) so the blue-green overlap is safe — old code simply
 * ignores the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->boolean('stages_low_confidence')->default(false)->after('low_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->dropColumn('stages_low_confidence');
        });
    }
};
