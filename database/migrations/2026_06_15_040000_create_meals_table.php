<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A logged meal for a profile. Macros are stored directly on the row (the sum of its
 * MealItems, or entered/estimated wholesale). `source` records how it was logged —
 * an AI photo estimate, free-text parse, or manual entry. `photo_path` references the
 * public disk. Meal-level macros are what the daily totals and trends are computed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->dateTime('eaten_at')->index();
            $table->string('name')->nullable();
            $table->string('photo_path')->nullable();      // public disk
            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 1)->default(0);
            $table->decimal('carbs_g', 6, 1)->default(0);
            $table->decimal('fat_g', 6, 1)->default(0);
            $table->string('source')->default('manual');   // photo | manual | text
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
