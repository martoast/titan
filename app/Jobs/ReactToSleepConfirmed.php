<?php

namespace App\Jobs;

use App\Models\SleepLog;
use App\Services\Notifications\NotificationService;
use App\Support\SleepCoach;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The morning sleep summary — fired only when the user MARKED AWAKE on the band (a confirmed sleep
 * session sealed the night). A push + a chat message with the breakdown + how it sets up the day,
 * exactly like the workout celebration. User-gated by design: no marker → no push, so a nap or a
 * still evening never triggers a wrong-time notification. See {@see SleepCoach::summary} and the
 * sibling {@see ReactToWorkoutSealed}.
 */
class ReactToSleepConfirmed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sleepLogId) {}

    public function handle(NotificationService $notifications): void
    {
        $log = SleepLog::with('profile')->find($this->sleepLogId);
        $profile = $log?->profile;
        if (! $profile) {
            return;
        }

        // Once per night.
        $night = $log->slept_at?->toDateString();
        if ($night && data_get($profile->settings, 'sleep_summary_reacted') === $night) {
            return;
        }

        $msg = SleepCoach::summary($profile, $log);
        $notifications->notify($profile, $msg['title'], $msg['push'], '/coach', 'sleep');

        $convo = $profile->conversations()->firstOrCreate(['title' => 'Daily Briefings']);
        $convo->messages()->create(['role' => 'assistant', 'content' => $msg['body']]);

        if ($night) {
            $settings = $profile->settings ?? [];
            $settings['sleep_summary_reacted'] = $night;
            $profile->update(['settings' => $settings]);
        }
    }
}
