<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a Titan profile to a wearable connected through Terra (the unified
 * wearable API). One row per connected device/provider. Terra identifies the
 * connection by `terra_user_id`; inbound webhooks are matched back to a profile
 * through it. Device-agnostic: provider is just a label (WHOOP, OURA, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wearable_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('provider')->default('WHOOP');     // WHOOP | OURA | GARMIN | APPLE | ...
            $table->string('terra_user_id')->unique();         // Terra's id for this connection
            $table->json('scopes')->nullable();
            $table->string('status')->default('connected');    // connected | error | disconnected
            $table->timestamp('last_webhook_at')->nullable();
            $table->string('last_payload_type')->nullable();   // sleep | daily | body | activity
            $table->timestamps();

            $table->index(['profile_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wearable_connections');
    }
};
