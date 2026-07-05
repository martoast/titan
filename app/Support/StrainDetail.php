<?php

namespace App\Support;

use App\Models\ActivitySession;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The Whoop-style Strain screen: today's Day Strain (0-21) building through the day, the
 * recovery-based TARGET band (push / maintain / hold back), and the workouts that drove it — each
 * with the strain it added. Strain is a logarithmic function of cumulative cardiovascular + ambient
 * LOAD (same curve as Strain::assess), so a hard session early adds a lot and later ones add less —
 * exactly Whoop's "strain gets harder to raise as the day goes on".
 */
class StrainDetail
{
    private const MAX = 21.0;
    private const K = 90.0;   // load→strain scale (matches Strain::K)

    /** @return array<string,mixed> */
    public static function forProfile(Profile $profile, ?Carbon $day = null): array
    {
        $day = $day ?? Carbon::today();
        $base = Strain::assess($profile, $day);   // strain, load, target, status, advice, readiness, band

        $sessions = ActivitySession::where('profile_id', $profile->id)
            ->whereDate('started_at', $day)
            ->orderBy('started_at')
            ->get();

        // Ambient (a day of living) as a load floor, accrued evenly across waking hours.
        $act = $profile->dailyActivity()->whereDate('date', $day)->first();
        $ambientLoad = max((float) ($act->mvpa_min ?? 0) * 0.8, (int) ($act->steps ?? 0) * 0.004);

        // Build the cumulative strain CURVE: start of day → each workout end → now. Each point is the
        // strain implied by cumulative (workout + ambient-so-far) load, so it steps up at each session.
        $startOfDay = $day->copy()->startOfDay();
        $now = Carbon::now();
        $dayFrac = fn (Carbon $t) => max(0.0, min(1.0, $startOfDay->diffInSeconds($t) / 86400.0));

        $curve = [['t' => $startOfDay->toIso8601ZuluString(), 'strain' => 0.0]];
        $workoutLoad = 0.0;
        $contributions = [];
        $prevStrain = 0.0;
        foreach ($sessions as $s) {
            $trimp = (float) ($s->trimp ?? 0);
            $workoutLoad += $trimp;
            $end = $s->ended_at ? Carbon::parse($s->ended_at) : Carbon::parse($s->started_at)->addMinutes((int) ($s->duration_min ?? 0));
            $ambientSoFar = $ambientLoad * $dayFrac($end);
            $strainAt = self::strain($workoutLoad + $ambientSoFar);
            $curve[] = ['t' => $end->toIso8601ZuluString(), 'strain' => round($strainAt, 1)];
            $contributions[] = [
                'id' => $s->id,
                'title' => $s->title(),
                'activity_type' => $s->activity_type,
                'started_at' => $s->started_at,
                'duration_min' => $s->duration_min,
                'avg_hr' => $s->avg_hr,
                'trimp' => $trimp ?: null,
                'strain_added' => round(max(0.0, $strainAt - $prevStrain), 1),
            ];
            $prevStrain = $strainAt;
        }
        // Final point at "now" (ambient keeps trickling up after the last workout).
        $curve[] = ['t' => $now->toIso8601ZuluString(), 'strain' => (float) ($base['strain'] ?? 0)];

        return [
            'strain' => $base['strain'] ?? 0,
            'max' => self::MAX,
            'band' => $base['band'] ?? null,
            'label' => $base['label'] ?? null,
            'target' => $base['target'] ?? null,     // {low, high, mode, label}
            'status' => $base['status'] ?? null,
            'advice' => $base['advice'] ?? null,
            'readiness' => $base['readiness'] ?? null,
            // The concrete session to reach target — {minutes, zone, label, strain_to_go} or null.
            'suggestion' => (is_array($base['target'] ?? null))
                ? Strain::sessionForTarget((float) ($base['strain'] ?? 0), $base['target']) : null,
            'curve' => $curve,                        // [{t, strain}] cumulative through the day
            'contributions' => $contributions,        // per-workout strain
        ];
    }

    private static function strain(float $load): float
    {
        return round(self::MAX * (1 - exp(-$load / self::K)), 1);
    }
}
