<?php

namespace App\Services\Health;

use App\Models\BodyMetric;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\WearableConnection;
use App\Models\Workout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use XMLReader;
use ZipArchive;

/**
 * Imports an Apple Health export ("Export All Health Data" → export.zip) into Titan's
 * canonical tables. This is the *practical* path for any iPhone user: Apple Health has
 * no cloud API, but the Health app exports everything as a single (often huge) XML file.
 *
 * Design constraints:
 *   - export.xml can be hundreds of MB → we NEVER load it into memory. We unzip to a
 *     temp dir and stream-parse with XMLReader, one <Record>/<Workout> node at a time.
 *   - We aggregate per local calendar day in bounded PHP arrays (one entry per day per
 *     metric), then upsert once at the end. Memory stays O(days), not O(samples).
 *   - Writes are idempotent: updateOrCreate on (profile_id + date) with
 *     updated_via='apple_health' so re-importing a fresh export corrects in place and
 *     never clobbers subjective user-owned fields (stress/mood/energy/quality notes).
 *
 * Apple HealthKit identifiers handled:
 *   HKQuantityTypeIdentifierHeartRateVariabilitySDNN  → recovery_logs.hrv_ms (daily mean)
 *   HKQuantityTypeIdentifierRestingHeartRate          → recovery_logs.resting_hr
 *   HKCategoryTypeIdentifierSleepAnalysis             → sleep_logs (per-night stages)
 *   HKQuantityTypeIdentifierBodyMass                  → body_metrics.weight_kg
 *   HKQuantityTypeIdentifierBodyFatPercentage         → body_metrics.body_fat_pct
 *   Workout                                           → workouts (name + duration)
 */
class AppleHealthImporter
{
    public const SOURCE = 'apple_health';

    public const PROVENANCE = 'apple_health';

    /** SDNN is reported in ms already; body fat as a 0..1 fraction. */
    private const HRV_TYPE = 'HKQuantityTypeIdentifierHeartRateVariabilitySDNN';

    private const RHR_TYPE = 'HKQuantityTypeIdentifierRestingHeartRate';

    private const SLEEP_TYPE = 'HKCategoryTypeIdentifierSleepAnalysis';

    private const WEIGHT_TYPE = 'HKQuantityTypeIdentifierBodyMass';

    private const BODYFAT_TYPE = 'HKQuantityTypeIdentifierBodyFatPercentage';

    /**
     * Import an uploaded export.zip for a profile.
     *
     * @param  string  $zipPath  absolute path to the uploaded zip on disk
     * @return array{
     *   recovery:int, sleep:int, body:int, workouts:int,
     *   samples:int, date_from:?string, date_to:?string, error:?string
     * }
     */
    public function importZip(Profile $profile, string $zipPath): array
    {
        $tmpDir = $this->makeTempDir();

        try {
            $xmlPath = $this->extractExportXml($zipPath, $tmpDir);
            if ($xmlPath === null) {
                return $this->summary(error: 'Could not find export.xml inside the zip. Make sure you uploaded the file from "Export All Health Data".');
            }

            return $this->importXml($profile, $xmlPath);
        } catch (\Throwable $e) {
            Log::warning('[AppleHealth] import failed', ['error' => $e->getMessage()]);

            return $this->summary(error: 'We could not read that export. The file may be corrupt or incomplete.');
        } finally {
            $this->cleanup($tmpDir);
        }
    }

