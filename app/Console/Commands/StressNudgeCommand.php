<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Notifications\NotificationService;
use App\Support\Lang;
use App\Support\Reminders;
use App\Support\StressMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Proactive stress nudge — the real-time interruption that IS the point of a stress monitor. Runs on the
 * schedule during waking hours; when a profile's stress has HELD high for a sustained window (see
 * StressMonitor::sustainedHigh — never a single spike), it offers a 90-second breathing intervention.
 * Opt-in via the 'stress' reminder type; rate-limited to once every few hours so it never nags.
 *
 *   php artisan stress:nudge
 *   php artisan stress:nudge --profile=1
 */
class StressNudgeCommand extends Command
{
    protected $signature = 'stress:nudge {--profile= : Only this profile id}';

    protected $description = 'Offer a breathing minute to profiles whose stress has held high.';

    /** Minimum gap between stress nudges (hours) — one interruption, then leave them alone. */
    private const COOLDOWN_HOURS = 3;

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        foreach ($this->resolveProfiles() as $profile) {
            try {
                if (! Reminders::enabled($profile, 'stress')) {
                    continue;
                }
                if (StressMonitor::sustainedHigh($profile) === null) {
                    continue;
                }
                // Rate-limit: skip if we nudged within the cooldown.
                $last = data_get($profile->settings, 'nudge_sent.stress');
                if ($last && \Illuminate\Support\Carbon::parse($last)->gt(now()->subHours(self::COOLDOWN_HOURS))) {
                    continue;
                }

                App::setLocale(Lang::locale($profile->primary_language));
                $notifications->notify(
                    $profile,
                    __('🧘 Take a breath'),
                    __("Your stress has been running high for a while. A 90-second physiological sigh can bring it down — tap to start."),
                    '/coach',
                    'stress',
                );

                $settings = $profile->settings ?? [];
                $settings['nudge_sent']['stress'] = now()->toIso8601String();
                $profile->update(['settings' => $settings]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[stress] nudge failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Stress nudges sent: {$sent}");

        return self::SUCCESS;
    }

    /** Profiles with a stress sample in the last hour — the only ones there's anything to nudge about. */
    private function resolveProfiles()
    {
        return Profile::query()
            ->when($this->option('profile'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('stressSamples', fn ($q) => $q->where('recorded_at', '>=', now()->subHour()))
            ->orderBy('id')->get();
    }
}
