<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Meal;
use App\Services\Notifications\NotificationService;
use App\Support\Macros;
use App\Support\Reminders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Proactive protein nudge. When a meal is logged and the day is winding down but protein is still
 * meaningfully short of target, the coach reaches out on its own — a push + a note in the chat with
 * the gap and a quick way to close it. Once per day, and only if the user wants meal nudges. Like a
 * coach who notices you're behind and says something before it's too late to fix.
 */
class ReactToMealLogged implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Don't bother before this local hour — earlier there's plenty of day left to catch up. */
    private const EVENING_HOUR = 17;
    /** Nudge only when at least this many grams short… */
    private const MIN_GAP_G = 25;
    /** …and below this fraction of the daily target. */
    private const BEHIND_FRACTION = 0.8;

    public function __construct(public int $mealId) {}

    public function handle(NotificationService $notifications): void
    {
        $meal = Meal::find($this->mealId);
        $profile = $meal?->profile;
        if (! $profile) {
            return;
        }

        // Respect their coaching preference (meal nudges are on for "balanced" and up).
        if (class_exists(Reminders::class) && ! Reminders::enabled($profile, 'meals')) {
            return;
        }

        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $now = Carbon::now($tz);
        $today = $now->toDateString();

        $macros = rescue(fn () => Macros::today($profile), null, false);
        if (! is_array($macros)) {
            return;
        }
        $have = (int) data_get($macros, 'protein.value', 0);
        $target = (int) data_get($macros, 'protein.target', 0);
        if ($target <= 0) {
            return;
        }
        $name = $profile->display_name ? ' '.$profile->display_name : '';

        // WIN: target hit — celebrate once a day, any time (protein IS the foundation).
        if ($have >= $target) {
            if (data_get($profile->settings, 'protein_won') === $today) {
                return;
            }
            $this->announce(
                $profile, $notifications,
                title: '💪 Protein locked in',
                push: "You hit your {$target}g protein target today. Strong.",
                body: "💪 **Protein locked in{$name}.** You hit your **{$target}g** target ({$have}g logged) — "
                    ."that's the foundation for recovery and muscle. Strong day.",
                flag: 'protein_won', today: $today,
            );

            return;
        }

        // NUDGE: behind, late in the day — once a day, evening only.
        if (data_get($profile->settings, 'protein_nudged') === $today) {
            return;
        }
        if ($now->hour < self::EVENING_HOUR) {
            return;
        }
        $remaining = $target - $have;
        if ($remaining < self::MIN_GAP_G || ($have / $target) >= self::BEHIND_FRACTION) {
            return;   // not behind enough to be worth a nudge
        }

        $this->announce(
            $profile, $notifications,
            title: '🍗 Protein’s running low',
            push: "You're at {$have}g of {$target}g today — about {$remaining}g to go before bed.",
            body: "🍗 **Protein check{$name}.** You're at **{$have}g** of your **{$target}g** target today — "
                ."about **{$remaining}g** to go before bed.\n\n"
                ."An easy hit closes it: a scoop of whey, Greek yogurt, cottage cheese, a couple of eggs, "
                ."or a can of tuna. Want a snack that fits the rest of your macros? Just ask.",
            flag: 'protein_nudged', today: $today,
        );
    }

    /** Push + a note in the Daily Briefings thread, then stamp the once-per-day flag. */
    private function announce(\App\Models\Profile $profile, NotificationService $notifications,
                              string $title, string $push, string $body, string $flag, string $today): void
    {
        $notifications->notify($profile, $title, $push, '/coach', 'protein');

        $convo = Conversation::forDay($profile);
        $convo->messages()->create(['role' => 'assistant', 'kind' => ChatMessage::KIND_REACTION, 'content' => $body]);

        $settings = $profile->settings ?? [];
        $settings[$flag] = $today;
        $profile->update(['settings' => $settings]);
    }
}
