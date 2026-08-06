<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `bedtime` / `wake_time` are the only sleep_logs columns rendered in a TIMEZONE rather than counted in
 * epochs, so whatever resolves that tz decides what hour the user reads on their sleep card.
 *
 * It used to be `wearableConnections()->whereNotNull('timezone')->value('timezone')` — no ORDER BY. The
 * wearable rows are not a device reading: the band/bridge sends no timezone, so DeviceIngestionController
 * stamps every BANGLE connection with `config('app.timezone')` as a DEFAULT, while the Apple Health importer
 * stamps its row with the profile's declared zone. A user in America/Tijuana on an America/Mexico_City
 * install therefore accumulates connection rows carrying two zones an hour apart, and an unordered `value()`
 * returned whichever one the query plan happened to surface — so a sealed night's bedtime could move an hour
 * between seals with no change in the underlying data.
 *
 * Found 2026-08-06 on Alex (profile 1): 15 × America/Mexico_City + 1 × America/Tijuana.
 */
class ProfileEffectiveTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function profileWithConnections(array $timezones, ?string $declared): Profile
    {
        $profile = User::factory()->create()->ensureProfile();
        $profile->update(['settings' => $declared ? ['timezone' => $declared] : []]);
        foreach ($timezones as $i => $tz) {
            $profile->wearableConnections()->create([
                'provider' => $i === 0 ? 'BANGLE' : 'APPLE_HEALTH',
                'source' => 'titan_band',
                'status' => 'connected',
                'device_id' => 'band-'.$i,
                'device_token_hash' => hash('sha256', 'secret-'.$i),
                'timezone' => $tz,
            ]);
        }

        return $profile->fresh();
    }

    public function test_declared_profile_timezone_wins_over_disagreeing_connections(): void
    {
        $profile = $this->profileWithConnections(
            ['America/Mexico_City', 'America/Tijuana'],
            'America/Tijuana',
        );

        $this->assertSame('America/Tijuana', $profile->effectiveTimezone());
    }

    /** The real regression: the answer must not depend on which connection row the DB returns first. */
    public function test_resolution_is_stable_when_connections_disagree(): void
    {
        $profile = $this->profileWithConnections(
            ['America/Mexico_City', 'America/Tijuana'],
            'America/Tijuana',
        );

        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $seen[] = Profile::find($profile->id)->effectiveTimezone();
        }

        $this->assertSame(['America/Tijuana'], array_values(array_unique($seen)));
    }

    /** No declared zone → fall back to connections, but DETERMINISTICALLY (newest first), never arbitrarily. */
    public function test_falls_back_to_newest_connection_when_nothing_declared(): void
    {
        $profile = $this->profileWithConnections(['America/Mexico_City'], null);
        $newest = $profile->wearableConnections()->create([
            'provider' => 'APPLE_HEALTH', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-newest', 'device_token_hash' => hash('sha256', 'newest'),
            'timezone' => 'America/Tijuana',
        ]);
        $newest->forceFill(['updated_at' => now()->addHour()])->save();

        $this->assertSame('America/Tijuana', $profile->fresh()->effectiveTimezone());
    }

    public function test_falls_back_to_app_timezone_with_no_declaration_and_no_connections(): void
    {
        $profile = $this->profileWithConnections([], null);

        $this->assertSame(config('app.timezone'), $profile->effectiveTimezone());
    }

    /** Callers format with this value directly, so a junk setting must not become a thrown DateTimeZone. */
    public function test_unparseable_declared_timezone_is_ignored(): void
    {
        $profile = $this->profileWithConnections(['America/Mexico_City'], null);
        $profile->update(['settings' => ['timezone' => 'Not/AZone']]);

        $this->assertSame('America/Mexico_City', $profile->fresh()->effectiveTimezone());
    }
}
