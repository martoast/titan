<?php

namespace App\Services\Health;

use App\Models\ActivitySession;
use App\Models\BodyMetric;
use App\Models\DailyActivity;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\WearableConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Live HealthKit sync — the native app reads the user's Apple Health data (iPhone steps/distance +
 * Apple Watch HRV/RHR/sleep/workouts/weight), aggregates it per-day, and POSTs it here. We upsert
 * idempotently into the same models the band feeds, so the WHOLE Titan engine (recovery, sleep,
 * strain, bio-age, weight trend, the correlation engine) lights up for anyone with an iPhone — no
 * band required. Mirrors AppleHealthImporter's mapping and adds steps→DailyActivity + cardio
 * workouts→ActivitySession.
 */
class HealthIngestService
{
    public const SOURCE = 'apple_health';

    /**
     * @param  array<string,mixed>  $payload  { recovery[], sleep[], activity[], body[], workouts[], vo2max }
     * @return array<string,int>  counts per section
     */
    public function ingest(Profile $profile, array $payload): array
    {
        $pid = $profile->id;
        $c = ['recovery' => 0, 'sleep' => 0, 'activity' => 0, 'body' => 0, 'workouts' => 0];

        foreach ((array) ($payload['recovery'] ?? []) as $r) {
            if (! is_array($r) || ! ($date = $this->date($r['date'] ?? null))) {
                continue;
            }
            $fields = array_filter([
                'hrv_ms' => $this->int($r['hrv_ms'] ?? null),
                'resting_hr' => $this->int($r['resting_hr'] ?? null),
                'resp_rate' => $this->float($r['resp_rate'] ?? null, 1),
            ], fn ($v) => $v !== null);
            if ($fields === []) {
                continue;
            }
            RecoveryLog::updateOrCreate(['profile_id' => $pid, 'logged_at' => $date], $fields + ['updated_via' => self::SOURCE]);
            $c['recovery']++;
        }

        foreach ((array) ($payload['sleep'] ?? []) as $s) {
            if (! is_array($s) || ! ($date = $this->date($s['date'] ?? null))) {
                continue;
            }
            $duration = $this->int($s['duration_min'] ?? null) ?? 0;
            if ($duration <= 0) {
                continue;
            }
            SleepLog::updateOrCreate(['profile_id' => $pid, 'slept_at' => $date], array_filter([
                'duration_min' => $duration,
                'deep_min' => $this->int($s['deep_min'] ?? null),
                'rem_min' => $this->int($s['rem_min'] ?? null),
                'light_min' => $this->int($s['light_min'] ?? null),
                'awake_min' => $this->int($s['awake_min'] ?? null),
                'quality' => $this->int($s['quality'] ?? null),
                'updated_via' => self::SOURCE,
            ], fn ($v) => $v !== null));
            $c['sleep']++;
        }

        foreach ((array) ($payload['activity'] ?? []) as $a) {
            if (! is_array($a) || ! ($date = $this->date($a['date'] ?? null))) {
                continue;
            }
            $fields = array_filter([
                'steps' => $this->int($a['steps'] ?? null),
                'active_kcal' => $this->int($a['active_kcal'] ?? null),
                'floors' => $this->int($a['floors'] ?? null),
                'distance_km' => $this->float($a['distance_km'] ?? null, 2),
                'mvpa_min' => $this->int($a['mvpa_min'] ?? null),
            ], fn ($v) => $v !== null);
            if ($fields === []) {
                continue;
            }
            // Apple Health is the FALLBACK source: mergeDaily only stores these on a day the band hasn't
            // claimed (band-primary — supersedes the old per-day-MAX merge). See DailyActivity::mergeDaily.
            DailyActivity::mergeDaily($pid, $date, $fields, ['source' => self::SOURCE, 'updated_via' => self::SOURCE]);
            $c['activity']++;
        }

        foreach ((array) ($payload['body'] ?? []) as $b) {
            if (! is_array($b) || ! ($date = $this->date($b['date'] ?? null))) {
                continue;
            }
            $fields = array_filter([
                'weight_kg' => $this->float($b['weight_kg'] ?? null, 2),
                'body_fat_pct' => $this->float($b['body_fat_pct'] ?? null, 1),
            ], fn ($v) => $v !== null);
            if ($fields === []) {
                continue;
            }
            BodyMetric::updateOrCreate(['profile_id' => $pid, 'taken_at' => $date], $fields);
            $c['body']++;
        }

        foreach ((array) ($payload['workouts'] ?? []) as $w) {
            if (! is_array($w)) {
                continue;
            }
            $start = $this->time($w['started_at'] ?? null);
            if (! $start) {
                continue;
            }
            $end = $this->time($w['ended_at'] ?? null);
            $duration = $end ? max(1, $start->diffInMinutes($end)) : $this->int($w['duration_min'] ?? null);
            ActivitySession::updateOrCreate(['profile_id' => $pid, 'started_at' => $start], array_filter([
                'ended_at' => $end,
                'duration_min' => $duration,
                'activity_type' => isset($w['type']) ? (string) $w['type'] : 'other',
                'distance_km' => $this->float($w['distance_km'] ?? null, 2),
                'calories_kcal' => $this->int($w['active_kcal'] ?? ($w['calories_kcal'] ?? null)),
                'avg_hr' => $this->int($w['avg_hr'] ?? null),
                'vo2max' => $this->float($w['vo2max'] ?? null, 1),
                'source' => self::SOURCE,
                'updated_via' => self::SOURCE,
            ], fn ($v) => $v !== null));
            $c['workouts']++;
        }

        if (($vo2 = $this->float($payload['vo2max'] ?? null, 1)) !== null) {
            $settings = $profile->settings ?? [];
            $settings['health'] = ($settings['health'] ?? []) + [];
            $settings['health']['vo2max'] = $vo2;
            $settings['health']['vo2max_at'] = now()->toDateString();
            $profile->update(['settings' => $settings]);
        }

        $this->touchConnection($profile);

        return $c;
    }

    public function status(Profile $profile): array
    {
        $conn = WearableConnection::where('profile_id', $profile->id)->where('source', self::SOURCE)->first();

        return [
            'connected' => (bool) $conn,
            'source' => self::SOURCE,
            'last_sync_at' => optional($conn?->last_sync_at)->toIso8601String(),
        ];
    }

    private function touchConnection(Profile $profile): void
    {
        try {
            WearableConnection::updateOrCreate(
                ['profile_id' => $profile->id, 'source' => self::SOURCE],
                [
                    'provider' => 'APPLE_HEALTH',
                    'status' => 'connected',
                    'timezone' => $profile->settings['timezone'] ?? config('app.timezone', 'UTC'),
                    'last_sync_at' => now(),
                    'last_payload_type' => 'HealthKit sync',
                    'last_webhook_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('[HealthKit] connection upsert failed', ['error' => $e->getMessage()]);
        }
    }

    private function date(mixed $v): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }

        return rescue(fn () => Carbon::parse((string) $v)->toDateString(), null, false);
    }

    private function time(mixed $v): ?Carbon
    {
        if (! is_string($v)) {
            return null;
        }

        return rescue(fn () => Carbon::parse($v), null, false);
    }

    private function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private function float(mixed $v, int $places): ?float
    {
        return is_numeric($v) ? round((float) $v, $places) : null;
    }
}
