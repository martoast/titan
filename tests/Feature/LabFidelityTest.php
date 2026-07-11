<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Lab\NightScript;
use App\Services\Lab\VirtualBand;
use App\Services\Simulator\BiosignalSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LAB PIPELINE FIDELITY · Part B — the VirtualBand now produces what the real watch produces: ppg_raw (not
 * the synthetic ibi), the summary channels (hr_trend / motion_trend / activity) it used to skip, and the
 * real per-window HR jitter as a knob. A clean-HR sim can never catch an HR-noise staging bug.
 */
class LabFidelityTest extends TestCase
{
    use RefreshDatabase;

    private function band(int $seed = 42): VirtualBand
    {
        $profile = User::factory()->create()->ensureProfile();
        $device = $profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan-band', 'status' => 'connected',
            'device_id' => 'lab-'.$profile->id, 'timezone' => 'UTC',
        ]);

        return new VirtualBand(new BiosignalSimulator($seed), $device, 'lab-secret');
    }

    private function script(float $jitter = 0.0): NightScript
    {
        return new NightScript(
            scenario: 'test', promises: [2, 5], tz: 'UTC',
            bedAt: CarbonImmutable::parse('2026-07-11 05:00:00', 'UTC'),
            blocks: [
                ['stage' => 'deep', 'minutes' => 40], ['stage' => 'light', 'minutes' => 120],
                ['stage' => 'rem', 'minutes' => 40], ['stage' => 'wake', 'minutes' => 8],
            ],
            hrJitterSd: $jitter,
        );
    }

    public function test_overnight_windows_are_ppg_raw_not_ibi(): void
    {
        $rendered = $this->band()->renderNight($this->script());
        $this->assertNotEmpty($rendered['live']);
        foreach ($rendered['live'] as $w) {
            $this->assertSame('ppg_raw', $w['kind'], 'the real band emits ppg_raw overnight, never ibi');
            $this->assertSame('banglejs2', $w['src']);
            $this->assertArrayNotHasKey('confidence', $w, 'no synthetic confidence field');
        }
    }

    public function test_it_emits_the_summary_channels_the_lab_used_to_skip(): void
    {
        $summaries = $this->band()->renderSummaries($this->script());
        $byKind = collect($summaries)->keyBy('kind');

        $this->assertTrue($byKind->has('motion_trend'), 'T10 dense motion → motion_samples');
        $this->assertTrue($byKind->has('hr_trend'), 'T5 HR trend → hr_samples');
        $this->assertTrue($byKind->has('activity'), 'T8 steps → daily_activity');

        // motion_trend is the DENSE milli-g EMA channel (~14-199), NOT the ~1-3 proxy scale.
        $motions = array_column($byKind['motion_trend']['samples'], 'motion');
        $this->assertNotEmpty($motions);
        $this->assertGreaterThan(10, max($motions), 'milli-g EMA scale, not the np.std proxy');
        // hr_trend carries per-minute points with a confidence.
        $this->assertArrayHasKey('bpm', $byKind['hr_trend']['samples'][0]);
        $this->assertArrayHasKey('conf', $byKind['hr_trend']['samples'][0]);
    }

    public function test_hr_jitter_knob_shifts_the_window_hr(): void
    {
        $band = $this->band();
        $start = CarbonImmutable::parse('2026-07-11 05:00:00', 'UTC');
        // Same stage, an ibi window with vs without a big +offset → the offset raises the window's mean HR.
        $clean = $band->renderWindow('light', $start, 30, 'ibi', 25, 0.0);
        $shifted = $band->renderWindow('light', $start, 30, 'ibi', 25, 25.0);
        $hr = fn ($w) => 60000.0 / (array_sum($w['ibi_ms']) / max(1, count($w['ibi_ms'])));
        $this->assertGreaterThan($hr($clean) + 12, $hr($shifted), 'a +25 bpm offset meaningfully raises the window HR');
    }
}
