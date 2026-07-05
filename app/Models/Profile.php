<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's health profile -- the hub of their Titan record. Relationships to every
 * domain are declared here so feature code never has to touch User. Each domain's
 * models are created by their respective build; the hasMany strings resolve lazily,
 * so declaring them ahead of the models existing is safe.
 */
class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'display_name', 'birthdate', 'sex', 'height_cm',
        'primary_goal', 'coach_tone', 'settings', 'onboarded_at',
        // Community
        'community_enabled', 'username', 'bio', 'avatar_path',
        'followers_require_approval', 'default_activity_visibility',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'height_cm' => 'decimal:1',
            'settings' => 'array',
            'onboarded_at' => 'datetime',
            'community_enabled' => 'boolean',
            'followers_require_approval' => 'boolean',
        ];
    }

    /** A friendly name for the community: the @username if set, else the display name. */
    public function communityName(): string
    {
        return $this->display_name ?: ($this->username ?: 'Athlete');
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // --- The brain (long-term memory wiki) ---
    public function knowledgePages(): HasMany
    {
        return $this->hasMany(KnowledgePage::class);
    }

    // --- Health data layer ---
    public function biomarkerReadings(): HasMany
    {
        return $this->hasMany(BiomarkerReading::class);
    }

    public function bodyMetrics(): HasMany
    {
        return $this->hasMany(BodyMetric::class);
    }

    // --- Nutrition ---
    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    /** The reusable meal memory — distinct dishes this profile eats, for one-tap re-logging. */
    public function mealTemplates(): HasMany
    {
        return $this->hasMany(MealTemplate::class);
    }

    // --- "What you take" — supplements & medications ---
    public function stackItems(): HasMany
    {
        return $this->hasMany(StackItem::class);
    }

    public function intakeEvents(): HasMany
    {
        return $this->hasMany(IntakeEvent::class);
    }

    public function interactionFlags(): HasMany
    {
        return $this->hasMany(InteractionFlag::class);
    }

    // --- Behavior journal ---
    public function behaviorLogs(): HasMany
    {
        return $this->hasMany(BehaviorLog::class);
    }

    public function behaviorImpacts(): HasMany
    {
        return $this->hasMany(BehaviorImpact::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function hydrationLogs(): HasMany
    {
        return $this->hasMany(HydrationLog::class);
    }

    public function fasts(): HasMany
    {
        return $this->hasMany(Fast::class);
    }

    // --- Training ---
    public function workouts(): HasMany
    {
        return $this->hasMany(Workout::class);
    }

    // --- Sleep / recovery ---
    public function sleepLogs(): HasMany
    {
        return $this->hasMany(SleepLog::class);
    }

    public function recoveryLogs(): HasMany
    {
        return $this->hasMany(RecoveryLog::class);
    }

    public function activitySessions(): HasMany
    {
        return $this->hasMany(ActivitySession::class);
    }

    public function dailyActivity(): HasMany
    {
        return $this->hasMany(DailyActivity::class);
    }

    public function hrSamples(): HasMany
    {
        return $this->hasMany(HrSample::class);
    }

    // --- Physique: progress photos + the living goal image ---
    public function progressPhotos(): HasMany
    {
        return $this->hasMany(ProgressPhoto::class);
    }

    public function physiqueGoals(): HasMany
    {
        return $this->hasMany(PhysiqueGoal::class);
    }

    public function livingGoalRenders(): HasMany
    {
        return $this->hasMany(LivingGoalRender::class);
    }

    public function mealSuggestions(): HasMany
    {
        return $this->hasMany(MealSuggestion::class);
    }

    // --- Coach ---
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    // --- Wearable / biosignal ingestion ---
    public function wearableConnections(): HasMany
    {
        return $this->hasMany(WearableConnection::class);
    }

    public function deviceIngestions(): HasMany
    {
        return $this->hasMany(DeviceIngestion::class);
    }

    public function menstrualCycles(): HasMany
    {
        return $this->hasMany(MenstrualCycle::class);
    }

    public function cycleLogs(): HasMany
    {
        return $this->hasMany(CycleLog::class);
    }

    public function trainingPrograms(): HasMany
    {
        return $this->hasMany(TrainingProgram::class);
    }

    public function coachMemories(): HasMany
    {
        return $this->hasMany(CoachMemory::class);
    }

    public function weeklySnapshots(): HasMany
    {
        return $this->hasMany(WeeklySnapshot::class);
    }

    // --- Community: follow graph + social ----------------------------------------------------

    /** Follow edges where I'm the follower (people I follow / requested). */
    public function followingLinks(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    /** Follow edges where I'm the followee (my followers / pending requests). */
    public function followerLinks(): HasMany
    {
        return $this->hasMany(Follow::class, 'followee_id');
    }

    /** Profiles I follow (any status — filter on the `status` pivot for accepted-only). */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'follows', 'follower_id', 'followee_id')
            ->withPivot('status', 'accepted_at')->withTimestamps();
    }

    /** Profiles who follow me. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'follows', 'followee_id', 'follower_id')
            ->withPivot('status', 'accepted_at')->withTimestamps();
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    /** Kudos I've given. */
    public function kudos(): HasMany
    {
        return $this->hasMany(Kudo::class);
    }

    /** IDs of the profiles I follow with an accepted edge — the feed + leaderboard scope. */
    public function acceptedFollowingIds(): array
    {
        return Follow::query()
            ->where('follower_id', $this->id)
            ->where('status', Follow::ACCEPTED)
            ->pluck('followee_id')->all();
    }

    /** My follow state toward another profile: 'accepted', 'pending', or null (not following). */
    public function followStateToward(Profile $other): ?string
    {
        return Follow::query()
            ->where('follower_id', $this->id)
            ->where('followee_id', $other->id)
            ->value('status');
    }

    public function isFollowing(Profile $other): bool
    {
        return $this->followStateToward($other) === Follow::ACCEPTED;
    }

    /** Convenience: the "other" profile in the duo (the brother). */
    public function duoPartner(): ?Profile
    {
        return static::where('id', '!=', $this->id)->first();
    }
}
