<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dream physique is no longer a single front shot — a front photo can't show a glute or
 * leg goal at all. We now capture up to three angles (front / back / side) and render each.
 * `shots` holds the full set: [{angle, source, goal}, …] (paths on the public disk).
 * `source_photo_path` / `goal_image_path` stay as the PRIMARY (front) shot so every existing
 * consumer — the % -to-goal bar, the living-goal morph, the coach, the dashboard — is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physique_goals', function (Blueprint $table) {
            $table->json('shots')->nullable()->after('goal_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('physique_goals', function (Blueprint $table) {
            $table->dropColumn('shots');
        });
    }
};
