<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\HrSample;
use App\Models\User;
use App\Services\Wearables\DeviceIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The 24/7 HR graph: the band's `hr_trend` summary → hr_samples rows → the /me/hr graph endpoint.
 */
class HrTrendTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(User $user, array $samples): void
    {
        $conn = $user->profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan-band', 'status' => 'connected',
        ]);
        app(DeviceIngestionService::class)->ingest($conn, [
            'summaries' => [['kind' => 'hr_trend', 'samples' => $samples]],
        ]);
    }

    public function test_hr_trend_summary_persists_samples(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $t = Carbon::now('UTC')->timestamp;

        $this->ingest($user, [
            ['t' => $t - 120, 'bpm' => 61, 'conf' => 95],
            ['t' => $t - 60, 'bpm' => 64, 'conf' => 90],
            ['t' => $t, 'bpm' => 72, 'conf' => 88],
            ['t' => $t, 'bpm' => 999, 'conf' => 50],   // garbage bpm → dropped
        ]);

        $this->assertSame(3, HrSample::where('profile_id', $user->profile->id)->count());
    }

    public function test_resend_does_not_double_insert(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $t = Carbon::now('UTC')->timestamp;
        $batch = [['t' => $t - 60, 'bpm' => 60, 'conf' => 95], ['t' => $t, 'bpm' => 70, 'conf' => 95]];

        $this->ingest($user, $batch);
        $this->ingest($user, $batch);   // same points again (retry / ring overlap)

        $this->assertSame(2, HrSample::where('profile_id', $user->profile->id)->count());
    }

    public function test_hr_endpoint_returns_graph_and_resting(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        $base = Carbon::now('UTC')->startOfDay()->addHours(8)->timestamp;
        $samples = [];
        foreach (range(0, 19) as $i) {
            $samples[] = ['t' => $base + $i * 60, 'bpm' => 55 + $i, 'conf' => 95];   // 55..74
        }
        $this->ingest($user, $samples);

        $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me/hr');

        $res->assertOk()
            ->assertJsonStructure(['date', 'points', 'resting_hr', 'min', 'max', 'avg', 'count'])
            ->assertJsonPath('count', 20)
            ->assertJsonPath('min', 55)
            ->assertJsonPath('max', 74);

        // Resting ≈ 5th percentile of 55..74 → the low end, not the average.
        $this->assertLessThanOrEqual(58, $res->json('resting_hr'));
    }
}
