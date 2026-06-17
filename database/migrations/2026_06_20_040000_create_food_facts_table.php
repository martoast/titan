<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shared food-knowledge cache. The first time any food is looked up, its real macros (from the web)
 * are extracted and stored here per-100g; every later lookup of that food — for any user, any portion —
 * is an instant cache hit with zero web/AI cost. So Titan builds up a library of the foods people eat
 * instead of re-researching the same chicken breast every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_facts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // normalized base food, e.g. "grilled chicken breast"
            $table->string('basis')->default('100g');   // what the macros are per
            $table->unsignedSmallInteger('calories');
            $table->decimal('protein_g', 6, 1);
            $table->decimal('carbs_g', 6, 1);
            $table->decimal('fat_g', 6, 1);
            $table->string('source')->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_facts');
    }
};
