<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Daily Strain (0-21) + the Strain Coach -- the "how hard should I go today" half of the daily loop.
 *
 * Strain is the day's accumulated cardiovascular/metabolic load mapped onto a 0-21 scale (Whoop-style,
 * Borg-flavoured), so a rest day sits low and a double session sits near the top. We build the load
 * from what we actually measure: each logged workout's Banister TRIMP plus ambient activity (MVPA
 * minutes / steps), then compress it logarithmically (load saturates -- going from hard to brutal moves
 * the number less than easy to moderate).
 *
 * The COACH closes the loop with the morning Readiness score: well-recovered → a higher target ("push"),
 * run-down → a low target ("restrain", protect recovery). It's the guidance the readiness score is
 * begging for. Honest scope: a relative training-guidance heuristic (like TRIMP/ACWR), not a validated
 * physiological unit -- the value is the daily push/rest decision, not the absolute number.
 */
class Strain
{
    private const K = 90.0;            // load → strain compression constant (tuned for sane day values)
    private const MAX = 21.0;

    /**
     * @return array{strain:float,load:float,band:string,label:string,
     *   target:array{low:float,high:float,mode:string,label:string},
     *   status:string,advice:string,readiness:?int}
     */
    public static function assess(Profile $profile, ?Carbon $day = null, ?string $tz = null): array
    {
        // Strain accrues over the user's LOCAL calendar day. Without a tz the "day" is UTC — for a
        // user hours off UTC that window flips mid-afternoon and the number collapses every evening
        // (looks frozen/wrong). Callers that don't care (coach tools, nudges) omit tz → UTC as before.
        [$dayStart, $startUtc, $endUtc] = self::dayBounds($day, $tz);

        // Workout load: sum of TRIMP for real-training sessions that STARTED on this local day. The
        // ->training() filter matches StrainDetail's per-session breakdown exactly, so the strain RING and
        // the list that explains it never disagree (and a passively-imported 2-min walk adds no strain).
        $workout = (float) $profile->activitySessions()
            ->where('started_at', '>=', $startUtc)
            ->where('started_at', '<', $endUtc)
            ->training()
            ->sum('trimp');

        // Ambient load: a day of living. MVPA minutes are the strongest signal; fall back to steps.
        $act = $profile->dailyActivity()->whereDate('date', $dayStart->toDateString())->first();
        $mvpa = (float) ($act->mvpa_min ?? 0);
        $steps = (int) ($act->steps ?? 0);
        $ambient = max($mvpa * 0.8, $steps * 0.004);

        // All-day HR load (the 24/7 stream, Whoop-style): Banister TRIMP over the day's hr_samples that
        // fall OUTSIDE a logged workout (workout minutes are already in $workout). Resting minutes sit at
        // ~0 HRR → ~0 TRIMP, so a quiet day stays light; sustained elevated HR (a hike, a hard commute)
        // accrues real strain even with no "workout" logged. Taken as the STRONGER of {step/MVPA ambient,
        // HR load} rather than summed, so a brisk walk that lifts both steps and HR isn't double-counted.
        $hrLoad = self::hrZoneLoad($profile, $dayStart, $startUtc, $endUtc);

        $load = $workout + max($ambient, $hrLoad);
        $strain = round(self::MAX * (1 - exp(-$load / self::K)), 1);
        [$band, $label] = self::band($strain);

        // Coach: target band from this morning's readiness.
        $readiness = Readiness::compute($profile, $dayStart->toDateString())['score'] ?? null;
        $target = self::targetFor($readiness);
        [$status, $advice] = self::coach($strain, $target, $readiness);

        return [
            'strain' => $strain,
            'load' => round($load, 1),
            'band' => $band,
            'label' => $label,
            'target' => $target,
            'status' => $status,
            'advice' => $advice,
            'readiness' => $readiness !== null ? (int) round($readiness) : null,
        ];
    }

