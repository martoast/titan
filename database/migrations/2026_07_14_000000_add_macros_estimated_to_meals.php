<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEAL_LOGGING_REVISION 3.6 — flag estimated macro splits. When a meal is logged with only calories
 * (or a couple of macros), Macros::reconcile INVENTS the missing macros to keep the day's energy
 * honest. A serious tracker shouldn't hide that: this nullable column records WHICH macros were
 * server-estimated (['protein','carbs','fat'] subset) so the meal card can show an honest "estimated"
 * chip and nudge the user to confirm. Null / empty = every macro came from the user or the photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->json('macros_estimated')->nullable()->after('fat_g');
        });
    }

    public function down(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->dropColumn('macros_estimated');
        });
    }
};
