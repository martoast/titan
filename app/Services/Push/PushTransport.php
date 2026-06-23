<?php

namespace App\Services\Push;

/**
 * Delivers one push to one device token. The concrete APNs HTTP/2 implementation needs the Apple
 * `.p8` auth key (Key ID + Team ID + bundle id) — which requires the Apple Developer account — so
 * it's swappable: {@see LogPushTransport} is the default until that's configured, then bind
 * {@see ApnsTransport} (token-based JWT over `https://api.push.apple.com`). See tasks/native-ios/.
 */
interface PushTransport
{
    /**
     * @param  array<string,mixed>  $data  silent payload (e.g. ['type' => 'band_synced'])
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool;
}
