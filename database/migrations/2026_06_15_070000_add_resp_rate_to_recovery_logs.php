<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recovery_logs', function (Blueprint $table) {
            // Overnight resting respiratory rate (breaths/min), from PPG. A wellness trend
            // alongside HRV/RHR — not respiratory-event/apnea detection. Decimal: e.g. 14.8.
            $table->decimal('resp_rate', 4, 1)->nullable()->after('resting_hr');
        });
    }

    public function down(): void
    {
        Schema::table('recovery_logs', function (Blueprint $table) {
            $table->dropColumn('resp_rate');
        });
    }
};
