<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('calories')->nullable();
            $table->decimal('protein_g', 5, 1)->nullable();
            $table->decimal('carbs_g', 5, 1)->nullable();
            $table->decimal('fat_g', 5, 1)->nullable();
            $table->json('ingredients')->nullable();   // string[]
            $table->json('extras')->nullable();        // string[] — items to buy (not on hand)
            $table->json('steps')->nullable();         // string[]
            $table->string('image_path')->nullable();  // generated meal photo (public disk)
            $table->string('context')->nullable();     // why it was suggested, e.g. "next meal · 50g protein"
            $table->timestamps();
            $table->index(['profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_suggestions');
    }
};
