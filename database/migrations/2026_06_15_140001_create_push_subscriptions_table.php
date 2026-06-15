<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A browser Web Push endpoint for a profile — one row per PushManager subscription
 * (a profile can have several: phone, laptop, etc.). `endpoint` is the push service
 * URL; `p256dh` + `auth` are the client encryption keys VAPID needs to sign payloads.
 *
 * Push endpoints can be long (well past an indexable varchar), so we key uniqueness off
 * `endpoint_hash` (sha256 of the endpoint, set by the model) which is cross-DB safe —
 * re-subscribing the same browser upserts rather than duplicating. Dead endpoints
 * (404/410 on send) are pruned by WebPushService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
