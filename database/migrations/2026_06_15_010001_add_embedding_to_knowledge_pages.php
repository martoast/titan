<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_pages', function (Blueprint $table) {
            // Cached embedding vector (JSON) + hash of the embed source so we can
            // skip re-embedding unchanged pages.
            $table->json('embedding')->nullable()->after('is_pinned');
            $table->string('embed_hash', 32)->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embed_hash']);
        });
    }
};
