<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titan Wearable · Phase 0 platform migration.
 *
 * 1. Generalizes `wearable_connections` from Terra-shaped to source-agnostic so the
 *    open-source band, Apple Health, Polar AccessLink and Terra all share one table.
 *    Adds: `source`, `device_token_hash` (sha256 of the per-device secret — the secret
 *    itself is shown once at pairing and never stored), `device_id` (the public id sent
 *    on every request), `timezone` (IANA tz so the server resolves the correct calendar
 *    date). `terra_user_id` becomes nullable (only Terra connections carry one).
 *
 * 2. Creates the `device_ingestions` ledger — one row per signed batch. Raw waveforms
 *    live in MinIO (`object_key`); this table is the queryable index + processing state
 *    machine. `batch_uid` is the idempotency key (UNIQUE → duplicate batches are no-ops).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wearable_connections', function (Blueprint $table) {
            // terra | titan_band | bangle | polar | apple_health
            $table->string('source')->default('terra')->after('provider');
            // sha256(per-device secret). Null for Terra (uses Terra's own signing secret).
            $table->string('device_token_hash', 64)->nullable()->after('source');
            // Public device identifier sent as X-Device-Id (e.g. "tb_01J…").
            $table->string('device_id')->nullable()->unique()->after('device_token_hash');
            // IANA timezone for this device's owner — drives slept_at/logged_at dates.
            $table->string('timezone')->nullable()->after('device_id');
            $table->timestamp('last_sync_at')->nullable()->after('last_webhook_at');

            $table->index(['profile_id', 'source']);
        });

        // terra_user_id was UNIQUE NOT NULL; make it nullable for non-Terra sources.
        // (The unique index already permits multiple NULLs in MySQL.)
        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->string('terra_user_id')->nullable()->change();
        });

        Schema::create('device_ingestions', function (Blueprint $table) {
            $table->id();
            $table->string('batch_uid', 50)->unique();                 // idempotency key (ULID + per-window suffix)
            $table->foreignIdFor(Profile::class)->constrained()->cascadeOnDelete();
            $table->string('source');                                   // titan_band | bangle | polar | apple_health
            $table->string('kind');                                     // ibi | ppg_raw | summary
            $table->string('object_key')->nullable();                   // raw/{profile}/{date}/{batch_uid}.ndjson.gz
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            // received | queued | processing | processed | failed | duplicate
            $table->string('status')->default('received');
            $table->string('algo_version')->nullable();                 // biosignal algorithm version that processed it
            $table->json('result_refs')->nullable();                    // {recovery_log_id, sleep_log_id, …}
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'created_at']);
            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_ingestions');

        Schema::table('wearable_connections', function (Blueprint $table) {
            $table->dropIndex(['profile_id', 'source']);
            $table->dropUnique(['device_id']);
            $table->dropColumn(['source', 'device_token_hash', 'device_id', 'timezone', 'last_sync_at']);
        });
    }
};