    /**
     * All-day cardiovascular load from the 24/7 HR stream, as Banister TRIMP summed over the day's
     * hr_samples that fall OUTSIDE any logged workout window (those are already counted as $workout).
     *
     * Each sample is ~1 minute (the trend is bucketed to 1/min upstream). Per-minute TRIMP =
     * HRR · gender_coef · e^(gender_exp · HRR), where HRR = (bpm − rest) / (max − rest) is the Karvonen
     * heart-rate reserve, clamped to [0,1]. At rest bpm ≈ restHR → HRR ≈ 0 → ~0 load, so a quiet day
     * doesn't inflate. maxHR = Tanaka (208 − 0.7·age); restHR = the latest sealed resting HR (fallback 60).
     */
    private static function hrZoneLoad(Profile $profile, Carbon $dayStart, Carbon $startUtc, Carbon $endUtc): float
    {
        // hr_samples.recorded_at is stored as the owner's LOCAL wall-clock (see DeviceIngestionService::
        // writeHrTrend), so it's queried by the local-day range — same convention as MobileHrController —
        // NOT the UTC instants used for the (UTC-stored) workout/activity tables.
        $samples = $profile->hrSamples()
            ->whereBetween('recorded_at', [$dayStart->copy()->startOfDay(), $dayStart->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'bpm']);
        if ($samples->isEmpty()) {
            return 0.0;
        }

        // Workout windows to exclude (their load is $workout). started_at..ended_at (fallback +duration).
        $windows = $profile->activitySessions()
            ->where('started_at', '<', $endUtc)
            ->where(fn ($q) => $q->where('ended_at', '>=', $startUtc)->orWhereNull('ended_at'))
            ->get(['started_at', 'ended_at', 'duration_min'])
            ->map(fn ($s) => [
                $s->started_at,
                $s->ended_at ?? $s->started_at->copy()->addMinutes((int) ($s->duration_min ?? 0)),
            ]);
        $inWorkout = function (Carbon $t) use ($windows): bool {
            foreach ($windows as [$a, $b]) {
                if ($t >= $a && $t < $b) {
                    return true;
                }
            }

            return false;
        };

        $age = $profile->birthdate ? (int) $profile->birthdate->age : 35;
        $maxHr = 208.0 - 0.7 * $age;                                  // Tanaka
        $rest = (float) ($profile->recoveryLogs()->whereNotNull('resting_hr')
            ->latest('logged_at')->value('resting_hr') ?: 60);
        $reserve = max(1.0, $maxHr - $rest);
        // Female coefficients (Banister); male otherwise. sex stored 'F'/'M' (nullable → male default).
        $female = strtoupper((string) $profile->sex) === 'F';
        [$coef, $exp] = $female ? [0.86, 1.67] : [0.64, 1.92];

        $load = 0.0;
        foreach ($samples as $s) {
            if ($s->bpm <= 0 || $inWorkout($s->recorded_at)) {
                continue;
            }
            $hrr = min(1.0, max(0.0, ((float) $s->bpm - $rest) / $reserve));
            $load += $hrr * $coef * exp($exp * $hrr);                 // ~1 min per sample
        }

        return $load;
    }

