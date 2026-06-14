<?php

use App\Models\Meal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One ingredient/component line of a meal — the editable breakdown the user corrects
 * after the AI vision estimate (meals run ~10-25% off, so per-item correction matters).
 * A meal's totals are the sum of its items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Meal::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('quantity')->nullable();        // e.g. "1 cup", "150 g"
            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 1)->default(0);
            $table->decimal('carbs_g', 6, 1)->default(0);
            $table->decimal('fat_g', 6, 1)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_items');
    }
};
