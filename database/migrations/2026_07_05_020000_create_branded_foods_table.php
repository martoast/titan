<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared cache of PACKAGED products' official per-serving nutrition labels — so a branded item is
 * researched on the web ONCE, and every later scan (by anyone) of the same product is instant and free.
 * The per-serving analogue of `food_facts` (which caches generic ingredients per-100g). Keyed on a
 * normalized "brand product" string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branded_foods', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();        // normalized "brand product"
            $table->string('brand')->nullable();
            $table->string('product');
            $table->string('serving')->nullable();  // the label serving, e.g. "1 bottle (500 ml)"
            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 1)->default(0);
            $table->decimal('carbs_g', 6, 1)->default(0);
            $table->decimal('fat_g', 6, 1)->default(0);
            $table->string('source')->nullable();   // 'label' (read off the panel) or a web host
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branded_foods');
    }
};
