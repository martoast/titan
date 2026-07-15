<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEAL_LOGGING_REVISION 3.4 — multi-item meal templates. A remembered dish could only carry lumped
 * macros; now it can carry its ingredient breakdown so "my usual breakfast" (eggs + oats + coffee)
 * re-logs as one tap AND recreates the items. Nullable `items` json (null = a simple single-dish
 * memory, unchanged); `fiber_g` mirrors the meals column so the secondary stat survives the round-trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_templates', function (Blueprint $table) {
            $table->json('items')->nullable()->after('fat_g');
            $table->decimal('fiber_g', 6, 1)->nullable()->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('meal_templates', function (Blueprint $table) {
            $table->dropColumn(['items', 'fiber_g']);
        });
    }
};
