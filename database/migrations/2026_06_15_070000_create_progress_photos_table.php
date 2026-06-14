<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dated progress photo for a profile. The raw material of the physique loop: the
 * gallery, the photo-to-photo comparison, the source for AI physique analysis, and
 * the frame the "living goal image" morphs forward. `photo_path` references the
 * public disk; `pose` (front/side/back) keeps comparisons honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('photo_path');                  // public disk
            $table->date('taken_at')->index();
            $table->string('pose')->nullable();            // front | side | back
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_photos');
    }
};
