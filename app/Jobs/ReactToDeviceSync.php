<?php

namespace App\Jobs;

use App\Models\Profile;
use App\Models\SleepLog;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The "your band just synced" moment -- the magic that makes Titan feel alive. When fresh overnight
 * recovery lands from the wearable, the coach reacts on its own: a push + a note in the chat with the
 * morning read (readiness + today's focus), once per day. This is the sensor pipeline reaching out
 * through the AI, instead of the user always having to ask.
 */
class ReactToDeviceSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $profileId) {}

    public function handle(NotificationService $notifications): void
    {
        $profile = Profile::find($this->profileId);
        if (! $profile) {
            return;
        }

        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->toDateString();

        // Greet the morning's data once a day, and only if they want morning coaching.
        if (data_get($profile->settings, 'device_greeted') === $today) {
            return;
        }
        if (class_exists(\App\Support\Reminders::class) && ! \App\Support\Reminders::enabled($profile, 'briefing')) {
            return;
        }

        // Progressive summary: if tonight's confirmed NIGHT is still being STAGED, hold the greeting until it
        // FINALIZES (the seal re-dispatches us then) so the readiness we announce reflects the complete night,
        // not the duration-only placeholder. Nights only — a mid-staging NAP must not suppress the morning read
        // (the finalize re-dispatch is night-only). Bounded to a fresh row (30 min ≫ the ~2 min stage defer, so
        // it never truncates a legitimately slow finalize) so a hard-crashed finalize can't wedge the greeting
        // forever — and a capped finalize settles the placeholder anyway (settleComputingPlaceholder). UTC to
        // match the DB-stored updated_at regardless of app.timezone.
        $stillStaging = SleepLog::where('profile_id', $profile->id)
            ->where('is_nap', false)
            ->where('stage_status', 'computing')
            ->where('updated_at', '>=', Carbon::now('UTC')->subMinutes(30))
            ->exists();
        if ($stillStaging) {
            return;   // not marked greeted → the finalize (or a later sync) fires it with the settled night
        }

        // Wait for the read to be computable -- if recovery isn't ready yet, a later sync will fire this.
        $score = class_exists(\App\Support\Readiness::class)
            ? rescue(fn () => \App\Support\Readiness::compute($profile)['score'] ?? null, null, false)
            : null;
        if ($score === null) {
            return;
        }
        $label = rescue(fn () => \App\Support\Readiness::compute($profile)['label'] ?? '', '', false);
        $focus = class_exists(\App\Support\DailyFocus::class)
            ? rescue(fn () => \App\Support\DailyFocus::compute($profile)['headline'] ?? null, null, false)
            : null;

        // Atomic claim: two workers (a sync-triggered job and the finalize re-dispatch) can both pass the
        // persistent `device_greeted` check in the race window before it's written. Cache::add is atomic, so
        // only one wins and greets; the loser bails. (The persistent flag below still guards across restarts.)
        if (! Cache::add("device_greeted:{$profile->id}:{$today}", true, Carbon::now()->addDay())) {
            return;
        }

        $body = "Your overnight data just synced -- readiness {$score}".($label ? " ({$label})" : '').'.'.($focus ? " Today's focus: {$focus}." : '');

        $notifications->notify($profile, '🌅 Your recovery is in', $body, '/coach', 'sync');

        // Drop it into the Daily Briefings thread so it's waiting in the coach UI.
        $convo = $profile->conversations()->firstOrCreate(['title' => 'Daily Briefings']);
        $convo->messages()->create([
            'role' => 'assistant',
            'content' => "🌅 **Your band just synced your overnight data.**\n\n{$body}\n\nAsk me how to make the most of today.",
        ]);

        $settings = $profile->settings ?? [];
        $settings['device_greeted'] = $today;
        $profile->update(['settings' => $settings]);
    }
}
