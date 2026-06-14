<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloodwork results over time — one row per marker per draw. Values are stored
 * raw with their unit; the `flag` (low/normal/high/optimal) is computed against
 * the App\Support\Biomarkers catalog at save time so charts + cards can colour
 * out-of-range markers without re-deriving ranges on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biomarker_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('marker');                  // catalog key, e.g. "testosterone"
            $table->decimal('value', 12, 4);
            $table->string('unit')->nullable();
            $table->date('taken_at');
            $table->string('source')->default('manual'); // manual | lab_upload
            $table->string('flag')->nullable();          // low | normal | high | optimal
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'marker', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biomarker_readings');
    }
};
