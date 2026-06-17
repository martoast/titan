<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks when a profile finished the onboarding wizard. Null = not yet onboarded, which the
 * `onboarded` middleware uses to funnel a new user into /onboarding before anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });
    }
};
