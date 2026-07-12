<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationService;
use App\Support\Reminders;
use App\Support\WeeklyReview;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The weekly coaching review push (end of week). For each opted-in profile we compile the week and
 * send a concise "here's your week" notification; the full `review` card + the coach's narration are
 * one tap away in the chat (weekly_review tool). Deduped once per calendar week.
 *
 *   php artisan coach:weekly-review
 *   php artisan coach:weekly-review --profile=1
 */
class WeeklyReviewCommand extends Command
{
    protected $signature = 'coach:weekly-review {--profile= : Only this profile id}';

    protected $description = 'Compile and push each opted-in profile their week in review.';

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        foreach ($this->resolveProfiles() as $profile) {
            try {
                if (! Reminders::enabled($profile, 'review')) {
                    continue;
                }
                // Everything below (compile()'s prose, the push copy) renders in the athlete's language.
                \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
                $review = WeeklyReview::compile($profile);
                if (! $review) {
                    continue;
                }
                // Freeze the week so next week has a real week-over-week comparison + the trend grows.
                WeeklyReview::snapshot($profile);

                $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
                $weekKey = 'review:'.Carbon::now($tz)->startOfWeek()->toDateString();
                if (data_get($profile->settings, 'nudge_sent.review') === $weekKey) {
                    continue;
                }

                $score = $review['score'];
                $delta = $review['score_delta'];
                $lead = $score !== null
                    ? __('Week score :score', ['score' => $score]).($delta ? ' '.__('(:delta vs last week)', ['delta' => ($delta > 0 ? '+' : '').$delta]) : '').'. '
                    : '';
                // Lead the physique north star -- staying on track to the dream physique is the point.
                $phys = '';
                if (! empty($review['physique'])) {
                    $p = $review['physique'];
                    $phys = ' '.__("You're :pct% to your physique", ['pct' => $p['step_pct']]);
                    $phys .= (! empty($p['step_delta']) && $p['step_delta'] > 0) ? __(' (+:pct% this week).', ['pct' => $p['step_delta']]) : '.';
                }
                $streak = $review['streak'] >= 2 ? ' · '.__(':count-week streak 🔥', ['count' => $review['streak']]) : '';
                $bits = collect($review['metrics'])->take(2)->map(fn ($m) => $m['label'].' '.$m['value'])->implode(' · ');
                $body = trim($lead.$review['headline'].'.'.$phys.' '.$bits.$streak.' '.__('Tap for the full review.'));

                $notifications->notify($profile, __('📊 Your week in review'), Str::limit($body, 200), '/coach', 'review');

                // Email the full review too (reaches you even without push enabled).
                if ($email = $profile->user?->email) {
                    try {
                        \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\WeeklyReviewMail($profile, $review));
                    } catch (\Throwable $e) {
                        Log::warning('[coach] weekly review email failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
                    }
                }

                $settings = $profile->settings ?? [];
                $settings['nudge_sent']['review'] = $weekKey;
                $profile->update(['settings' => $settings]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[coach] weekly review failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Weekly reviews sent: {$sent}");

        return self::SUCCESS;
    }

    /** All profiles -- the weekly review goes out by email too, so it isn't limited to push-subscribers. */
    private function resolveProfiles()
    {
        if ($id = $this->option('profile')) {
            return Profile::with('user')->where('id', $id)->get();
        }

        return Profile::with('user')->orderBy('id')->get();
    }

    /** @return array<int,string> schedule line for routes/console.php */
    public static function scheduleLines(): array
    {
        return ["Schedule::command('coach:weekly-review')->weeklyOn(0, '18:00')->timezone(config('app.timezone'));"];
    }
}
