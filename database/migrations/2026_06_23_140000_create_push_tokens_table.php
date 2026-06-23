<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APNs / FCM device push tokens, one row per (user, device token). The native iOS app
 * registers its APNs token here after login so the Laravel scheduler/coach can push nudges,
 * briefings, and "band synced" notifications — replacing the browser-only Web Push/VAPID path
 * for mobile. See tasks/native-ios/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16)->default('ios');   // ios | android
            $table->string('token');                          // APNs device token (hex) / FCM token
            $table->string('environment', 16)->default('production'); // production | sandbox
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'token']);            // a device token maps to one row
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
