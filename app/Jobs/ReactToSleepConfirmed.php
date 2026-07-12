<?php

namespace App\Jobs;

use App\Models\SleepLog;
use App\Services\Notifications\NotificationService;
use App\Support\CoachReaction;
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
        // Push AND email -- email is what reaches the native app without a paid Apple account.
        $notifications->notify($profile, $msg['title'], $msg['push'], '/coach', 'sleep', email: true);

        // The chat message is trajectory-grounded (P4): a real sleep card (drawn by the P2 widget) plus a
        // read that reasons over where recovery/sleep are HEADING, not just last night. Falls back to the
        // templated summary if the model is unavailable, so the morning note is never dropped.
        $assess = rescue(fn () => SleepCoach::assess($profile), null, false);
        $card = array_filter([
            'type' => 'sleep',
            'hours' => $log->duration_min ? round($log->duration_min / 60, 1) : null,
            'performance' => $assess['performance_pct'] ?? null,
            'debt' => isset($assess['debt_h']) ? round((float) $assess['debt_h'], 1) : null,
            'status' => $assess['label'] ?? null,
            'stages' => array_filter([
                'deep' => $log->deep_min, 'rem' => $log->rem_min,
                'light' => $log->light_min, 'awake' => $log->awake_min,
            ], fn ($v) => $v !== null),
            'low_confidence' => (bool) $log->low_confidence ?: null,
        ], fn ($v) => $v !== null && $v !== []);

        $facts = 'Confirmed night. '.trim(($msg['push'] ?? '')).' Assessment: '.($assess['label'] ?? 'n/a')
            .(($assess['advice'] ?? '') !== '' ? ' — '.$assess['advice'] : '');

        // The story of the night (onset, deep distribution, wakes, REM cycles) — so the coach's read
        // matches what the app shows on the timeline. One narrative, both surfaces.
        $story = rescue(fn () => class_exists(\App\Support\SleepStory::class) ? \App\Support\SleepStory::forNight($log, $assess['need_h'] ?? null) : null, null, false);
        if (is_array($story) && ! empty($story['text'])) {
            $facts .= ' STORY OF THE NIGHT (weave this in, teach the why briefly): '.$story['text'];
        }

        // Low-signal night (poor PPG contact → mostly NODATA): be honest that the read is an ESTIMATE and
        // proactively suggest a fit check — the #1 cause and it's user-fixable. Don't state a confident number.
        if ($log->low_confidence) {
            $cov = $log->coverage !== null ? ' (only ~'.round($log->coverage * 100).'% of the night had a clean signal)' : '';
            $facts .= " IMPORTANT: this was a LOW-SIGNAL night{$cov} — present the duration/stages as a rough ESTIMATE,"
                .' not a confident number, and gently suggest they check the band fit (snug, above the wrist bone) so tonight reads cleanly.';
        }
        $body = CoachReaction::ground($profile, "last night's sleep", $facts, $msg['body'], $card);

        $convo = $profile->conversations()->firstOrCreate(['title' => 'Daily Briefings']);
        $convo->messages()->create(['role' => 'assistant', 'content' => $body]);

        if ($night) {
            $settings = $profile->settings ?? [];
            $settings['sleep_summary_reacted'] = $night;
            $profile->update(['settings' => $settings]);
        }
    }
}
