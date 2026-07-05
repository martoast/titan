<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A barcode (GTIN/EAN/UPC) is an exact product identifier — the perfect cache key. Scanning it once and
 * caching by barcode means the same product is never looked up twice, and there's no "two spellings, two
 * rows" ambiguity that a text key has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branded_foods', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('branded_foods', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }
};
