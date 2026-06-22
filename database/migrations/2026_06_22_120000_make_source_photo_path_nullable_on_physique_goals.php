<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physique goals moved from on-the-fly AI render to pre-generated static model images
 * (see App\Support\PhysiqueModelImage). A goal no longer carries the user's uploaded
 * source photo or a generation prompt, so both `source_photo_path` and `prompt` must be
 * nullable — otherwise creating a goal throws an integrity-constraint violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physique_goals', function (Blueprint $table) {
            $table->string('source_photo_path')->nullable()->change();
            $table->text('prompt')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('physique_goals', function (Blueprint $table) {
            $table->string('source_photo_path')->nullable(false)->change();
            $table->text('prompt')->nullable(false)->change();
        });
    }
};
