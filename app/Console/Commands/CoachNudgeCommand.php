<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationService;
use App\Support\CoachNudge;
use App\Support\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The proactive coach, through the day:
 *
 *   php artisan coach:nudge move        # mid-day move & stretch (if sedentary)
 *   php artisan coach:nudge sleep       # evening wind-down (the hour before target bedtime)
 *   php artisan coach:nudge training    # recovery-aware train/rest nudge
 *   php artisan coach:nudge cycle       # period heads-up (women who track it)
 *
 * For each push-enabled profile that has the nudge type ON (per their coaching intensity), we build the
 * nudge and — if it has something to say and we haven't already sent it this window — push it. The
 * scheduler runs these at sensible times (see scheduleLines()).
 */
class CoachNudgeCommand extends Command
{
    protected $signature = 'coach:nudge {type : move|sleep|training|cycle} {--profile= : Only this profile id}';

    protected $description = 'Send a proactive coaching nudge (move / sleep / training / cycle) to opted-in profiles.';

    public function handle(NotificationService $notifications): int
    {
        $type = (string) $this->argument('type');
        if (! array_key_exists($type, Reminders::TYPES)) {
            $this->error("Unknown nudge type: {$type}");

            return self::FAILURE;
        }

        $sent = 0;
        foreach ($this->resolveProfiles() as $profile) {
            try {
                if (! Reminders::enabled($profile, $type)) {
                    continue;
                }
                $nudge = CoachNudge::build($profile, $type);
                if (! $nudge) {
                    continue;
                }
                // Dedupe per window (e.g. one sleep nudge per night even if checked hourly).
                if (data_get($profile->settings, "nudge_sent.{$type}") === $nudge['key']) {
                    continue;
                }

                $notifications->notify($profile, $nudge['title'], $nudge['body'], $nudge['url'], 'nudge');

                $settings = $profile->settings ?? [];
                $settings['nudge_sent'][$type] = $nudge['key'];
                $profile->update(['settings' => $settings]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[coach] nudge failed', ['profile' => $profile->id, 'type' => $type, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Coach nudges ({$type}) sent: {$sent}");

        return self::SUCCESS;
    }

    /** Push-enabled profiles (those with a subscription want the buzz). */
    private function resolveProfiles()
    {
        if ($id = $this->option('profile')) {
            return Profile::where('id', $id)->get();
        }

        return Profile::whereIn('id', PushSubscription::query()->distinct()->pluck('profile_id'))->get();
    }

    /** @return array<int,string> schedule lines for routes/console.php */
    public static function scheduleLines(): array
    {
        return [
            "Schedule::command('coach:nudge move')->dailyAt('14:30')->timezone(config('app.timezone'));",
            "Schedule::command('coach:nudge training')->dailyAt('08:30')->timezone(config('app.timezone'));",
            "Schedule::command('coach:nudge cycle')->dailyAt('07:30')->timezone(config('app.timezone'));",
            "Schedule::command('coach:nudge sleep')->everyThirtyMinutes()->between('20:00', '23:30')->timezone(config('app.timezone'));",
        ];
    }
}
