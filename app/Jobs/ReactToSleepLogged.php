<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\SleepLog;
use App\Services\Notifications\NotificationService;
use App\Support\Reminders;
use App\Support\SleepCoach;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Proactive sleep reaction — the morning counterpart to the protein win/nudge. When a night's sleep
 * lands (the band sealed it), the coach speaks to the two beats that matter: a genuine WIN when you
 * were well rested, or a heads-up when sleep DEBT is building. Once per night, opt-in (Reminders
 * 'sleep'). The middling nights stay quiet — the evening wind-down nudge already owns "tonight".
 */
class ReactToSleepLogged implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sleepLogId) {}

    public function handle(NotificationService $notifications): void
    {
        $log = SleepLog::find($this->sleepLogId);
        $profile = $log?->profile;
        if (! $profile || ! $log->slept_at) {
            return;
        }

        if (class_exists(Reminders::class) && ! Reminders::enabled($profile, 'sleep')) {
            return;
        }

        // Once per night.
        $night = $log->slept_at->toDateString();
        if (data_get($profile->settings, 'sleep_reacted') === $night) {
            return;
        }

        $sleep = rescue(fn () => SleepCoach::assess($profile), null, false);
        if (! is_array($sleep)) {
            return;
        }
        $band = $sleep['band'] ?? null;
        $advice = $sleep['advice'] ?? '';
        $hh = $this->hours($sleep['last_h'] ?? null);
        $name = $profile->display_name ? ' '.$profile->display_name : '';

        if ($band === 'optimal') {
            $title = '😴 Well rested';
            $push = $hh ? "You slept {$hh} — a full night. Make the most of it." : 'A full night of sleep — make the most of it.';
            $body = "😴 **Well rested{$name}.** ".($hh ? "You slept **{$hh}** last night — that covered your need. " : '')
                .$advice." Your recovery and training both bank this. Keep the rhythm.";
        } elseif ($band === 'debt') {
            $debt = $sleep['debt_h'] ?? null;
            $title = '😴 Sleep debt building';
            $push = $debt ? "You're carrying ~{$debt}h of sleep debt. An earlier night helps." : 'Sleep debt is building — an earlier night helps.';
            $body = "😴 **Sleep debt building{$name}.** ".$advice
                ." Want me to plan a wind-down so tonight actually lands? Just ask.";
        } else {
            return;   // good / low / unknown — leave it to the briefing + evening wind-down nudge
        }

        $notifications->notify($profile, $title, $push, '/coach', 'sleep');

        $convo = Conversation::forDay($profile);
        $convo->messages()->create(['role' => 'assistant', 'kind' => ChatMessage::KIND_REACTION, 'content' => $body]);

        $settings = $profile->settings ?? [];
        $settings['sleep_reacted'] = $night;
        $profile->update(['settings' => $settings]);
    }

    /** "7.5h" / "8h", or null. */
    private function hours(?float $h): ?string
    {
        if ($h === null || $h <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($h, 1), '0'), '.').'h';
    }
}
