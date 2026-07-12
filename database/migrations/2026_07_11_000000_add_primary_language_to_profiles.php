<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's chosen app language (i18n). Drives the coach's reply language + localized emails/pushes, and
 * mirrors the iOS in-app language picker. 'en' default; 'es-MX' = Mexican Spanish. A column (not settings)
 * so a batch job can query "all Spanish users" cheaply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('primary_language', 12)->default('en')->after('coach_tone');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('primary_language');
        });
    }
};
