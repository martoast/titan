<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * Default transport until the APNs `.p8` is configured: records the push instead of sending it.
 * Lets the whole push path (token resolution, payload, dispatch) run + be tested with no Apple
 * credentials. Swap for ApnsTransport once the developer account + key exist.
 */
class LogPushTransport implements PushTransport
{
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        Log::info('push (log transport)', [
            'token' => substr($deviceToken, 0, 8).'…',
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        return true;
    }
}
