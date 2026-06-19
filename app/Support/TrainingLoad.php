<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Training load + the acute:chronic workload ratio (ACWR) -- a "do no harm" guardrail.
 *
 * We are nudging sedentary people to move more; the matching duty of care is not to let them ramp so
 * fast they get hurt and quit. Each workout already carries a Banister TRIMP (heart-rate training
 * impulse). ACWR compares the ACUTE load (roughly the last week) to the CHRONIC load (roughly the last
 * month, "what your body is adapted to"). A sharp spike -- doing far more this week than you've built up
 * to -- is the classic over-reaching pattern.
 *
 * We use the EWMA formulation (Williams et al. 2017, Br J Sports Med), which weights recent days more
 * and decays old load, rather than the older flat rolling average (Hulin/Gabbett) -- it tracks the
 * decaying nature of fitness/fatigue better and avoids the rolling window's edge artefacts.
 *
 * HONEST SCOPE -- this is training-load GUIDANCE, not an injury prediction. The "sweet spot ~0.8-1.3 /
 * danger >1.5" bands come from team-sport cohorts and their individual predictive validity is genuinely
 * debated (Impellizzeri 2020; Lolli 2019 flag the ratio's mathematical coupling). What survives the
 * critique is the uncontroversial principle underneath: progress load gradually, don't spike it. We
 * surface ACWR as that gentle progressive-overload check, in wellness language, never as a diagnosis.
 */
class TrainingLoad
{
    private const LOOKBACK_DAYS = 42;   // daily series length (gives the 28-day EWMA a ~2-week warmup)
    private const ACUTE_DAYS = 7;
    private const CHRONIC_DAYS = 28;
    private const MIN_HISTORY_DAYS = 14; // need ≥2 weeks since the first session before ACWR is meaningful
    private const MIN_CHRONIC = 5.0;     // and a non-trivial chronic load, else the ratio is unstable

    private const SWEET_LOW = 0.8;
    private const SWEET_HIGH = 1.3;
    private const CAUTION_HIGH = 1.5;

    /**
     * @param  Collection<int,\App\Models\ActivitySession>  $sessions  the profile's activity sessions
     * @return array{acute:float,chronic:float,acwr:float|null,band:string,label:string,advice:string,
     *               week_trimp:int,history_days:int,sufficient:bool}|null
     */
    public static function assess(Collection $sessions, ?CarbonImmutable $asOf = null): ?array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();
        $start = $asOf->subDays(self::LOOKBACK_DAYS - 1);

        $daily = array_fill(0, self::LOOKBACK_DAYS, 0.0);  // index 0 = oldest day
        $firstIdx = null;
        foreach ($sessions as $s) {
            $when = $s->started_at ? CarbonImmutable::parse($s->started_at)->startOfDay() : null;
            $trimp = (float) ($s->trimp ?? 0);
            if (! $when || $trimp <= 0 || $when->lt($start) || $when->gt($asOf)) {
                continue;
            }
            $idx = (int) $start->diffInDays($when);
            if ($idx < 0 || $idx >= self::LOOKBACK_DAYS) {
                continue;
            }
            $daily[$idx] += $trimp;
            $firstIdx = $firstIdx === null ? $idx : min($firstIdx, $idx);
        }
        if ($firstIdx === null) {
            return null;  // no load at all in the window
        }

        // EWMA of daily load: λ = 2/(N+1); warm up across the whole series so the read at `asOf` is stable.
        $la = 2.0 / (self::ACUTE_DAYS + 1);
        $lc = 2.0 / (self::CHRONIC_DAYS + 1);
        $acute = $chronic = 0.0;
        for ($i = 0; $i < self::LOOKBACK_DAYS; $i++) {
            $acute = $daily[$i] * $la + $acute * (1 - $la);
            $chronic = $daily[$i] * $lc + $chronic * (1 - $lc);
        }

        $historyDays = self::LOOKBACK_DAYS - $firstIdx;
        $weekTrimp = (int) round(array_sum(array_slice($daily, self::LOOKBACK_DAYS - self::ACUTE_DAYS)));
        $sufficient = $historyDays >= self::MIN_HISTORY_DAYS && $chronic >= self::MIN_CHRONIC;

        if (! $sufficient) {
            return [
                'acute' => round($acute, 1), 'chronic' => round($chronic, 1), 'acwr' => null,
                'band' => 'building', 'label' => 'Building your baseline',
                'advice' => 'Keep logging workouts -- once you have ~2 weeks of history we can track whether '
                    .'your load is progressing safely.',
                'week_trimp' => $weekTrimp, 'history_days' => $historyDays, 'sufficient' => false,
            ];
        }

        $acwr = $chronic > 0 ? $acute / $chronic : null;
        [$band, $label, $advice] = self::band($acwr);

        return [
            'acute' => round($acute, 1),
            'chronic' => round($chronic, 1),
            'acwr' => $acwr !== null ? round($acwr, 2) : null,
            'band' => $band,
            'label' => $label,
            'advice' => $advice,
            'week_trimp' => $weekTrimp,
            'history_days' => $historyDays,
            'sufficient' => true,
        ];
    }

    /**
     * @return array{0:string,1:string,2:string}  [band, label, advice]
     */
    private static function band(?float $acwr): array
    {
        if ($acwr === null) {
            return ['building', 'Building your baseline', 'Keep logging workouts to track your load.'];
        }
        if ($acwr < self::SWEET_LOW) {
            return ['detraining', 'Load tapering',
                'Your training load is dropping. Fine for a recovery week -- but sustained low load slowly '
                .'gives back the fitness you built. A couple of easy sessions keeps the base.'];
        }
        if ($acwr <= self::SWEET_HIGH) {
            return ['optimal', 'Sweet spot',
                'Your load is well-matched to what your body is adapted to. Keep progressing gradually.'];
        }
        if ($acwr <= self::CAUTION_HIGH) {
            return ['caution', 'Ramping up',
                "You're building load faster than usual. That's how fitness grows -- just keep the climb "
                .'gradual (~10% a week) and watch for niggles.'];
        }
        return ['high', 'Sharp spike',
            "This week's load jumped well above what you've built up to. Ease back for a few days -- the "
            .'biggest injury risk is doing too much too soon. Your body adapts on the easy days.'];
    }
}
