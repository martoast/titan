<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use App\Models\WearableConnection;
use App\Services\Wearables\DeviceIngestionService;
use App\Services\Wearables\TerraClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Device-agnostic biosignal ingestion API. No web/session auth -- each request is
 * authenticated by a per-device HMAC signature (X-Device-Id + X-Titan-Signature),
 * the same scheme TerraClient uses for webhooks. CSRF-exempt by virtue of being an
 * API route (no web middleware group).
 *
 * Endpoints:
 *   POST   /api/devices/pair         -- issue a device_id + one-time secret (web-auth'd)
 *   POST   /api/devices/ingest       -- accept a signed batch (Shapes A/B/C) → 202
 *   DELETE /api/devices/{id}         -- revoke a device (null the token hash)
 *   GET    /api/devices/ingestions   -- store-and-forward catch-up (?since=)
 */
class DeviceIngestionController extends Controller
{
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;   // 5 MB gzip ceiling
    private const MAX_WINDOWS = 500;

    public function __construct(private readonly TerraClient $terra) {}

    /**
     * Accept a signed, optionally gzipped batch. Always 202 (processing is async).
     * Idempotent on batch_uid via a Redis lock + a UNIQUE row in device_ingestions.
     */
    public function ingest(Request $request, DeviceIngestionService $service): JsonResponse
    {
        $connection = $this->authenticate($request);
        if (! $connection) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // Rate limit per device_id (store-and-forward bursts are fine; abuse isn't).
        $rlKey = 'ingest:'.$connection->device_id;
        if (RateLimiter::tooManyAttempts($rlKey, 120)) {
            return response()->json(['error' => 'rate_limited'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($rlKey));
        }
        RateLimiter::hit($rlKey, 60);

        $payload = $this->decodeBody($request);
        if ($payload === null) {
            return response()->json(['error' => 'invalid_body'], 422);
        }

        $batchUid = (string) ($payload['batch_uid'] ?? '');
        if ($batchUid === '' || ! Str::isUlid($batchUid)) {
            return response()->json(['error' => 'batch_uid must be a ULID'], 422);
        }

        // Size / count guards.
        $windowCount = count($payload['windows'] ?? []);
        if ($windowCount > self::MAX_WINDOWS) {
            return response()->json(['error' => 'too_many_windows', 'max' => self::MAX_WINDOWS], 413);
        }

        // Idempotency: a Redis lock makes the dup-check + insert atomic across workers.
        $lock = Cache::lock("ingest:lock:{$batchUid}", 10);

        if (! $lock->get()) {
            // Another request for this batch is in flight → treat as duplicate.
            return $this->accepted($batchUid, 0, 0, true);
        }

        try {
            if (DeviceIngestion::where('batch_uid', $batchUid)->exists()
                || DeviceIngestion::where('batch_uid', 'like', $batchUid.'-%')->exists()) {
                return $this->accepted($batchUid, 0, 0, true);
            }

            $result = $service->ingest($connection, $payload);

            return $this->accepted(
                $result['batch_uid'],
                $result['windows_queued'],
                $result['summaries_written'],
                false,
                $result['windows_rejected'] ?? 0,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Device → server READ: the band polls this on connection to learn what activity (if any)
     * the user has started via the coach, so it can switch to the right sensing profile --
     * GPS + faster HR for a run, low-power for everyday. Same HMAC auth as /ingest (the GET
     * has an empty body, which the device signs). Returns {active:false} when nothing is set.
     */
    /**
     * Server → device: pending coach commands (buzz, sync…). The band polls this on each check-in;
     * commands are drained on read so each is executed once. HMAC auth, no session.
     */
    public function commands(Request $request): JsonResponse
    {
        $connection = $this->authenticate($request);
        if (! $connection) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return response()->json(['commands' => $connection->drainCommands()]);
    }

    public function activity(Request $request): JsonResponse
    {
        $connection = $this->authenticate($request);
        if (! $connection) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $active = $connection->profile?->settings['active_activity'] ?? null;
        if (! is_array($active)) {
            return response()->json(['active' => false]);
        }

        $sinceMin = null;
        if (! empty($active['started_at'])) {
            try {
                $sinceMin = (int) round(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($active['started_at']), true));
            } catch (\Throwable) {
                // leave null
            }
        }

        return response()->json([
            'active' => true,
            'type' => $active['type'] ?? 'other',
            'label' => $active['label'] ?? null,
            'started_at' => $active['started_at'] ?? null,
            'since_min' => $sinceMin,
            'sampling' => $active['sampling'] ?? \App\Support\ActivityPriming::profile((string) ($active['type'] ?? 'other')),
        ]);
    }

    /**
     * Pair a device to the authenticated user's profile. Issues a public device_id and
     * a 32-byte secret returned ONCE (only its sha256 is stored). This route runs under
     * web auth (registered in routes/api.php inside the auth middleware) -- the device
     * never self-pairs.
     */
    public function pair(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'source' => ['required', 'string', 'in:titan_band,bangle,polar,apple_health'],
            'label' => ['nullable', 'string', 'max:80'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $deviceId = $data['source'].'_'.strtolower((string) Str::ulid());
        $secret = bin2hex(random_bytes(32));

        $connection = WearableConnection::create([
            'profile_id' => $profile->id,
            'provider' => strtoupper($data['source']),
            'source' => $data['source'],
            'device_id' => $deviceId,
            'device_token_hash' => hash('sha256', $secret),
            'timezone' => $data['timezone'] ?? config('app.timezone', 'UTC'),
            'status' => 'connected',
            'last_payload_type' => $data['label'] ?? null,
        ]);

        return response()->json([
            'device_id' => $deviceId,
            'secret' => $secret, // shown ONCE -- the client must store it now
            'source' => $connection->source,
            'connection_id' => $connection->id,
        ], 201);
    }

    /** Revoke a device -- null its token hash so signatures can never verify again. */
    public function destroy(Request $request, WearableConnection $connection): JsonResponse
    {
        $profile = $request->user()->ensureProfile();
        abort_unless($connection->profile_id === $profile->id, 403);

        $connection->update([
            'status' => 'disconnected',
            'device_token_hash' => null,
        ]);

        return response()->json(['revoked' => true]);
    }

    /**
     * Store-and-forward catch-up: what has the server processed since ?since=<iso>?
     * The phone bridge uses this to know which buffered batches it can drop. Web-auth'd.
     */
    public function ingestions(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();
        $since = $request->query('since');

        $query = $profile->deviceIngestions()->orderByDesc('created_at')->limit(500);
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return response()->json([
            'ingestions' => $query->get([
                'batch_uid', 'source', 'kind', 'status', 'algo_version',
                'window_start', 'window_end', 'created_at',
            ]),
        ]);
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Resolve + verify the signing device. Looks up the connection by X-Device-Id, then
     * checks X-Titan-Signature against that device's secret (one verifier covers Terra
     * webhooks and device batches). Constant-time; enforces a 5-minute replay window.
     */
    private function authenticate(Request $request): ?WearableConnection
    {
        $deviceId = $request->header('X-Device-Id');
        $signature = $request->header('X-Titan-Signature');
        if (! $deviceId || ! $signature) {
            return null;
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return null;
        }

        $connection = WearableConnection::where('device_id', $deviceId)
            ->where('status', 'connected')
            ->whereNotNull('device_token_hash')
            ->first();
        if (! $connection) {
            return null;
        }

        // The shared HMAC key is sha256(secret) -- which is exactly what we store as
        // device_token_hash. The plaintext 32-byte secret is shown once at pairing and
        // never reaches the server; the device derives sha256(secret) itself to sign.
        // So we key the HMAC on the stored hash: the device's secret stays off-server
        // while both sides share a stable 256-bit key. Scheme is otherwise identical to
        // Terra's webhook verifier (one verifier covers both).
        $sharedKey = (string) $connection->device_token_hash;

        if (! $this->terra->verifyDeviceSignature($request->getContent(), $signature, $sharedKey)) {
            return null;
        }

        return $connection;
    }

    /**
     * Decode the request body: gzip-inflate if Content-Encoding: gzip, then JSON-decode.
     *
     * @return array<string,mixed>|null
     */
    private function decodeBody(Request $request): ?array
    {
        $body = $request->getContent();

        if (str_contains(strtolower((string) $request->header('Content-Encoding', '')), 'gzip')) {
            $inflated = @gzdecode($body);
            if ($inflated === false) {
                return null;
            }
            $body = $inflated;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function accepted(string $batchUid, int $windowsQueued, int $summariesWritten, bool $duplicate, int $windowsRejected = 0): JsonResponse
    {
        return response()->json([
            'accepted' => true,
            'batch_uid' => $batchUid,
            'windows_queued' => $windowsQueued,
            'windows_rejected' => $windowsRejected,
            'summaries_written' => $summariesWritten,
            'duplicate' => $duplicate,
        ], 202);
    }
}
