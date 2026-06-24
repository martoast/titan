<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make food corrections per-profile. The web-sourced cache stays SHARED (profile_id null) — public
 * nutrition data is the same for everyone and we don't want to re-research it. But a user's manual
 * correction ("my Costco ground beef is 250 cal") is theirs alone: a profile-scoped row that wins
 * for that profile only. So the global `name` uniqueness is replaced by uniqueness per (profile, name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_facts', function (Blueprint $table) {
            $table->dropUnique(['name']);                          // was globally unique
            $table->foreignId('profile_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['profile_id', 'name']);                // one entry per food per scope
            $table->index('name');                                // fuzzy lookups
        });
    }

    public function down(): void
    {
        Schema::table('food_facts', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'name']);
            $table->dropIndex(['name']);
            $table->dropConstrainedForeignId('profile_id');
            $table->unique('name');
        });
    }
};
