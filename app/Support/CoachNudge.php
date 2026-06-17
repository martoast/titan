<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Builds the proactive nudges the coach pushes through the day — move/stretch, sleep wind-down, training
 * (recovery-aware), and cycle heads-ups. Each builder returns a {title, body, url, type, key} array or
 * null when there's nothing worth pinging about. `key` dedupes so a nudge fires at most once per window.
 */
class CoachNudge
{
    /** Dispatch by type. @return array{title:string,body:string,url:string,type:string,key:string}|null */
    public static function build(Profile $profile, string $type, ?Carbon $now = null): ?array
    {
        return match ($type) {
            'move' => self::move($profile, $now),
            'sleep' => self::sleep($profile, $now),
            'training' => self::training($profile, $now),
            'cycle' => self::cycle($profile, $now),
            default => null,
        };
    }

    /** Mid-day move + stretch nudge — only if they've been sedentary so far. */
    public static function move(Profile $profile, ?Carbon $now = null): ?array
    {
        if (! class_exists(\App\Models\DailyActivity::class)) {
            return null;
        }
        $now = self::now($profile, $now);
        $steps = (int) ($profile->dailyActivity()->whereDate('date', $now->toDateString())->value('steps') ?? 0);
        $target = class_exists(\App\Support\StepGoal::class) ? \App\Support\StepGoal::targetFor($profile) : 8000;

        if ($steps >= (int) ($target * 0.45)) {
            return null;   // already moving enough — no nag
        }

        return [
            'title' => '🚶 Time to move',
            'body' => 'Been heads-down? '.number_format($steps).' steps so far. Take 5: a brisk walk + loosen the hips, shoulders and t-spine. Sitting is the tax — pay it down.',
            'url' => '/coach',
            'type' => 'move',
            'key' => 'move:'.$now->toDateString(),
        ];
    }

    /** Evening wind-down — fires in the ~hour before their target bedtime. */
    public static function sleep(Profile $profile, ?Carbon $now = null): ?array
    {
        if (! class_exists(\App\Support\SleepCoach::class)) {
            return null;
        }
        $now = self::now($profile, $now);
        $need = rescue(fn () => \App\Support\SleepCoach::assess($profile)['need_h'] ?? 8.0, 8.0, false);
        $wake = self::averageWake($profile) ?? '07:00';

        // Tonight's target bedtime = tomorrow's wake − sleep need, on the clock.
        $bed = Carbon::parse($now->toDateString().' '.$wake, $now->timezone)->addDay()->subHours($need);
        $minsTo = $now->diffInMinutes($bed, false);
        if ($minsTo < 0 || $minsTo > 60) {
            return null;   // only nudge in the hour before lights-out
        }

        $needTxt = rtrim(rtrim(number_format($need, 1), '0'), '.');

        return [
            'title' => '🌙 Wind down',
            'body' => "Aim for lights out by {$bed->format('g:i A')} to hit your ~{$needTxt}h. Screens down, lights low — sleep is where the gains land.",
            'url' => '/coach',
            'type' => 'sleep',
            'key' => 'sleep:'.$now->toDateString(),
        ];
    }

    /** Recovery-aware training nudge — push hard when primed, back off when run-down. */
    public static function training(Profile $profile, ?Carbon $now = null): ?array
    {
        $now = self::now($profile, $now);
        $verdict = class_exists(\App\Support\Autoregulator::class)
            ? (\App\Support\Autoregulator::assess($profile)['verdict'] ?? 'insufficient')
            : 'insufficient';

        [$title, $body] = match ($verdict) {
            'deload' => ['🧘 Recovery day', 'Your data says back off today — light movement, great food, early night. Deloading now is how you come back stronger.'],
            'back_off' => ['💪 Train smart today', "Recovery's a touch down — train, but keep 2–3 reps in reserve and skip the failure work. Quality over grind."],
            'progress' => ['🔥 Primed — go hard', "Green light: recovered and progressing. Add a set on your focus muscles and chase 1 RIR. Make today count."],
            'hold' => ['💪 Time to train', "Solid and steady — hit today's session and beat last week's logbook somewhere."],
            'adhere' => ['💪 Get the session in', "You're a bit behind your plan this week — the fix is showing up. Let's get today's session logged."],
            default => ['💪 Make today count', "A session today moves you toward your goal. Tell me what you're training and I'll coach you through it and log it."],
        };

        return ['title' => $title, 'body' => $body.self::goalHook($profile), 'url' => '/coach', 'type' => 'training', 'key' => 'train:'.$now->toDateString()];
    }

    /** A short reference to the dream physique to tie a nudge back to the north star. */
    private static function goalHook(Profile $profile): string
    {
        if (! class_exists(\App\Support\PhysiqueProgress::class)) {
            return '';
        }
        $a = rescue(fn () => \App\Support\PhysiqueProgress::assess($profile), null, false);
        if (! $a) {
            return '';
        }

        return $a['verdict'] === 'behind'
            ? " You're {$a['step_pct']}% to your physique — let's not lose ground."
            : " Another step toward your physique ({$a['step_pct']}% there).";
    }

    /** A cycle heads-up when a period is a day or two out (women who track it). */
    public static function cycle(Profile $profile, ?Carbon $now = null): ?array
    {
        if (! class_exists(\App\Support\Cycle::class) || ! \App\Support\Cycle::available($profile)) {
            return null;
        }
        $s = rescue(fn () => \App\Support\Cycle::status($profile), null, false);
        if (! $s || empty($s['has_data']) || ! empty($s['late'])) {
            return null;
        }
        $in = $s['next_period']['in_days'] ?? null;
        if ($in === null || $in < 0 || $in > 2) {
            return null;
        }
        $body = $in === 0
            ? 'Your period may start today. Be kind to yourself — iron-rich food, and ease off if energy dips.'
            : "Your period's likely in ~{$in} day".($in === 1 ? '' : 's').". A good time to top up iron and plan lighter sessions if you need them.";

        return ['title' => '🌸 Cycle heads-up', 'body' => $body, 'url' => '/cycle', 'type' => 'cycle', 'key' => 'cycle:'.($s['next_period']['date'] ?? $in)];
    }

    private static function now(Profile $profile, ?Carbon $now): Carbon
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');

        return $now ? $now->copy()->setTimezone($tz) : Carbon::now($tz);
    }

    /** Average wake time from recent sleep logs (HH:MM), or null. */
    private static function averageWake(Profile $profile): ?string
    {
        if (! class_exists(\App\Models\SleepLog::class)) {
            return null;
        }
        $times = $profile->sleepLogs()->whereNotNull('wake_time')->latest('slept_at')->take(14)->pluck('wake_time');
        if ($times->isEmpty()) {
            return null;
        }
        $mins = $times->map(function ($t) {
            [$h, $m] = array_pad(explode(':', (string) $t), 2, 0);

            return (int) $h * 60 + (int) $m;
        });

        return sprintf('%02d:%02d', intdiv((int) round($mins->avg()), 60), (int) round($mins->avg()) % 60);
    }
}
