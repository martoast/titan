<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationService;
use App\Support\MealCoach;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Proactive meal reminder -- the "you forgot to eat again" nudge.
 *
 *   php artisan meals:remind                 # every push-enabled profile due/overdue for a meal
 *   php artisan meals:remind --profile=1
 *
 * Runs on the schedule (every 15 min). For each profile it computes the meal coach; if the current
 * slot is due or overdue and we haven't already nudged THIS slot, it sends a push + in-app reminder
 * with the protein/calories that meal should carry. De-duped per (date, slot) in profile settings so
 * the user gets one buzz per meal, not one every 15 minutes. Opt-out: settings['meal_reminders'] = false.
 */
class MealReminder extends Command
{
    protected $signature = 'meals:remind {--profile= : Only run for this profile id}';

    protected $description = 'Nudge profiles to eat when a planned meal is due or overdue.';

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        foreach ($this->resolveProfiles() as $profile) {
            if (! \App\Support\Reminders::enabled($profile, 'meals')) {
                continue;
            }
            try {
                $m = MealCoach::assess($profile);
                if (! in_array($m['status'], ['soon', 'overdue'], true)) {
                    continue;
                }
                // One nudge per slot: key on today's date + how many meals are already in.
                $slotKey = now()->toDateString().':'.$m['meals_logged'];
                if (($profile->settings['meal_last_reminder'] ?? null) === $slotKey) {
                    continue;
                }

                \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
                $title = $m['status'] === 'overdue' ? __('🍽️ Eat now -- you\'re overdue') : __('🍽️ Time to eat');
                $notifications->notify($profile, $title, $m['advice'], '/meals/add', 'meal');

                $profile->settings = array_merge($profile->settings ?? [], ['meal_last_reminder' => $slotKey]);
                $profile->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[meals] reminder failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Meal reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /** Profiles that opted into push (have a subscription) -- those are the ones who want a buzz. */
    private function resolveProfiles()
    {
        if ($id = $this->option('profile')) {
            return Profile::where('id', $id)->get();
        }
        $ids = PushSubscription::query()->distinct()->pluck('profile_id');

        return Profile::whereIn('id', $ids)->get();
    }
}
