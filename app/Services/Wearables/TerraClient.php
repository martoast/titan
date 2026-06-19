<?php

namespace App\Services\Wearables;

use App\Models\Profile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for Terra (unified wearable API). Two jobs: mint a "connect widget"
 * session so a user can authorize their wearable (Whoop, Oura, …), and verify the
 * signature on inbound webhooks. Dependency-free Http, like the rest of Titan.
 *
 * Docs: https://docs.tryterra.co
 */
class TerraClient
{
    public function configured(): bool
    {
        return (bool) (config('services.terra.dev_id') && config('services.terra.api_key'));
    }

    private function headers(): array
    {
        return [
            'dev-id' => (string) config('services.terra.dev_id'),
            'x-api-key' => (string) config('services.terra.api_key'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Create a Terra connect-widget session and return its URL. The user opens it,
     * picks their device, and authorizes; Terra then fires an `auth` webhook with
     * the new terra_user_id (we tie it back via reference_id = profile id).
     *
     * @param  array<int,string>  $providers  e.g. ['WHOOP'] or ['WHOOP','OURA','GARMIN']
     */
    public function generateWidgetSession(Profile $profile, array $providers = ['WHOOP'], ?string $successUrl = null, ?string $failureUrl = null): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(20)
                ->post(rtrim((string) config('services.terra.base_url'), '/').'/auth/generateWidgetSession', [
                    'reference_id' => (string) $profile->id,
                    'providers' => implode(',', $providers),
                    'language' => 'en',
                    'auth_success_redirect_url' => $successUrl,
                    'auth_failure_redirect_url' => $failureUrl,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[Terra] widget session failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('[Terra] widget session rejected', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        return $response->json('url');
    }

    /**
     * Verify a Terra webhook signature. Header `terra-signature: t=<ts>,v1=<hmac>`
     * where hmac = HMAC-SHA256( "<ts>.<raw body>", signing_secret ). Returns true
     * when no secret is configured only in local dev, so payload replay/testing works.
     */
    public function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('services.terra.signing_secret');
        if ($secret === '') {
            // No secret set (e.g. local dev / simulated payloads) -- don't hard-fail.
            return ! app()->environment('production');
        }
        if (! $signatureHeader) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k] = $v;
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($t === '' || $v1 === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $t.'.'.$rawBody, $secret);

        return hash_equals($expected, $v1);
    }

    /**
     * Verify a Titan device signature against an explicit per-device secret. Identical
     * HMAC scheme to verifySignature() -- header `X-Titan-Signature: t=<ts>,v1=<hmac>`
     * where hmac = HMAC-SHA256("<ts>.<raw body>", secret) -- but used for the open-source
     * band / device ingestion path where each device has its own 32-byte secret. Also
     * enforces a replay window: rejects when |now − ts| exceeds $toleranceSeconds.
     *
     * One verifier covers both Terra webhooks and device batches.
     */
    public function verifyDeviceSignature(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($secret === '' || ! $signatureHeader) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k] = $v;
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($t === '' || $v1 === '' || ! ctype_digit($t)) {
            return false;
        }

        // Replay protection: reject stale or future-dated timestamps.
        if (abs(time() - (int) $t) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $t.'.'.$rawBody, $secret);

        return hash_equals($expected, $v1);
    }
}
