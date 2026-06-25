<?php

namespace App\Jobs;

use App\Models\ActivitySession;
use App\Services\Notifications\NotificationService;
use App\Support\WorkoutCoach;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Proactive workout reaction — the moment a session seals (you double-tapped the band to end it, or
 * the auto-detector closed it), the coach celebrates: a push summary lands on your phone AND a chat
 * message drops in congratulating you, scaling recovery advice to how hard it was, and asking one
 * follow-up. The session is already an authoritative activity_sessions row, so the coach can see it
 * and it counts. Once per session. See {@see WorkoutCoach} and the sibling {@see ReactToSleepLogged}.
 */
class ReactToWorkoutSealed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $activitySessionId) {}

    public function handle(NotificationService $notifications): void
    {
        $log = ActivitySession::with('profile')->find($this->activitySessionId);
        $profile = $log?->profile;
        if (! $profile) {
            return;
        }

        // Once per session (guard against any re-seal/retry).
        if (data_get($profile->settings, 'workout_reacted') === $log->id) {
            return;
        }

        $msg = WorkoutCoach::celebrate($log);

        // The summary lands as a push AND an email (the channel native-app users actually receive),
        // opening the coach so the note is right there.
        $notifications->notify($profile, $msg['title'], $msg['push'], '/coach', 'workout', email: true);

        $convo = $profile->conversations()->firstOrCreate(['title' => 'Daily Briefings']);
        $convo->messages()->create(['role' => 'assistant', 'content' => $msg['body']]);

        $settings = $profile->settings ?? [];
        $settings['workout_reacted'] = $log->id;
        $profile->update(['settings' => $settings]);
    }
}
