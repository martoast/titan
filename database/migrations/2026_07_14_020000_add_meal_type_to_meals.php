<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEAL_LOGGING_REVISION 3.5 — meal-type grouping (breakfast / lunch / dinner / snack). Nullable + inferred
 * from time-of-day on create, user-overridable. Sections the day list (was a flat time-ordered list) and
 * unlocks "add to breakfast" + cleaner templates. Nullable so old meals stay ungrouped rather than
 * mis-labelled; the reader infers on the fly for display when the column is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->string('meal_type', 16)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->dropColumn('meal_type');
        });
    }
};
