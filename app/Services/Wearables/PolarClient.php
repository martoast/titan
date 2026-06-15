<?php

namespace App\Services\Wearables;

use App\Models\BodyMetric;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\Workout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for Polar AccessLink — the free, official Polar wearable API
 * (OAuth2 Authorization Code + webhooks). A real second data source: a user with any
 * recent Polar watch (Vantage, Grit X, Ignite, …) authorizes Titan and we pull their
 * exercises, sleep, and Nightly Recharge straight into the same canonical tables.
 *
 * This is a scaffold: the OAuth handshake + user registration are wired; the data-pull
 * methods map Polar's response shapes to Titan rows but are stubbed where they need a
 * persisted access token (we don't yet have a column for it — see registerUser()).
 * Everything degrades gracefully when credentials are absent (configured() === false),
 * so the app runs fine without a Polar dev account.
 *
 * Free dev credentials: https://admin.polaraccesslink.com
 * Docs: https://www.polar.com/accesslink-api/
 */
class PolarClient
{
    public const SOURCE = 'polar';

    public const PROVENANCE = 'polar';

    /** OAuth2 endpoints live on flow.polar.com; the data API on the base_url host. */
    private const AUTH_URL = 'https://flow.polar.com/oauth2/authorization';

    private const TOKEN_URL = 'https://polarremote.com/v2/oauth2/token';

    public function configured(): bool
    {
        return (bool) (config('services.polar.client_id') && config('services.polar.client_secret'));
    }

    private function clientId(): string
    {
        return (string) config('services.polar.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.polar.client_secret');
    }

    private function redirect(): string
    {
        return (string) config('services.polar.redirect');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.polar.base_url', 'https://www.polaraccesslink.com'), '/');
    }

    /**
     * Build the Polar OAuth2 authorize URL. We pass the profile id as `state` so the
     * callback can tie the returned code back to the right Titan profile (CSRF + routing
     * in one). Returns null if Polar isn't configured.
     */
    public function authorizeUrl(Profile $profile): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirect(),
            'scope' => 'accesslink.read_all',
            'state' => (string) $profile->id,
        ]);

        return self::AUTH_URL.'?'.$query;
    }

    /**
     * Exchange an authorization code for an access token. Polar uses HTTP Basic auth
     * (client_id:client_secret) on the token endpoint.
     *
     * @return array{access_token:string,token_type:string,x_user_id:int|string,expires_in?:int}|null
     */
    public function exchangeToken(string $code): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(20)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirect(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('[Polar] token exchange failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('[Polar] token exchange rejected', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        return $response->json();
    }

    /**
     * Register the authorized Polar user with our AccessLink client (one-time, required
     * before any data can be pulled). Idempotent on Polar's side: a 409 means "already
     * registered", which we treat as success.
     */
    public function registerUser(string $accessToken, string $memberId): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
                ->timeout(20)
                ->post($this->baseUrl().'/v3/users', [
                    'member-id' => $memberId,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[Polar] user registration failed', ['error' => $e->getMessage()]);

            return false;
        }

        // 200/201 = registered, 409 = already registered. Both are fine.
        return $response->successful() || $response->status() === 409;
    }

    // ---------------------------------------------------------------------------
    // Data pull stubs. Polar AccessLink is "transactional": you create a
    // pull-notification "transaction", list its resource URLs, GET each, then commit
    // (DELETE) the transaction. These map each resource to a canonical Titan row, then
    // would be driven by a queued job once we persist the per-user access token.
    //
    // They accept an explicit ($accessToken, $polarUserId) so the OAuth callback / a
    // future PolarSyncJob can call them directly without a token column existing yet.
    // ---------------------------------------------------------------------------

    /**
     * Pull recorded exercises → workouts. Polar:
     *   POST /v3/users/{userId}/exercise-transactions  → {transaction-id, resource-uri[]}
     *   GET each resource → exercise summary (sport, duration ISO-8601, start-time).
     *
     * @return int rows written
     */
    public function pullExercises(Profile $profile, string $accessToken, string $polarUserId): int
    {
        return $this->withTransaction(
            $profile,
            $accessToken,
            "/v3/users/{$polarUserId}/exercise-transactions",
            'exercises',
            function (array $exercise) use ($profile): bool {
                $start = $this->parse($exercise['start-time'] ?? null);
                if ($start === null) {
                    return false;
                }

                Workout::updateOrCreate(
                    ['profile_id' => $profile->id, 'performed_at' => $start],
                    array_filter([
                        'name' => isset($exercise['sport']) ? ucwords(strtolower(str_replace('_', ' ', (string) $exercise['sport']))) : 'Polar Session',
                        'duration_min' => $this->isoDurationToMinutes($exercise['duration'] ?? null),
                        'updated_via' => self::PROVENANCE,
                    ], fn ($v) => $v !== null),
                );

                return true;
            },
        );
    }

    /**
     * Pull sleep → sleep_logs. Polar:
     *   GET /v3/users/sleep  → nights with sleep stages (light/deep/REM) in seconds and
     *   a 1..5 sleep score we scale to 0..100 for Titan's `quality`.
     *
     * @return int rows written
     */
    public function pullSleep(Profile $profile, string $accessToken, string $polarUserId): int
    {
        $nights = $this->getJson($accessToken, '/v3/users/sleep')['nights'] ?? [];
        $written = 0;

        foreach ($nights as $night) {
            if (! is_array($night) || empty($night['date'])) {
                continue;
            }
            $deep = $this->secToMin($night['deep_sleep'] ?? null);
            $rem = $this->secToMin($night['rem_sleep'] ?? null);
            $light = $this->secToMin($night['light_sleep'] ?? null);
            $awake = $this->secToMin($night['total_interruption_duration'] ?? null);
            $duration = ($deep ?? 0) + ($rem ?? 0) + ($light ?? 0);

            if ($duration <= 0) {
                continue;
            }
            SleepLog::updateOrCreate(
                ['profile_id' => $profile->id, 'slept_at' => (string) $night['date']],
                array_filter([
                    'duration_min' => $duration,
                    'deep_min' => $deep,
                    'rem_min' => $rem,
                    'light_min' => $light,
                    'awake_min' => $awake,
                    'quality' => isset($night['sleep_score']) ? (int) round(((float) $night['sleep_score']) / 5 * 100) : null,
                    'updated_via' => self::PROVENANCE,
                ], fn ($v) => $v !== null),
            );
            $written++;
        }

        return $written;
    }

    /**
     * Pull Nightly Recharge → recovery_logs. Polar:
     *   GET /v3/users/nightly-recharge  → per-date HRV (ms) + beat-to-beat average,
     *   from which we take nightly HRV and resting HR.
     *
     * @return int rows written
     */
    public function pullNightlyRecharge(Profile $profile, string $accessToken, string $polarUserId): int
    {
        $records = $this->getJson($accessToken, '/v3/users/nightly-recharge')['recharges'] ?? [];
        $written = 0;

        foreach ($records as $r) {
            if (! is_array($r) || empty($r['date'])) {
                continue;
            }
            $fields = array_filter([
                'hrv_ms' => isset($r['heart_rate_variability_avg']) ? (int) round((float) $r['heart_rate_variability_avg']) : null,
                'resting_hr' => isset($r['beat_to_beat_avg']) ? (int) round((float) $r['beat_to_beat_avg']) : null,
                'updated_via' => self::PROVENANCE,
            ], fn ($v) => $v !== null);

            if (count($fields) <= 1) {
                continue;
            }
            RecoveryLog::updateOrCreate(['profile_id' => $profile->id, 'logged_at' => (string) $r['date']], $fields);
            $written++;
        }

        return $written;
    }

    // --- internal HTTP helpers (all degrade to no-ops on failure) ---

    /**
     * Run a Polar pull-transaction: open it, fetch each listed resource, map it, then
     * commit (DELETE) so Polar marks the data delivered.
     *
     * @param  callable(array<string,mixed>):bool  $map
     */
    private function withTransaction(Profile $profile, string $accessToken, string $openPath, string $resourceKey, callable $map): int
    {
        try {
            $open = Http::withToken($accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(20)
                ->post($this->baseUrl().$openPath);

            // 204 = no new data.
            if ($open->status() === 204 || $open->failed()) {
                return 0;
            }

            $transactionId = $open->json('transaction-id');
            $resourceUris = $open->json('resource-uri') ?? [];
            $written = 0;

            foreach ($resourceUris as $uri) {
                $res = Http::withToken($accessToken)->withHeaders(['Accept' => 'application/json'])->timeout(20)->get((string) $uri);
                if ($res->failed()) {
                    continue;
                }
                if ($map($res->json())) {
                    $written++;
                }
            }

            // Commit the transaction so Polar won't redeliver.
            if ($transactionId) {
                Http::withToken($accessToken)->timeout(20)->put($this->baseUrl().$openPath.'/'.$transactionId);
            }

            return $written;
        } catch (\Throwable $e) {
            Log::warning('[Polar] pull failed', ['resource' => $resourceKey, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /** @return array<string,mixed> */
    private function getJson(string $accessToken, string $path): array
    {
        try {
            $res = Http::withToken($accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(20)
                ->get($this->baseUrl().$path);

            return $res->successful() ? (array) $res->json() : [];
        } catch (\Throwable $e) {
            Log::warning('[Polar] get failed', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function isoDurationToMinutes(?string $iso): ?int
    {
        if (! $iso) {
            return null;
        }
        try {
            $interval = new \DateInterval($iso);
            $minutes = ($interval->h * 60) + $interval->i + (int) round($interval->s / 60);

            return $minutes > 0 ? $minutes : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function secToMin(int|float|null $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }

        return (int) round(((float) $seconds) / 60);
    }

    private function parse(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
