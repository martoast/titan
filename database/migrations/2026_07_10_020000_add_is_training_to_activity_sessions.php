<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist whether a session "counts as training" at seal time — the honest-finisher fix. Length alone
 * (duration_min >= 5) wrongly EXCLUDED a force-sealed 1–4-min real workout and wrongly ADMITTED a >=5-min
 * stray-motion 'other' blob. The seal now stamps is_training from the user-ended/manual/confirmed signal
 * (which already existed in flight but was never stored) plus a confidence check on auto-detected sessions.
 * Nullable so old rows fall back to the length rule in ActivitySession::scopeTraining.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->boolean('is_training')->nullable()->after('activity_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->dropColumn('is_training');
        });
    }
};
