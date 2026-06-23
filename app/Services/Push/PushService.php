<?php

namespace App\Services\Push;

use App\Models\User;

/**
 * Sends a push to all of a user's registered devices. Resolves the user's push tokens and fans
 * the message out over the configured {@see PushTransport}. The coach/scheduler call this for
 * nudges, the daily briefing, and "band synced / low battery" — the native-app equivalent of the
 * existing browser Web Push path. See tasks/native-ios/02-deployment.md.
 */
class PushService
{
    public function __construct(private PushTransport $transport) {}

    /**
     * @param  array<string,mixed>  $data
     * @return int  number of devices the push was delivered to
     */
    public function send(User $user, string $title, string $body, array $data = []): int
    {
        $sent = 0;
        foreach ($user->pushTokens()->where('platform', 'ios')->get() as $token) {
            if ($this->transport->send($token->token, $title, $body, $data)) {
                $token->forceFill(['last_seen_at' => now()])->saveQuietly();
                $sent++;
            }
        }

        return $sent;
    }
}
