<?php

namespace App\Console\Commands;

use App\Models\GlucoseReading;
use App\Models\Profile;
use App\Services\Notifications\NotificationService;
use App\Support\GlucoseSpike;
use App\Support\Lang;
use App\Support\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Walk-after-spike nudge (CGM_INTEGRATION P3) — the move that only a system which SEES glucose can make.
 * When a profile's glucose is actively spiking right now, offer a 2-minute walk: Dunstan et al. showed short
 * activity breaks cut the postprandial rise ~24–30% (see MovementBreaks). Wellness, not medical — a spike is
 * normal; this is a gentle "want to blunt it?" Opt-in via the 'glucose' reminder; rate-limited so it never nags.
 *
 *   php artisan glucose:walk-nudge
 *   php artisan glucose:walk-nudge --profile=1
 */
class GlucoseWalkNudgeCommand extends Command
{
    protected $signature = 'glucose:walk-nudge {--profile= : Only this profile id}';

    protected $description = 'Offer a 2-minute walk to profiles whose glucose is actively spiking.';

    /** Minimum gap between walk nudges (hours) — one suggestion per spike, not a nag. */
    private const COOLDOWN_HOURS = 3;

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        foreach ($this->resolveProfiles() as $profile) {
            try {
                if (! Reminders::enabled($profile, 'glucose')) {
                    continue;
                }
                if (GlucoseSpike::active($profile) === null) {
                    continue;
                }
                // Rate-limit: skip if we nudged within the cooldown.
                $last = data_get($profile->settings, 'nudge_sent.glucose');
                if ($last && \Illuminate\Support\Carbon::parse($last)->gt(now()->subHours(self::COOLDOWN_HOURS))) {
                    continue;
                }

                App::setLocale(Lang::locale($profile->primary_language));
                $notifications->notify(
                    $profile,
                    __('🚶 A quick walk?'),
                    __('Your glucose is rising right now. A 2-minute walk can blunt the spike by roughly a quarter — a small move that adds up.'),
                    '/coach',
                    'glucose',
                );

                $settings = $profile->settings ?? [];
                $settings['nudge_sent']['glucose'] = now()->toIso8601String();
                $profile->update(['settings' => $settings]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[glucose] walk nudge failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Glucose walk nudges sent: {$sent}");

        return self::SUCCESS;
    }

    /** Profiles with a glucose reading in the last hour — the only ones there's live data to nudge on. */
    private function resolveProfiles()
    {
        return Profile::query()
            ->when($this->option('profile'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('glucoseReadings', fn ($q) => $q->where('taken_at', '>=', now()->subHour()))
            ->orderBy('id')->get();
    }
}
