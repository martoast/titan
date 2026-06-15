<?php

/*
 * Titan · simulator routes — the virtual band / digital twin.
 * Runs inside the auth group (see routes/web.php). The "simulator" build owns this
 * file exclusively.
 *
 * The simulator pages a 3D model of the open-source recovery band, generates realistic
 * synthetic physiology in the browser, and streams it into the REAL ingestion pipeline
 * (POST /api/devices/ingest, HMAC-signed) so recovery_logs / sleep_logs get populated
 * just like the hardware would.
 */

use App\Models\WearableConnection;
use App\Services\Simulator\BiosignalSimulator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** The simulator page. */
Route::get('/simulator', function (Request $request) {
    $profile = $request->user()->ensureProfile();
    [$device, $secret] = simulatorDevice($profile);

    return view('simulator.index', [
        'profile' => $profile,
        'device' => $device,
        // The per-device HMAC signing key for THIS session. The server verifies with
        // device_token_hash = sha256(secret), so the page must sign with that same value
        // — we hand the page sha256(secret) (NOT the plaintext) over the already-authed
        // channel. Byte-identical scheme to the firmware bridge after the same fix.
        'deviceSecret' => hash('sha256', $secret),
        'ingestUrl' => rtrim(config('app.url', url('/')), '/').'/api/devices/ingest',
        'states' => BiosignalSimulator::STATES,
    ]);
})->name('simulator.index');

/**
 * Headless trigger: generate + (attempt to) ingest a full night server-side. Lets the
 * page (or a curl) kick a night without running the IBI math in JS. Returns the summary.
 */
Route::post('/simulator/night', function (Request $request) {
    $profile = $request->user()->ensureProfile();
    [$device, $secret] = simulatorDevice($profile);

    $minutes = (int) $request->integer('minutes', 480);
    $minutes = max(120, min(720, $minutes));
    $sim = new BiosignalSimulator($request->filled('seed') ? (int) $request->input('seed') : null);

    $tz = $device->effectiveTimezone();
    $wake = Carbon::now($tz)->startOfDay()->addHours(7);
    $bedtime = $wake->copy()->subMinutes($minutes);

    $segments = $sim->hypnogram($minutes);
    $summary = BiosignalSimulator::summariseSleep($segments);

    $cursor = $bedtime->copy();
    $windows = [];
    $allIbi = [];
    $rhrMedians = [];
    foreach ($segments as $seg) {
        $w = $sim->generateWindow($seg['state'], $seg['minutes'] * 60);
        $start = $cursor->copy();
        $end = $cursor->copy()->addMinutes($seg['minutes']);
        $windows[] = [
            'kind' => 'ibi',
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
            'ibi_ms' => $w['ibi_ms'],
            'accel_counts' => $w['accel_counts'],
            'confidence' => $seg['state'] === 'rest' ? 0.7 : 0.95,
        ];
        $allIbi = array_merge($allIbi, $w['ibi_ms']);
        if ($seg['state'] !== 'rest' && count($w['ibi_ms']) > 5) {
            $rhrMedians[] = BiosignalSimulator::meanHr($w['ibi_ms']);
        }
        $cursor = $end;
    }

    $rmssd = BiosignalSimulator::rmssd($allIbi);
    $rhr = $rhrMedians ? min($rhrMedians) : BiosignalSimulator::meanHr($allIbi);
    $date = $wake->toDateString();

    $payloadA = [
        'batch_uid' => (string) Str::ulid(),
        'device_id' => $device->device_id,
        'timezone' => $tz,
        'windows' => $windows,
    ];
    $payloadC = [
        'batch_uid' => (string) Str::ulid(),
        'device_id' => $device->device_id,
        'timezone' => $tz,
        'summaries' => [
            [
                'kind' => 'sleep', 'date' => $date,
                'duration_min' => $summary['duration_min'], 'deep_min' => $summary['deep_min'],
                'rem_min' => $summary['rem_min'], 'light_min' => $summary['light_min'],
                'awake_min' => $summary['awake_min'], 'bedtime' => $bedtime->format('H:i'),
                'wake_time' => $wake->format('H:i'), 'quality' => $summary['quality'],
            ],
            ['kind' => 'recovery', 'date' => $date, 'hrv_ms' => (int) round($rmssd), 'resting_hr' => $rhr],
        ],
    ];

    $delivery = [
        simulatorPost($device->device_id, $secret, $payloadA),
        simulatorPost($device->device_id, $secret, $payloadC),
    ];

    return response()->json([
        'ok' => true,
        'delivered' => collect($delivery)->contains('ok', true),
        'summary' => [
            'bedtime' => $bedtime->format('H:i'),
            'wake_time' => $wake->format('H:i'),
            'duration_min' => $summary['duration_min'],
            'deep_min' => $summary['deep_min'],
            'rem_min' => $summary['rem_min'],
            'light_min' => $summary['light_min'],
            'awake_min' => $summary['awake_min'],
            'quality' => $summary['quality'],
            'rmssd_ms' => $rmssd,
            'resting_hr' => $rhr,
            'beats' => count($allIbi),
            'windows' => count($windows),
        ],
        'hypnogram' => $segments,
        'delivery' => $delivery,
    ]);
})->name('simulator.night');

/* ---- helpers (file-local; closures keep them out of the global namespace via fn names) ---- */

if (! function_exists('simulatorDevice')) {
    /**
     * Find-or-create this profile's virtual band and (re)mint a signing secret for the
     * current session. Returns [WearableConnection, plaintextSecret].
     *
     * @return array{0: \App\Models\WearableConnection, 1: string}
     */
    function simulatorDevice($profile): array
    {
        $device = WearableConnection::where('profile_id', $profile->id)
            ->where('source', 'titan_band')
            ->where('device_id', 'like', 'tb_sim_%')
            ->first();

        $secret = bin2hex(random_bytes(16));

        if (! $device) {
            $device = WearableConnection::create([
                'profile_id' => $profile->id,
                'provider' => 'titan_band',
                'source' => 'titan_band',
                'device_id' => 'tb_sim_'.Str::lower(Str::random(10)),
                'device_token_hash' => hash('sha256', $secret),
                'timezone' => config('app.timezone', 'UTC'),
                'status' => 'connected',
                'scopes' => ['ibi', 'accel', 'sleep', 'recovery'],
            ]);
        } else {
            $device->update(['device_token_hash' => hash('sha256', $secret), 'status' => 'connected']);
        }

        return [$device, $secret];
    }
}

if (! function_exists('simulatorPost')) {
    /** Signed POST to the ingestion API; never throws (degrades gracefully). */
    function simulatorPost(string $deviceId, string $secret, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, $secret);
        $url = rtrim(config('app.url', url('/')), '/').'/api/devices/ingest';

        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders([
                'X-Device-Id' => $deviceId,
                'X-Titan-Signature' => $sig,
                'Content-Type' => 'application/json',
            ])->timeout(20)->withBody($body, 'application/json')->post($url);

            return ['ok' => $res->successful(), 'status' => $res->status(), 'body' => Str::limit($res->body(), 200)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => $e->getMessage()];
        }
    }
}