    /**
     * A concrete session that would carry you from your current strain to the bottom of today's target
     * — Whoop's "a 30-min Z2 run gets you there". Inverts the strain curve to the load gap, then reads
     * off minutes at a training-zone TRIMP rate (small gap → easy Z2; bigger → a tempo Z3). Returns null
     * when you're already in/above the band (nothing to add).
     *
     * @param  array{low:float,high:float,mode:string,label:string}  $target
     * @return array{minutes:int, zone:string, label:string, strain_to_go:float}|null
     */
    public static function sessionForTarget(float $strain, array $target): ?array
    {
        if ($strain >= $target['low']) {
            return null;
        }
        // load = -K·ln(1 − strain/MAX); the gap is the extra TRIMP-load needed to reach target.low.
        $loadAt = fn (float $s) => -self::K * log(max(1e-6, 1.0 - min($s, self::MAX - 0.1) / self::MAX));
        $gapLoad = max(0.0, $loadAt($target['low']) - $loadAt($strain));
        if ($gapLoad <= 0) {
            return null;
        }
        // Banister TRIMP/min ≈ intensity·0.64·e^(1.92·intensity). Z2 (0.60 HRR) ≈ 1.2/min; a Z3 tempo
        // (0.75) ≈ 2.0/min. Use the easy zone unless it'd need > 60 min, then suggest the tempo zone.
        $rateZ2 = 0.60 * 0.64 * exp(1.92 * 0.60);
        $rateZ3 = 0.75 * 0.64 * exp(1.92 * 0.75);
        $minsZ2 = (int) (round(($gapLoad / $rateZ2) / 5) * 5);
        if ($minsZ2 <= 60) {
            return ['minutes' => max(15, $minsZ2), 'zone' => 'Z2', 'label' => 'easy aerobic',
                'strain_to_go' => round($target['low'] - $strain, 1)];
        }
        $minsZ3 = (int) (round(($gapLoad / $rateZ3) / 5) * 5);

        return ['minutes' => max(20, $minsZ3), 'zone' => 'Z3', 'label' => 'steady tempo',
            'strain_to_go' => round($target['low'] - $strain, 1)];
    }

    /**
     * Resolve a (local-day-start, UTC start, UTC end) triple for a strain window. `$day` may be any
     * instant on the target day (or null = today); `$tz` is the user's IANA zone (or null/invalid =
     * app default). started_at is stored UTC, so the [startUtc, endUtc) half-open range is what the
     * queries bind against.
     *
     * @return array{0:Carbon,1:Carbon,2:Carbon}
     */
    public static function dayBounds(?Carbon $day, ?string $tz): array
    {
        $zone = self::zone($tz);
        $dayStart = ($day ? $day->copy() : Carbon::now($zone))->setTimezone($zone)->startOfDay();

        return [$dayStart, $dayStart->copy()->utc(), $dayStart->copy()->addDay()->utc()];
    }

    private static function zone(?string $tz): string
    {
        if ($tz === null || $tz === '') {
            return (string) config('app.timezone', 'UTC');
        }
        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    /** Recovery-driven target strain band. */
    public static function targetFor(?float $readiness): array
    {
        return match (true) {
            $readiness === null => ['low' => 9.0, 'high' => 14.0, 'mode' => 'maintain', 'label' => 'Maintain'],
            $readiness >= 67 => ['low' => 14.0, 'high' => 18.0, 'mode' => 'push', 'label' => 'Primed to push'],
            $readiness >= 34 => ['low' => 9.0, 'high' => 14.0, 'mode' => 'maintain', 'label' => 'Maintain'],
            default => ['low' => 4.0, 'high' => 9.0, 'mode' => 'restrain', 'label' => 'Hold back'],
        };
    }

    /** @return array{0:string,1:string} [status, advice] */
    private static function coach(float $strain, array $target, ?float $readiness): array
    {
        if ($strain < $target['low']) {
            return match ($target['mode']) {
                'restrain' => ['under', "You're under your easy ceiling -- good. Keep it light and let recovery come back."],
                'push' => ['under', sprintf('Room to push -- about %.0f more strain to hit your target. Your body is primed for it today.', $target['low'] - $strain)],
                default => ['under', sprintf('A bit more would hit your target (~%.0f to go). A solid session fits well today.', $target['low'] - $strain)],
            };
        }
        if ($strain <= $target['high']) {
            return ['on_target', 'On target for how recovered you are today. Nicely balanced.'];
        }
        // Above the target band.
        return $target['mode'] === 'restrain'
            ? ['over', "Above your target on a low-recovery day -- ease off and prioritise sleep tonight."]
            : ['over', "You've cleared your target -- strong day. Anything more is a bonus; guard recovery."];
    }

    /** @return array{0:string,1:string} */
    private static function band(float $strain): array
    {
        return match (true) {
            $strain >= 18 => ['all_out', 'All-out'],
            $strain >= 14 => ['high', 'High'],
            $strain >= 8 => ['moderate', 'Moderate'],
            default => ['light', 'Light'],
        };
    }
}
