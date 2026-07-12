<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\SleepLog;
use Illuminate\Support\Carbon;

/**
 * Sleep debt as a LEDGER you can watch rise and fall — not a buried deficit-only scalar. Walks the last
 * ~2 weeks of confident nights oldest→newest as a running balance: a short night ACCRUES, a big night
 * PAYS SOME back (bounded — you can recover recent debt, you can't bank ahead or clear a week in one
 * night), old debt decays off the recoverable horizon, and the balance never goes negative. That's the
 * honest, motivating physiology. {@see SleepCoach} delegates `debt_h` here so one number lives everywhere.
 *
 * Honesty (same trust rule as the seal): a low_confidence / missing night does NOT move the ledger — a
 * jittery 4h estimate must never invent 3h of debt. It's marked unmeasured in `history`, not scored 0.
 */
class SleepDebt
{
    private const WINDOW_NIGHTS = 14;               // the recoverable horizon (~2 weeks)
    private const MAX_PAYBACK_PER_NIGHT = 1.5;       // a great night pays back at most this much
    private const DEBT_CAP_H = 10.0;                 // never runaway
    private const NIGHTLY_DECAY = 0.95;              // old debt is biologically written off
    private const PAYBACK_RATE_H = 0.75;             // humane catch-up pace (~45 min/night) for the plan

    /** @return array<string,mixed> the ledger — see SLEEP_DEBT.md for the shape */
    public static function forProfile(Profile $profile, ?Carbon $day = null): array
    {
        $baseline = SleepCoach::baselineFor($profile);
        $today = ($day ? $day->copy() : Carbon::now())->startOfDay();

        $nights = $profile->sleepLogs()->nights()
            ->whereNotNull('duration_min')
            ->where('slept_at', '>=', $today->copy()->subDays(self::WINDOW_NIGHTS)->toDateString())
            ->where('slept_at', '<=', $today->toDateString())
            ->orderBy('slept_at')->orderBy('id')
            ->get(['slept_at', 'duration_min', 'low_confidence']);

        $balance = 0.0;
        $history = [];
        $lastEffect = 0.0;          // + = added debt, − = paid down, for the most recent MEASURED night
        $balances = [];             // measured-night balances, for the trend

        foreach ($nights as $n) {
            $date = $n->slept_at?->toDateString();
            // Honesty: an unmeasured / low-signal night doesn't move the ledger.
            if ($n->low_confidence || ! $n->duration_min) {
                $history[] = ['date' => $date, 'unmeasured' => true, 'balance_h' => round($balance, 1), 'delta_h' => 0.0];

                continue;
            }

            $asleep = $n->duration_min / 60.0;
            $delta = $baseline - $asleep;                                  // + = short (owe), − = long (bank)
            $effect = $delta > 0 ? $delta : max($delta, -self::MAX_PAYBACK_PER_NIGHT);
            $balance = max(0.0, min(self::DEBT_CAP_H, $balance + $effect));
            $balance *= self::NIGHTLY_DECAY;

            $lastEffect = $effect;
            $balances[] = $balance;
            $history[] = ['date' => $date, 'unmeasured' => false, 'balance_h' => round($balance, 1), 'delta_h' => round($effect, 1)];
        }

        $balance = round($balance, 1);
        $band = match (true) {
            $balance <= 0.0 => 'none',
            $balance < 2.0 => 'light',
            $balance <= 4.0 => 'moderate',
            default => 'heavy',
        };

        // Trend vs ~3 measured nights ago.
        $trend = 'steady';
        if (count($balances) >= 4) {
            $prior = $balances[count($balances) - 4];
            $trend = $balance > $prior + 0.3 ? 'rising' : ($balance < $prior - 0.3 ? 'easing' : 'steady');
        }

        // Pure need helper (NOT assess — assess delegates debt back here; that would recurse).
        $need = SleepCoach::needFor($profile, $baseline, $balance, $day);

        return [
            'balance_h' => $balance,
            'band' => $band,
            'trend' => $trend,
            'paid_back_last_night_h' => $lastEffect < 0 ? round(-$lastEffect, 1) : 0.0,
            'added_last_night_h' => $lastEffect > 0 ? round($lastEffect, 1) : 0.0,
            'history' => $history,
            'payback' => self::payback($balance, (float) $need),
            'explainer' => 'Debt is the sleep you owe from recent short nights. You can pay back the last '
                .'~2 weeks — about an hour a night — but you can\'t bank ahead or clear it all at once.',
        ];
    }

    /** A REALISTIC payback plan: spread the balance over N nights at a humane rate, never a crash. */
    private static function payback(float $balance, float $tonightNeed): array
    {
        if ($balance <= 0.0) {
            return [
                'clearable' => true,
                'plan' => 'You\'re rested — no debt to pay.',
                'extra_min_per_night' => 0,
                'nights' => 0,
                'tonight_target_h' => round($tonightNeed, 1),
            ];
        }

        $nights = max(1, (int) ceil($balance / self::PAYBACK_RATE_H));
        $extraMin = (int) round(($balance * 60) / $nights);
        $nightWord = $nights === 1 ? 'night' : 'nights';

        return [
            'clearable' => true,
            'plan' => "+{$extraMin} min a night for {$nights} {$nightWord} clears it",
            'extra_min_per_night' => $extraMin,
            'nights' => $nights,
            'tonight_target_h' => round($tonightNeed, 1),
        ];
    }
}
