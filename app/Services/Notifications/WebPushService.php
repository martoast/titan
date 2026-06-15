<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Thin wrapper around minishlink/web-push. Signs an encrypted Web Push with the app's
 * VAPID keypair (config('services.webpush')) and delivers a `{title, body, url}` JSON
 * payload to one profile's browser endpoints — that JSON is what public/sw.js's `push`
 * listener reads.
 *
 * Resilience contract:
 *  - configured() is false when VAPID keys are missing → send() is a no-op (local/dev).
 *  - Every send is wrapped so a transport failure NEVER bubbles to the caller (a push
 *    must never break logging a meal, sealing a night, etc.).
 *  - Endpoints the push service reports as gone (404/410) are deleted so we stop
 *    pushing to dead browsers.
 */
class WebPushService
{
    /** VAPID keys present? If not, pushing is skipped entirely (e.g. local dev). */
    public function configured(): bool
    {
        return ! empty(config('services.webpush.public_key'))
            && ! empty(config('services.webpush.private_key'));
    }

    /**
     * Send one payload to every push subscription belonging to a profile.
     *
     * @param  iterable<PushSubscription>  $subscriptions
     * @param  array{title:string,body:string,url:?string}  $payload
     */
    public function sendToSubscriptions(iterable $subscriptions, array $payload): void
    {
        if (! $this->configured()) {
            return;
        }

        $subscriptions = collect($subscriptions);
        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $webPush = $this->client();
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Map each report back to its model so we can prune dead endpoints.
            $byHash = [];

            foreach ($subscriptions as $model) {
                $byHash[$model->endpoint_hash] = $model;

                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $model->endpoint,
                        'keys' => [
                            'p256dh' => $model->p256dh,
                            'auth' => $model->auth,
                        ],
                    ]),
                    $json,
                );
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                $model = $byHash[PushSubscription::hashFor($endpoint)] ?? null;

                if ($report->isSubscriptionExpired() && $model) {
                    // 404/410 — the browser unsubscribed or the endpoint died. Prune it.
                    rescue(fn () => $model->delete(), report: false);

                    continue;
                }

                if (! $report->isSuccess()) {
                    Log::info('[push] delivery failed', [
                        'endpoint' => $endpoint,
                        'reason' => $report->getReason(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Never let a push failure surface to the caller.
            Log::warning('[push] send threw', ['error' => $e->getMessage()]);
        }
    }

    private function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject', 'mailto:hello@titan.app'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
    }
}
