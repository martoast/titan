<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal API tokens — the bearer credentials that let an external assistant (e.g. a Claude/MCP
 * client) act on a user's Titan account without a browser session. Mirrors the device-pairing
 * scheme: the 40-char secret is shown ONCE at creation; only its sha256 is stored. Abilities
 * scope what a token may do (read-only vs read-write); '*' = full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('name');                          // human label, e.g. "Claude on my laptop"
            $table->string('token_hash')->unique();          // sha256 of the plaintext bearer
            $table->json('abilities')->nullable();           // ['*'] or ['read','write']
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
