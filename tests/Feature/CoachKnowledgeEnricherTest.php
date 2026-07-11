<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Brain\KnowledgeIngestor;
use App\Support\KnowledgeEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * COACH v2 · Phase 3 — living knowledge. The weekly pass turns the user's DATA into durable, source-
 * tagged wiki knowledge (not just chat), filed through the append-safe KnowledgeIngestor and deduped
 * once per week.
 */
class CoachKnowledgeEnricherTest extends TestCase
{
    use RefreshDatabase;

    private function seedWeek(int $profileId): void
    {
        for ($d = 27; $d >= 0; $d--) {
            RecoveryLog::create([
                'profile_id' => $profileId, 'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => $d < 7 ? 70 : 60, 'resting_hr' => 54, 'updated_via' => 'biosignal:sealed',
            ]);
            SleepLog::create([
                'profile_id' => $profileId, 'slept_at' => Carbon::today()->subDays($d)->toDateString(),
                'is_nap' => false, 'stage_status' => SleepLog::STATUS_FINAL, 'duration_min' => 420,
            ]);
        }
        for ($d = 5; $d >= 1; $d -= 2) {
            ActivitySession::create([
                'profile_id' => $profileId, 'source' => 'band', 'visibility' => 'private',
                'started_at' => Carbon::now()->subDays($d), 'ended_at' => Carbon::now()->subDays($d)->addHour(),
                'duration_min' => 60, 'activity_type' => 'strength', 'is_training' => true, 'trimp' => 90,
            ]);
        }
    }

    public function test_brief_summarises_trajectory_and_the_weeks_events(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $this->seedWeek($profile->id);

        $brief = KnowledgeEnricher::brief($profile);
        $this->assertStringContainsString('TRAJECTORY', $brief);
        $this->assertStringContainsString('THIS WEEK', $brief);
        $this->assertStringContainsString('Training sessions: 3', $brief);
        $this->assertStringContainsString('Nights of sleep logged', $brief);
    }

    public function test_synthesize_no_ops_on_a_thin_profile(): void
    {
        // A profile with no data yields a too-short brief → synthesize returns null BEFORE any AI call,
        // so the pass never fabricates knowledge from nothing (deterministic, independent of AI config).
        $profile = User::factory()->create()->ensureProfile();
        $this->assertNull(app(KnowledgeEnricher::class)->synthesize($profile));
    }

    public function test_synthesize_appends_provenance_when_configured(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $this->seedWeek($profile->id);

        $this->mock(AiService::class, function ($m) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('json')->andReturn([
                'knowledge' => "## Late training hurts deep sleep\nDeep sleep ran ~20% lower after 9pm+ sessions.",
            ]);
        });

        $out = app(KnowledgeEnricher::class)->synthesize($profile);
        $this->assertNotNull($out);
        $this->assertStringContainsString('Late training hurts deep sleep', $out);
        $this->assertStringContainsString('_Source: auto-synthesis from your data', $out, 'auto-pages carry a source + week (provenance)');
    }

    public function test_command_files_once_and_dedupes_within_the_week(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $this->seedWeek($profile->id);

        $this->mock(AiService::class, function ($m) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('json')->andReturn(['knowledge' => 'Durable insight with enough length to pass the floor check comfortably.']);
        });
        // Mock the ingestor so the test doesn't hit the real librarian/embedding chain — we're asserting
        // the command's wiring (synthesize → file once → stamp the week → no-op on re-run), not ingestion.
        $ingestor = \Mockery::mock(KnowledgeIngestor::class);
        $ingestor->shouldReceive('ingest')->once()->andReturn(['created' => [], 'updated' => [], 'message' => 'ok']);
        $this->app->instance(KnowledgeIngestor::class, $ingestor);

        $this->artisan('coach:enrich-knowledge', ['--profile' => $profile->id])->assertSuccessful();
        // Second run in the same week must NOT file again (the ->once() expectation enforces this).
        $this->artisan('coach:enrich-knowledge', ['--profile' => $profile->id])->assertSuccessful();

        $this->assertSame(
            'enrich:'.Carbon::now(config('app.timezone'))->startOfWeek()->toDateString(),
            data_get($profile->fresh()->settings, 'nudge_sent.enrich'),
        );
    }
}
