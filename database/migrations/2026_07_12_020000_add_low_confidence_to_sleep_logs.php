<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Honesty flag for a low-signal night. A DIY optical band loses skin contact far more than a commercial
 * one (loose fit, wrist position, cold), so a night can seal on mostly-NODATA coverage — or the stager
 * can return a physiologically implausible stage split (d85f377). Either way the number must be presented
 * as an ESTIMATE the app/coach caveat ("we could only confirm ~4h — sensor contact was low, check your
 * fit"), never as a confident fact. Set by SealNightJob; surfaced by the sleep readers + the coach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->boolean('low_confidence')->default(false)->after('coverage');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->dropColumn('low_confidence');
        });
    }
};
