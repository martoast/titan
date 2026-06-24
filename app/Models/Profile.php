<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'height_cm' => 'decimal:1',
            'settings' => 'array',
            'onboarded_at' => 'datetime',
        ];
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

    // --- Behavior journal ---
    public function behaviorLogs(): HasMany
    {
        return $this->hasMany(BehaviorLog::class);
    }

    public function behaviorImpacts(): HasMany
    {
        return $this->hasMany(BehaviorImpact::class);
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

    /** Convenience: the "other" profile in the duo (the brother). */
    public function duoPartner(): ?Profile
    {
        return static::where('id', '!=', $this->id)->first();
    }
}
