<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third, distinct kind of doubt: the band STOPPED RECORDING before the night ended.
 *
 * The two existing flags both describe a night we watched the whole way through and read badly —
 * `low_confidence` (the signal was thin) and `stages_low_confidence` (the deep/REM split is impossible).
 * Neither covers the case where the measurement simply ENDED: the band's battery died at 05:53 and the
 * seal, which cannot tell "the user woke" from "the band stopped", took that as her wake time.
 *
 * That was Tester B's 2026-08-16. It sealed as a believed 4.8h night, and because duration was trusted it
 * became her entire sleep debt (0.2h → 3.3h "moderate") and earned her a coaching line telling her to go
 * to bed earlier. She had slept normally.
 *
 * `truncated` records that the stored duration is a FLOOR, not the night. It always travels with
 * `low_confidence = 1` so every existing reader (SleepDebt, SleepWeek, the streak) already excludes it
 * through the mechanism they have — but it is a separate column because the app must be able to say WHY
 * ("your band ran out of battery", not "check your band fit"), and must refuse to print the truncated
 * duration as though it were her night.
 *
 * `wearable_connections.last_reboot_at` is the evidence. A band only reboots on power loss or a reflash,
 * and since the ingest's clock guard (DeviceIngestionService::batchClockOffsetSec) a reboot is detectable:
 * the band comes back with an un-synced clock. That is what lets us say the silence was a dead battery
 * rather than a wake.
 *
 * Both additive with defaults, so the blue-green overlap is safe — old code ignores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->boolean('truncated')->default(false)->after('stages_low_confidence');
        });

        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->timestamp('last_reboot_at')->nullable()->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->dropColumn('truncated');
        });

        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->dropColumn('last_reboot_at');
        });
    }
};
