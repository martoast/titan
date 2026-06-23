<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use App\Services\Push\PushService;
use App\Services\Push\PushTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Push fans a message out to all of a user's registered iOS devices via the configured transport.
 * Uses a spy transport so the path is fully tested without Apple credentials.
 */
class PushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_to_each_ios_device_token(): void
    {
        $user = User::factory()->create();
        $user->pushTokens()->create(['platform' => 'ios', 'token' => 'tok-a']);
        $user->pushTokens()->create(['platform' => 'ios', 'token' => 'tok-b']);
        $user->pushTokens()->create(['platform' => 'android', 'token' => 'tok-c']); // ignored (iOS-only)

        $spy = new class implements PushTransport
        {
            public array $sent = [];

            public function send(string $deviceToken, string $title, string $body, array $data = []): bool
            {
                $this->sent[] = compact('deviceToken', 'title', 'body', 'data');

                return true;
            }
        };

        $count = (new PushService($spy))->send($user, 'Band synced', 'Your night is in.', ['type' => 'band_synced']);

        $this->assertSame(2, $count);
        $this->assertEqualsCanonicalizing(['tok-a', 'tok-b'], array_column($spy->sent, 'deviceToken'));
        $this->assertSame('Band synced', $spy->sent[0]['title']);
        $this->assertSame('band_synced', $spy->sent[0]['data']['type']);
    }

    public function test_default_transport_is_resolvable(): void
    {
        // The container binds a working (log) transport so PushService is usable out of the box.
        $this->assertInstanceOf(PushService::class, app(PushService::class));
    }
}
