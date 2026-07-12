<?php

namespace App\Support;

use App\Models\LongevitySnapshot;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The Titan Longevity Index — Whoop's "Healthspan / pace of aging" as ONE score, fused from ingredients we
 * already compute. It's synthesis, not new sensing: {@see BiologicalAge} already blends the PhenoAge blood
 * clock + fitness age + wearable levers into a biological age with confidence + per-lever year attributions.
 * This adds (1) the **Titan Age** framing, (2) **pace of aging** — are you aging faster or slower than the
 * calendar, from the stored history — and (3) the top LEVERS pulling you younger/older, so the number is
 * actionable, never a black box. Degrades honestly: no confident age off two inputs (BiologicalAge gates
 * that), and pace stays "building" until there's real history.
 */
class LongevityIndex
{
    /** Pace needs at least this many days of history between the first and last snapshot before we state a
     *  numeric rate — over a couple of weeks biological age barely moves and the ratio is pure noise. */
    private const PACE_MIN_SPAN_DAYS = 30;

    /**
     * @return array{
     *   titan_age: float, chronological_age: float, delta: float, band: string, label: string,
     *   confidence: string, partial: bool, missing: array<int,string>,
     *   pace: array{value: ?float, label: string, direction: ?string},
     *   younger_levers: array<int,array{label:string,years:float}>,
     *   older_levers: array<int,array{label:string,years:float}>,
     *   components: array<int,mixed>
     * }|null  null when there isn't enough to estimate an age at all (BiologicalAge's own gate)
     */
    public static function assess(Profile $profile): ?array
    {
        $bio = BiologicalAge::assess($profile);
        if ($bio === null) {
            return null;   // not enough signal for an honest age (no anchor + <2 levers) — never fabricate one
        }

        $titanAge = (float) $bio['biological_age'];
        $chrono = (float) $bio['chronological_age'];
        $delta = (float) $bio['delta'];

        // Levers with a real year attribution, split into what's pulling YOUNGER (years < 0) vs OLDER.
        $levers = collect($bio['components'] ?? [])
            ->filter(fn ($c) => is_numeric($c['years'] ?? null) && (float) $c['years'] != 0.0);
        $younger = $levers->filter(fn ($c) => (float) $c['years'] < 0)->sortBy('years')->take(2)
            ->map(fn ($c) => ['label' => $c['label'], 'years' => round((float) $c['years'], 1)])->values()->all();
        $older = $levers->filter(fn ($c) => (float) $c['years'] > 0)->sortByDesc('years')->take(2)
            ->map(fn ($c) => ['label' => $c['label'], 'years' => round((float) $c['years'], 1)])->values()->all();

        $pace = self::pace($profile);

        return [
            'titan_age' => $titanAge,
            'chronological_age' => $chrono,
            'delta' => $delta,
            'band' => $bio['band'],
            'label' => self::headline($delta, $pace),
            'confidence' => $bio['confidence'],
            'partial' => ($bio['confidence'] ?? 'low') !== 'high' || ! empty($bio['missing_for_bloodwork']),
            'missing' => $bio['missing_for_bloodwork'] ?? [],
            'pace' => $pace,
            'younger_levers' => $younger,
            'older_levers' => $older,
            'components' => $bio['components'] ?? [],
        ];
    }

    /**
     * Pace of aging from the snapshot history: how fast biological age moved vs how much calendar time
     * actually passed. 1.0× = aging with the clock; < 1 = slower (good); > 1 = faster. Needs a real span
     * (PACE_MIN_SPAN_DAYS) or it's noise — until then, "building".
     *
     * @return array{value: ?float, label: string, direction: ?string}
     */
    private static function pace(Profile $profile): array
    {
        $snaps = LongevitySnapshot::query()
            ->where('profile_id', $profile->id)
            ->orderBy('captured_on')
            ->get(['captured_on', 'titan_age']);

        if ($snaps->count() < 2) {
            return ['value' => null, 'label' => 'building your aging trend', 'direction' => null];
        }

        $first = $snaps->first();
        $last = $snaps->last();
        $spanDays = Carbon::parse($first->captured_on)->diffInDays(Carbon::parse($last->captured_on));
        if ($spanDays < self::PACE_MIN_SPAN_DAYS) {
            return ['value' => null, 'label' => 'building your aging trend', 'direction' => null];
        }

        $calYears = $spanDays / 365.25;
        $bioYears = (float) $last->titan_age - (float) $first->titan_age;
        $value = round($bioYears / max(0.01, $calYears), 2);

        // A small band around 1.0 reads as "with the clock" — don't over-interpret jitter.
        [$direction, $label] = match (true) {
            $value <= 0.85 => ['younger', 'aging slower than the clock'],
            $value >= 1.15 => ['older', 'aging faster than the clock'],
            default => ['even', 'aging with the clock'],
        };

        return ['value' => $value, 'label' => $label, 'direction' => $direction];
    }

    private static function headline(float $delta, array $pace): string
    {
        $years = abs(round($delta));
        $core = match (true) {
            $delta <= -1 => "{$years} years younger than your age",
            $delta >= 1 => "{$years} years older than your age",
            default => 'right on your age',
        };
        if (($pace['value'] ?? null) !== null && $pace['direction'] !== 'even') {
            return ucfirst($core).' — '.$pace['label'];
        }

        return ucfirst($core);
    }

    /**
     * Persist today's reading so pace-of-aging has history (idempotent per day). Call weekly and after a
     * fresh bloodwork / fitness test. No-op when there isn't enough to estimate an age.
     */
    public static function snapshot(Profile $profile, ?Carbon $day = null): ?LongevitySnapshot
    {
        $read = self::assess($profile);
        if ($read === null) {
            return null;
        }
        $on = ($day ?? Carbon::now())->toDateString();

        return LongevitySnapshot::updateOrCreate(
            ['profile_id' => $profile->id, 'captured_on' => $on],
            [
                'titan_age' => $read['titan_age'],
                'chronological_age' => $read['chronological_age'],
                'delta' => $read['delta'],
                'confidence' => $read['confidence'],
                'metrics' => $read,
            ],
        );
    }
}
