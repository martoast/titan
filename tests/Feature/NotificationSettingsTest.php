<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_renders(): void
    {
        $u = User::factory()->create();
        $resp = $this->actingAs($u)->get('/notifications/settings');
        $resp->assertOk();
        $resp->assertSee('How present should I be?');
        $resp->assertSee('Turn on notifications');   // fresh user has no push subscription yet
        $resp->assertSee('Fine-tune');
    }

    public function test_intensity_section_updates_the_setting(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post('/notifications/settings', ['section' => 'intensity', 'intensity' => 'intense'])
            ->assertRedirect();
        $this->assertSame('intense', Reminders::intensity($u->refresh()->profile));
    }

    public function test_types_section_sets_explicit_overrides(): void
    {
        $u = User::factory()->create();
        $u->profile->update(['settings' => ['coaching_intensity' => 'minimal']]);

        // Turn meals + move ON explicitly even though minimal would have them off.
        $this->actingAs($u)->post('/notifications/settings', ['section' => 'types', 'types' => ['meals' => '1', 'move' => '1']])
            ->assertRedirect();

        $p = $u->refresh()->profile;
        $this->assertTrue(Reminders::enabled($p, 'meals'));
        $this->assertTrue(Reminders::enabled($p, 'move'));
        $this->assertFalse(Reminders::enabled($p, 'sleep'));   // unchecked → explicitly off
    }

    public function test_test_push_needs_a_subscription_then_sends(): void
    {
        $u = User::factory()->create();
        $p = $u->profile;

        // No subscription → guided, no notification created.
        $this->actingAs($u)->post('/notifications/test')->assertJson(['ok' => false, 'reason' => 'no_subscription']);
        $this->assertSame(0, Notification::where('profile_id', $p->id)->count());

        // With a subscription → a test notification is created (push send is best-effort).
        PushSubscription::create(['profile_id' => $p->id, 'endpoint' => 'https://x/'.$p->id, 'endpoint_hash' => hash('sha256', 'e'.$p->id), 'p256dh' => 'k', 'auth' => 'a']);
        $this->actingAs($u)->post('/notifications/test')->assertJson(['ok' => true]);
        $this->assertDatabaseHas('notifications', ['profile_id' => $p->id, 'title' => '🔔 Titan test']);
    }
}
