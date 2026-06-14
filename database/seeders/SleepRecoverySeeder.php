<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds ~21 days of realistic, correlated sleep + recovery logs for profile 1 (Alex)
 * so the trend charts and readiness score look believable. Sleep duration/quality and
 * recovery signals move together (a bad night drags HRV/mood down the next day) with
 * a couple of intentional "rough nights" to make the charts interesting.
 *
 * Called by the orchestrator — does NOT touch DatabaseSeeder. Idempotent: clears the
 * last 21 days for the seeded profile before re-inserting.
 */
class SleepRecoverySeeder extends Seeder
{
    public function run(): void
    {
        $profile = Profile::find(1) ?? Profile::orderBy('id')->first();

        if (! $profile) {
            return; // No profiles yet — nothing to hang data off.
        }

        $start = Carbon::today()->subDays(20);

        // Wipe this window so re-running stays clean.
        $profile->sleepLogs()->whereBetween('slept_at', [$start->toDateString(), Carbon::today()->toDateString()])->delete();
        $profile->recoveryLogs()->whereBetween('logged_at', [$start->toDateString(), Carbon::today()->toDateString()])->delete();

        // Days (0-indexed from $start) that are deliberately rough nights.
        $roughNights = [4, 5, 12, 17];

        $sleepRows = [];
        $recoveryRows = [];

        $baselineHrv = 62; // Alex's rolling HRV baseline (ms).

        for ($i = 0; $i <= 20; $i++) {
            $date = (clone $start)->addDays($i);
            $rough = in_array($i, $roughNights, true);
            $weekend = $date->isWeekend();

            // --- Sleep ---
            if ($rough) {
                $duration = random_int(290, 360);          // 4h50 – 6h
                $quality = random_int(38, 55);
            } elseif ($weekend) {
                $duration = random_int(450, 525);          // 7h30 – 8h45
                $quality = random_int(72, 92);
            } else {
                $duration = random_int(405, 475);          // 6h45 – 7h55
                $quality = random_int(64, 86);
            }

            // Stage breakdown roughly proportional to total, with some noise.
            $awake = random_int(8, 28);
            $asleep = max(1, $duration - $awake);
            $deep = (int) round($asleep * (random_int(16, 22) / 100));
            $rem = (int) round($asleep * (random_int(20, 26) / 100));
            $light = max(0, $asleep - $deep - $rem);

            // Bedtime ~22:30–00:15; wake = bedtime + duration (+awake).
            $bedMinutes = random_int(22 * 60 + 15, 24 * 60 + 15);
            $bedtime = sprintf('%02d:%02d', intdiv($bedMinutes, 60) % 24, $bedMinutes % 60);
            $wakeMinutes = ($bedMinutes + $duration + $awake) % (24 * 60);
            $wakeTime = sprintf('%02d:%02d', intdiv($wakeMinutes, 60), $wakeMinutes % 60);

            $sleepRows[] = [
                'profile_id' => $profile->id,
                'slept_at' => $date->toDateString(),
                'duration_min' => $duration,
                'quality' => $quality,
                'deep_min' => $deep,
                'rem_min' => $rem,
                'light_min' => $light,
                'awake_min' => $awake,
                'bedtime' => $bedtime,
                'wake_time' => $wakeTime,
                'notes' => $rough ? 'Restless — woke up a few times.' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // --- Recovery (correlated with that night's sleep) ---
            $sleepFactor = ($duration / 480 + $quality / 80) / 2; // ~1.0 when well rested
            $hrv = (int) round($baselineHrv * (0.78 + $sleepFactor * 0.30) + random_int(-4, 4));
            $hrv = max(28, min(95, $hrv));
            $restingHr = (int) round(54 + (1 - $sleepFactor) * 14 + random_int(-2, 2));
            $restingHr = max(46, min(74, $restingHr));

            $stress = $rough ? random_int(6, 8) : random_int(2, 5);
            // Soreness driven by a faux training cycle (peaks midweek).
            $soreness = max(1, min(9, (int) round(3 + sin($i / 1.6) * 2.5 + ($rough ? 1 : 0) + random_int(-1, 1))));
            $mood = max(2, min(10, (int) round(4 + $sleepFactor * 5 - ($rough ? 2 : 0) + random_int(-1, 1))));
            $energy = max(2, min(10, (int) round(3 + $sleepFactor * 6 - ($rough ? 2 : 0) + random_int(-1, 1))));

            $recoveryRows[] = [
                'profile_id' => $profile->id,
                'logged_at' => $date->toDateString(),
                'hrv_ms' => $hrv,
                'resting_hr' => $restingHr,
                'stress' => $stress,
                'soreness' => $soreness,
                'mood' => $mood,
                'energy' => $energy,
                'notes' => $rough ? 'Low battery today.' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Drift the baseline slightly so trends aren't perfectly flat.
            $baselineHrv += random_int(-1, 1);
            $baselineHrv = max(55, min(70, $baselineHrv));
        }

        SleepLog::insert($sleepRows);
        RecoveryLog::insert($recoveryRows);
    }
}
