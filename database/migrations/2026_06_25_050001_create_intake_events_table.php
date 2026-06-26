<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An actual taken / skipped / extra dose — the dated log that powers adherence and the
 * coach's intake↔biosignal correlations. Fields are denormalized from stack_items (name,
 * dose, kind) so one-off doses (no parent item) and historical rows survive an item being
 * edited or removed. `slot` records which schedule slot a dose satisfied (morning/evening…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stack_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('kind')->default('supplement');    // supplement | medication | other
            $table->decimal('dose_amount', 10, 2)->nullable();
            $table->string('dose_unit')->nullable();
            $table->dateTime('taken_at');
            $table->string('status')->default('taken');        // taken | skipped | extra
            $table->string('source')->default('manual');       // manual | notification | coach | photo
            $table->string('slot')->nullable();                // morning | midday | evening | night | anytime
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'taken_at']);
            $table->index(['stack_item_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_events');
    }
};