    /**
     * Stream-parse an already-extracted export.xml and write aggregates. Public so the
     * fixture can be imported directly (see database/fixtures/apple_health_sample.xml).
     *
     * @return array{recovery:int,sleep:int,body:int,workouts:int,samples:int,date_from:?string,date_to:?string,error:?string}
     */
    public function importXml(Profile $profile, string $xmlPath): array
    {
        $tz = $this->profileTimezone($profile);

        // Per-day accumulators. Keyed by Y-m-d local date.
        $hrv = [];          // date => [sum, count]
        $rhr = [];          // date => [sum, count]
        $weight = [];       // date => kg (last wins)
        $bodyFat = [];      // date => pct
        $sleep = [];        // date => ['asleep'=>m,'deep'=>m,'rem'=>m,'core'=>m,'awake'=>m]
        $workouts = [];     // unique key => ['name','performed_at','duration_min']

        $samples = 0;
        $minDate = null;
        $maxDate = null;

        $reader = new XMLReader;
        // LIBXML_NOWARNING/NOERROR: tolerate the export's DTD line without choking.
        if (! @$reader->open($xmlPath, null, LIBXML_NOWARNING | LIBXML_NOERROR)) {
            return $this->summary(error: 'export.xml could not be opened.');
        }

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->name === 'Record') {
                    $type = (string) $reader->getAttribute('type');
                    if (! $this->isInterestingRecord($type)) {
                        continue;
                    }
                    $samples++;

                    $startStr = (string) $reader->getAttribute('startDate');
                    $endStr = (string) $reader->getAttribute('endDate') ?: $startStr;
                    $value = $reader->getAttribute('value');

                    $start = $this->parse($startStr);
                    if ($start === null) {
                        continue;
                    }
                    $localDate = $start->setTimezone($tz)->toDateString();
                    $minDate = $minDate === null || $localDate < $minDate ? $localDate : $minDate;
                    $maxDate = $maxDate === null || $localDate > $maxDate ? $localDate : $maxDate;

                    match ($type) {
                        self::HRV_TYPE => $this->accumulateMean($hrv, $localDate, (float) $value),
                        self::RHR_TYPE => $this->accumulateMean($rhr, $localDate, (float) $value),
                        self::WEIGHT_TYPE => $weight[$localDate] = $this->toKg((float) $value, (string) $reader->getAttribute('unit')),
                        self::BODYFAT_TYPE => $bodyFat[$localDate] = $this->toPct((float) $value),
                        self::SLEEP_TYPE => $this->accumulateSleep($sleep, $start, $this->parse($endStr) ?? $start, (string) $value, $tz),
                        default => null,
                    };
                } elseif ($reader->name === 'Workout') {
                    $samples++;
                    $startStr = (string) $reader->getAttribute('startDate');
                    $start = $this->parse($startStr);
                    if ($start === null) {
                        continue;
                    }
                    $activity = (string) $reader->getAttribute('workoutActivityType');
                    $durationRaw = $reader->getAttribute('duration');
                    $durationUnit = (string) $reader->getAttribute('durationUnit');
                    $durationMin = $this->toMinutes($durationRaw !== null ? (float) $durationRaw : null, $durationUnit);

                    $performedAt = $start->setTimezone($tz);
                    $localDate = $performedAt->toDateString();
                    $minDate = $minDate === null || $localDate < $minDate ? $localDate : $minDate;
                    $maxDate = $maxDate === null || $localDate > $maxDate ? $localDate : $maxDate;

                    // Dedupe on the exact start instant so a re-import maps to the same row.
                    $key = $performedAt->toIso8601String();
                    $workouts[$key] = [
                        'name' => $this->workoutName($activity),
                        'performed_at' => $performedAt,
                        'duration_min' => $durationMin,
                    ];
                }
            }
        } finally {
            $reader->close();
        }

        // --- Persist (idempotent upserts) ---
        $counts = $this->persist($profile, $tz, $hrv, $rhr, $weight, $bodyFat, $sleep, $workouts);

        $this->touchConnection($profile);

        return [
            ...$counts,
            'samples' => $samples,
            'date_from' => $minDate,
            'date_to' => $maxDate,
            'error' => null,
        ];
    }

    /**
     * @param  array<string,array{0:float,1:int}>  $hrv
     * @param  array<string,array{0:float,1:int}>  $rhr
     * @param  array<string,float>  $weight
     * @param  array<string,float>  $bodyFat
     * @param  array<string,array<string,float>>  $sleep
     * @param  array<string,array{name:string,performed_at:CarbonImmutable,duration_min:?int}>  $workouts
     * @return array{recovery:int,sleep:int,body:int,workouts:int}
     */
    private function persist(Profile $profile, string $tz, array $hrv, array $rhr, array $weight, array $bodyFat, array $sleep, array $workouts): array
    {
        $pid = $profile->id;
        $recovery = $body = $sleepRows = $workoutRows = 0;

        // Recovery: merge HRV + RHR by date.
        $recoveryDates = array_unique([...array_keys($hrv), ...array_keys($rhr)]);
        foreach ($recoveryDates as $date) {
            $fields = array_filter([
                'hrv_ms' => isset($hrv[$date]) && $hrv[$date][1] > 0 ? (int) round($hrv[$date][0] / $hrv[$date][1]) : null,
                'resting_hr' => isset($rhr[$date]) && $rhr[$date][1] > 0 ? (int) round($rhr[$date][0] / $rhr[$date][1]) : null,
                'updated_via' => self::PROVENANCE,
            ], fn ($v) => $v !== null);

            if (count($fields) <= 1) {
                continue; // only provenance, nothing real
            }
            RecoveryLog::updateOrCreate(['profile_id' => $pid, 'logged_at' => $date], $fields);
            $recovery++;
        }

        // Body: weight + body-fat by date.
        $bodyDates = array_unique([...array_keys($weight), ...array_keys($bodyFat)]);
        foreach ($bodyDates as $date) {
            $fields = array_filter([
                'weight_kg' => $weight[$date] ?? null,
                'body_fat_pct' => $bodyFat[$date] ?? null,
            ], fn ($v) => $v !== null);

            if ($fields === []) {
                continue;
            }
            // body_metrics has no updated_via column; key on (profile + taken_at) for idempotency.
            BodyMetric::updateOrCreate(['profile_id' => $pid, 'taken_at' => $date], $fields);
            $body++;
        }

        // Sleep: one row per night.
        foreach ($sleep as $date => $stages) {
            $deep = (int) round($stages['deep'] ?? 0);
            $rem = (int) round($stages['rem'] ?? 0);
            $light = (int) round($stages['core'] ?? 0); // "core" maps to Titan's light_min
            $awake = (int) round($stages['awake'] ?? 0);
            // Total asleep: prefer explicit stage sum, fall back to generic "asleep".
            $staged = $deep + $rem + $light;
            $duration = $staged > 0 ? $staged : (int) round($stages['asleep'] ?? 0);

            if ($duration <= 0) {
                continue;
            }
            SleepLog::updateOrCreate(
                ['profile_id' => $pid, 'slept_at' => $date],
                array_filter([
                    'duration_min' => $duration,
                    'deep_min' => $deep ?: null,
                    'rem_min' => $rem ?: null,
                    'light_min' => $light ?: null,
                    'awake_min' => $awake ?: null,
                    'updated_via' => self::PROVENANCE,
                ], fn ($v) => $v !== null),
            );
            $sleepRows++;
        }

        // Workouts: dedupe on the performed_at instant.
        foreach ($workouts as $w) {
            Workout::updateOrCreate(
                ['profile_id' => $pid, 'performed_at' => $w['performed_at']],
                array_filter([
                    'name' => $w['name'],
                    'duration_min' => $w['duration_min'],
                    'updated_via' => self::PROVENANCE,
                ], fn ($v) => $v !== null),
            );
            $workoutRows++;
        }

        return [
            'recovery' => $recovery,
            'sleep' => $sleepRows,
            'body' => $body,
            'workouts' => $workoutRows,
        ];
    }

    /** Upsert the apple_health WearableConnection and refresh last_sync_at. */
    private function touchConnection(Profile $profile): void
    {
        try {
            WearableConnection::updateOrCreate(
                ['profile_id' => $profile->id, 'source' => self::SOURCE],
                [
                    'provider' => 'APPLE_HEALTH',
                    'status' => 'connected',
                    'timezone' => $this->profileTimezone($profile),
                    'last_sync_at' => now(),
                    'last_payload_type' => 'Apple Health export',
                    'last_webhook_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('[AppleHealth] connection upsert failed', ['error' => $e->getMessage()]);
        }
    }

    // --- Aggregation helpers ---

    /** @param  array<string,array{0:float,1:int}>  $bag */
    private function accumulateMean(array &$bag, string $date, float $value): void
    {
        if (! is_finite($value)) {
            return;
        }
        $bag[$date] ??= [0.0, 0];
        $bag[$date][0] += $value;
        $bag[$date][1]++;
    }

    /**
     * Sleep "Analysis" records come as overlapping intervals tagged with a stage value.
     * We sum the duration of each interval into its stage bucket for the *wake* night --
     * a session that crosses midnight is attributed to the calendar day it ended on
     * (local), which is how a "night of the 14th" is conventionally labelled.
     *
     * @param  array<string,array<string,float>>  $bag
     */
    private function accumulateSleep(array &$bag, CarbonImmutable $start, CarbonImmutable $end, string $value, string $tz): void
    {
        $minutes = max(0.0, $start->diffInSeconds($end) / 60);
        if ($minutes <= 0) {
            return;
        }
        $night = $end->setTimezone($tz)->toDateString();
        $bag[$night] ??= ['asleep' => 0.0, 'deep' => 0.0, 'rem' => 0.0, 'core' => 0.0, 'awake' => 0.0];

        // Normalize across HealthKit's historical sleep value spellings.
        $bucket = match (true) {
            str_contains($value, 'AsleepDeep') => 'deep',
            str_contains($value, 'AsleepREM') => 'rem',
            str_contains($value, 'AsleepCore') => 'core',
            str_contains($value, 'Awake') => 'awake',
            // Generic "Asleep" / "AsleepUnspecified" / "InBed" with no stage detail.
            str_contains($value, 'Asleep') => 'asleep',
            default => 'asleep',
        };
        $bag[$night][$bucket] += $minutes;
    }

    private function isInterestingRecord(string $type): bool
    {
        return match ($type) {
            self::HRV_TYPE, self::RHR_TYPE, self::WEIGHT_TYPE, self::BODYFAT_TYPE, self::SLEEP_TYPE => true,
            default => false,
        };
    }

    // --- Unit / value helpers ---

    private function toKg(float $value, string $unit): float
    {
        $unit = strtolower(trim($unit));

        return match ($unit) {
            'lb', 'lbs' => round($value * 0.45359237, 2),
            'g' => round($value / 1000, 2),
            'st' => round($value * 6.35029318, 2),
            default => round($value, 2), // kg
        };
    }

    /** HealthKit body fat is a fraction (0.142). Accept already-percent values too. */
    private function toPct(float $value): float
    {
        return round($value <= 1 ? $value * 100 : $value, 2);
    }

    private function toMinutes(?float $value, string $unit): ?int
    {
        if ($value === null) {
            return null;
        }
        $unit = strtolower(trim($unit));
        $min = match ($unit) {
            'sec', 's' => $value / 60,
            'hr', 'h' => $value * 60,
            default => $value, // min
        };

        return (int) round($min);
    }

    private function parse(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** "HKWorkoutActivityTypeFunctionalStrengthTraining" → "Functional Strength Training". */
    private function workoutName(string $activityType): string
    {
        $name = preg_replace('/^HKWorkoutActivityType/', '', $activityType) ?? $activityType;
        $name = preg_replace('/(?<!^)([A-Z])/', ' $1', $name) ?? $name;
        $name = trim($name);

        return $name !== '' ? $name : 'Workout';
    }

    private function profileTimezone(Profile $profile): string
    {
        return $profile->settings['timezone'] ?? (string) config('app.timezone', 'UTC');
    }

    // --- Zip / temp-dir plumbing ---

    /** Find and extract export.xml from the zip into $tmpDir. Returns its path or null. */
    private function extractExportXml(string $zipPath, string $tmpDir): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            // Apple nests it under "apple_health_export/export.xml"; tolerate either.
            $entry = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, 'export.xml') && ! str_contains($name, '/cda/')) {
                    $entry = $name;
                    break;
                }
            }
            if ($entry === null) {
                return null;
            }

            $stream = $zip->getStream($entry);
            if ($stream === false) {
                return null;
            }
            $dest = $tmpDir.DIRECTORY_SEPARATOR.'export.xml';
            $out = fopen($dest, 'wb');
            if ($out === false) {
                fclose($stream);

                return null;
            }
            // Stream-copy so we never hold the (possibly huge) XML in memory.
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);

            return $dest;
        } finally {
            $zip->close();
        }
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'titan_apple_health_'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);

        return $dir;
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** @return array{recovery:int,sleep:int,body:int,workouts:int,samples:int,date_from:?string,date_to:?string,error:?string} */
    private function summary(?string $error = null): array
    {
        return [
            'recovery' => 0,
            'sleep' => 0,
            'body' => 0,
            'workouts' => 0,
            'samples' => 0,
            'date_from' => null,
            'date_to' => null,
            'error' => $error,
        ];
    }
}
