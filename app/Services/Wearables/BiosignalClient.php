<?php

namespace App\Services\Wearables;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the self-hosted Python biosignal service (FastAPI). Mirrors the
 * dependency-free Http style of TerraClient. The boundary is a pure HTTP contract:
 * we hand it a window of raw signal (the §5 window JSON) and it returns metrics --
 * it holds no state and never touches our DB.
 *
 *   POST /process/hrv      → {algo_version, metrics:{hrv_ms,resting_hr,rmssd,sdnn,pnn50,lf_hf,resp_rate,artifact_pct,valid}}
 *   POST /process/sleep    → {algo_version, metrics:{duration_min,deep_min,rem_min,light_min,awake_min,bedtime,wake_time,quality,hypnogram_30s}}
 *   POST /process/activity → {algo_version, metrics:{duration_min,trimp,avg_hr,strain}}
 */
class BiosignalClient
{
    public function configured(): bool
    {
        return (bool) config('services.biosignal.url');
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.biosignal.url'), '/'))
            ->withToken((string) config('services.biosignal.token'))
            ->timeout((int) config('services.biosignal.timeout', 60))
            ->retry(2, 500)
            ->acceptJson();
    }

    /**
     * @param  array<string,mixed>  $window  The §5 window JSON (kind=ibi or ppg_raw).
     * @return array<string,mixed>
     */
    public function processHrv(array $window): array
    {
        return $this->client()->post('/process/hrv', $window)->throw()->json();
    }

    /**
     * @param  array<string,mixed>  $window
     * @return array<string,mixed>
     */
    public function processSleep(array $window): array
    {
        return $this->client()->post('/process/sleep', $window)->throw()->json();
    }

    /**
     * @param  array<string,mixed>  $window
     * @return array<string,mixed>
     */
    public function processActivity(array $window): array
    {
        return $this->client()->post('/process/activity', $window)->throw()->json();
    }

    /**
     * Floors climbed + ascent/descent from a barometric altitude series.
     *
     * @param  array<string,mixed>  $window  {altitude_m: float[], sample_rate_hz: float}
     * @return array<string,mixed>
     */
    public function processElevation(array $window): array
    {
        return $this->client()->post('/process/elevation', $window)->throw()->json();
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function processFitness(array $request): array
    {
        return $this->client()->post('/process/fitness', $request)->throw()->json();
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function processGym(array $request): array
    {
        return $this->client()->post('/process/gym', $request)->throw()->json();
    }

    /**
     * Workout HR recomputed from raw PPG + accel with motion-artifact suppression — beats the
     * on-chip bpm, which cadence-locks under load. Returns per-window {bpm, confidence, reliable}
     * + a summary {hr_mean, hr_max, coverage}. See biosignal app/core/inmotion_hr.py.
     *
     * @param  array<string,mixed>  $request  {ppg, fs_ppg, accel_x, accel_y, accel_z, fs_acc, seed_bpm?}
     * @return array<string,mixed>
     */
    public function processInMotionHr(array $request): array
    {
        return $this->client()->post('/process/inmotion-hr', $request)->throw()->json();
    }

    /**
     * Run route → the Strava-style summary: distance, moving/elapsed time, avg + grade-adjusted
     * pace, per-km/mile splits, elevation gain + profile, best efforts, Relative Effort, and an
     * encoded+simplified polyline (+ bounds) for the map. See biosignal app/core/route.py.
     *
     * @param  array<string,mixed>  $request  {track:[{t,lat,lon,alt?}], hr_bpm?, hr_max?, units?}
     * @return array<string,mixed>
     */
    public function processRoute(array $request): array
    {
        return $this->client()->post('/process/route', $request)->throw()->json();
    }
}
