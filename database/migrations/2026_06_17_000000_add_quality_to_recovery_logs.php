<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-night signal-quality provenance for a sealed recovery read: how many windows fed
 * the whole-night aggregate vs were dropped as artifacts, and the validity flag. This is
 * what lets the coach say "HRV 68ms (2 of 9 windows were noisy and dropped)" instead of
 * presenting every number as equally trustworthy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recovery_logs', function (Blueprint $table) {
            $table->json('quality')->nullable()->after('updated_via');
        });
    }

    public function down(): void
    {
        Schema::table('recovery_logs', function (Blueprint $table) {
            $table->dropColumn('quality');
        });
    }
};
