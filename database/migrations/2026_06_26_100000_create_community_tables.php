<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The community layer: opt-in social + follow graph + kudos/comments + achievements.
 * Everything hangs off `profiles` (the hub), mirroring the rest of the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Opt-in + identity on the profile ------------------------------------------------
        Schema::table('profiles', function (Blueprint $table) {
            // Master switch. Off ⇒ invisible everywhere, un-followable, activities never shared.
            $table->boolean('community_enabled')->default(false)->after('coach_tone');
            $table->string('username')->nullable()->unique()->after('display_name');
            $table->string('bio', 280)->nullable()->after('username');
            $table->string('avatar_path')->nullable()->after('bio');
            // Private account ⇒ follow requests sit `pending` until approved.
            $table->boolean('followers_require_approval')->default(true)->after('avatar_path');
            // Default visibility a new activity inherits when its own `visibility` is null.
            $table->string('default_activity_visibility', 16)->default('followers')->after('followers_require_approval');
        });

        // --- Per-activity visibility override (null ⇒ inherit the profile default) -----------
        Schema::table('activity_sessions', function (Blueprint $table) {
            $table->string('visibility', 16)->nullable()->after('source');
        });

        // --- Follow graph: follower → followee, with an approval gate ------------------------
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('followee_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('status', 16)->default('accepted');   // pending | accepted
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['follower_id', 'followee_id']);
            $table->index(['followee_id', 'status']);   // "who follows me / pending requests"
            $table->index(['follower_id', 'status']);   // "who I follow" (feed + leaderboard scope)
        });

        // --- Kudos (the like / cheer) -------------------------------------------------------
        Schema::create('kudos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('activity_session_id')->constrained('activity_sessions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['profile_id', 'activity_session_id']);
            $table->index('activity_session_id');
        });

        // --- Comments -----------------------------------------------------------------------
        Schema::create('activity_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('activity_session_id')->constrained('activity_sessions')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index('activity_session_id');
        });

        // --- Achievements / badges (one row per earned milestone) ---------------------------
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('key');                          // e.g. first_10k, distance_100k_month
            $table->foreignId('activity_session_id')->nullable()->constrained('activity_sessions')->nullOnDelete();
            $table->timestamp('awarded_at');
            $table->json('meta')->nullable();               // {value, period, ...} for display
            $table->timestamps();
            $table->unique(['profile_id', 'key']);          // each milestone earned once
            $table->index(['profile_id', 'awarded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('activity_comments');
        Schema::dropIfExists('kudos');
        Schema::dropIfExists('follows');
        Schema::table('activity_sessions', fn (Blueprint $t) => $t->dropColumn('visibility'));
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'community_enabled', 'username', 'bio', 'avatar_path',
                'followers_require_approval', 'default_activity_visibility',
            ]);
        });
    }
};
