<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cardio / wearable activity session (run, ride, walk…) sealed from the band's workout
 * windows. See SealActivityJob. Strength training lives in {@see Workout} instead.
 */
class ActivitySession extends Model
{
    protected $fillable = [
        'profile_id', 'source', 'visibility', 'started_at', 'ended_at', 'duration_min',
        'activity_type', 'activity_confidence',
        'distance_km', 'distance_source', 'avg_hr', 'max_hr', 'hr_source', 'hr_quality', 'workout_hrv_ms', 'hr_zones', 'trimp', 'calories_kcal',
        'vo2max', 'fitness_level', 'hrr_bpm', 'updated_via',
        // Run route + analytics (biosignal /process/route).
        'route_polyline', 'route_bounds', 'moving_time_s', 'avg_pace_s_per_km', 'gap_s_per_km',
        'elevation_gain_m', 'elevation_loss_m', 'elevation_profile', 'splits', 'best_efforts', 'relative_effort',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'activity_confidence' => 'float',
            'distance_km' => 'float',
            'hr_quality' => 'float',
            'workout_hrv_ms' => 'float',
            'hr_zones' => 'array',
            'trimp' => 'float',
            'vo2max' => 'float',
            'hrr_bpm' => 'float',
            'route_bounds' => 'array',
            'elevation_profile' => 'array',
            'splits' => 'array',
            'best_efforts' => 'array',
        ];
    }

    /** A run we have a drawable GPS route for (drives the map card vs a plain stat list). */
    public function hasRoute(): bool
    {
        return ! empty($this->route_polyline);
    }

    /**
     * Mapbox Static Images URL: the route polyline drawn over a dark map tile — the end-of-run
     * "picture" + the share card. `auto` fits the path; @2x for retina. Null without a route or token.
     * The Mapbox public token (pk.*) is designed to ship in client URLs.
     */
    public function staticMapUrl(int $w = 640, int $h = 360, string $stroke = 'f43f5e'): ?string
    {
        $token = config('services.mapbox.token');
        if (! $this->route_polyline || ! $token) {
            return null;
        }
        $w = max(1, min(1280, $w));
        $h = max(1, min(1280, $h));
        $path = 'path-4+'.$stroke.'-0.9('.rawurlencode($this->route_polyline).')';

        return "https://api.mapbox.com/styles/v1/mapbox/dark-v11/static/{$path}/auto/{$w}x{$h}@2x"
            .'?padding=42&access_token='.urlencode($token);
    }

    /** Pace (s per km) → "m:ss /km" or "m:ss /mi". `$perKm` false converts to miles. */
    public function formatPace(?int $secPerKm, bool $perKm = true): ?string
    {
        if (! $secPerKm || $secPerKm <= 0) {
            return null;
        }
        $s = $perKm ? $secPerKm : (int) round($secPerKm * 1.609344);

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60).($perKm ? ' /km' : ' /mi');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    // --- Community ----------------------------------------------------------------------------

    public function kudos(): HasMany
    {
        return $this->hasMany(Kudo::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ActivityComment::class);
    }

    /**
     * The effective visibility — the activity's own override, else the owner's profile default.
     * Needs the `profile` relation; loads it if absent.
     */
    public function effectiveVisibility(): string
    {
        if ($this->visibility) {
            return $this->visibility;
        }

        return $this->profile?->default_activity_visibility ?? 'followers';
    }

    /**
     * Scope to the activities a viewer is allowed to see in the community:
     *   - their own, always;
     *   - others' only if that owner has community enabled AND the effective visibility clears —
     *     `public` to anyone, `followers` only to an accepted follower. `private` never leaks.
     *
     * Effective visibility is `COALESCE(activity_sessions.visibility, profiles.default_…)`, so the
     * gate is expressed in SQL against a join on the owning profile.
     */
    /** Sessions that count as real TRAINING for streaks/strain/trends — excludes an unclassified 'other'
     *  blob and a sub-5-min bout, so a passively-imported 2-minute walk can't light a streak or add strain. */
    public function scopeTraining(Builder $query): Builder
    {
        return $query
            ->where('duration_min', '>=', 5)
            ->where(fn ($q) => $q->whereNull('activity_type')->orWhere('activity_type', '!=', 'other'));
    }

    public function scopeVisibleTo(Builder $query, Profile $viewer): Builder
    {
        $followingIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->where('status', Follow::ACCEPTED)
            ->pluck('followee_id');

        return $query
            ->join('profiles as owner', 'owner.id', '=', 'activity_sessions.profile_id')
            ->select('activity_sessions.*')
            ->where(function (Builder $q) use ($viewer, $followingIds) {
                $q->where('activity_sessions.profile_id', $viewer->id)
                    ->orWhere(function (Builder $shared) use ($followingIds) {
                        $eff = "COALESCE(activity_sessions.visibility, owner.default_activity_visibility)";
                        $shared->where('owner.community_enabled', true)
                            ->where(function (Builder $vis) use ($eff, $followingIds) {
                                $vis->whereRaw("$eff = 'public'")
                                    ->orWhere(function (Builder $f) use ($eff, $followingIds) {
                                        $f->whereRaw("$eff = 'followers'")
                                            ->whereIn('activity_sessions.profile_id', $followingIds);
                                    });
                            });
                    });
            });
    }

    /**
     * Honest label for how this session's HR was derived — drives the trust badge in the UI.
     * 'ppg_inmotion' = recomputed from raw PPG with motion-artifact suppression (accurate);
     * 'chest_strap'  = a paired BLE strap (reference-grade); 'onchip' = the wrist's bare register.
     */
    public function hrSourceLabel(): ?string
    {
        return match ($this->hr_source) {
            'chest_strap' => 'chest strap',
            'ppg_inmotion' => 'motion-corrected',
            'onchip' => 'wrist',
            default => null,
        };
    }

    /** Minutes in the hard zones (Z4+Z5, ≥80% HRmax) — what a strong lifting day actually shows. */
    public function hardZoneMin(): float
    {
        $z = $this->hr_zones ?? [];

        return round((float) ($z['z4'] ?? 0) + (float) ($z['z5'] ?? 0), 1);
    }

    /** A human label for the activity (e.g. "Run", "Ride"). */
    public function title(): string
    {
        return match ($this->activity_type) {
            'run' => 'Run',
            'walk' => 'Walk',
            'cycle' => 'Ride',
            'stairs' => 'Stairs',
            'strength' => 'Strength',
            'rest' => 'Rest',
            default => 'Workout',
        };
    }
}
