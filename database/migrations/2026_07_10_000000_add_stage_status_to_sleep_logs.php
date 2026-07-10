<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Progressive summary (Whoop parity, Phase 1): a confirmed night is written first as a lightweight
// COMPUTING placeholder (duration/bed/wake from the envelope, stages null) the instant you end on the
// watch, then refined to FINAL when staging completes. `stage_status` lets the app show a loading card if
// you open before the finalize lands. `coverage` (already computed by the stager) surfaces how complete the
// night's sampling was; `finalized_at` marks when the settled read wrote. Default 'final' so every existing
// row and write path is unchanged. See docs/PROGRESSIVE_SUMMARY.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->enum('stage_status', ['computing', 'final'])->default('final')->after('quality');
            $table->decimal('coverage', 4, 3)->nullable()->after('stage_status');
            $table->timestamp('finalized_at')->nullable()->after('coverage');
        });
    }

    public function down(): void
    {
        Schema::table('sleep_logs', function (Blueprint $table) {
            $table->dropColumn(['stage_status', 'coverage', 'finalized_at']);
        });
    }
};
