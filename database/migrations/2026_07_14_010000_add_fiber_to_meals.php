<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEAL_LOGGING_REVISION 3.3 — fiber tracking. Fiber is table-stakes for a real tracker (MacroFactor/
 * Cronometer) and a genuine health signal, but it is NOT part of the calorie↔macro reconcile energy
 * math — it rides alongside as a secondary stat. Nullable so old meals stay null (unknown, not zero)
 * and the card can hide it when we don't have it. Structured so sugar/sodium/sat-fat follow the same
 * pattern later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->decimal('fiber_g', 6, 1)->nullable()->after('fat_g');
        });
        Schema::table('meal_items', function (Blueprint $table) {
            $table->decimal('fiber_g', 6, 1)->nullable()->after('fat_g');
        });
    }

    public function down(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->dropColumn('fiber_g');
        });
        Schema::table('meal_items', function (Blueprint $table) {
            $table->dropColumn('fiber_g');
        });
    }
};
