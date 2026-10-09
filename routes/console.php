<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Titan scheduled jobs ---
// Seal completed nights into the authoritative whole-night HRV/recovery row.
Schedule::command('biosignal:seal-nights')->hourly()->withoutOverlapping();

// Recover nights whose band data buffered offline and uploaded AFTER the seal (the "phone died
// mid-night" case): re-seal the affected night and fire the sleep summary/email that was missed
// because no live phone marked awake. Idempotent + self-deduping (see RecoverLateNights).
Schedule::command('sleep:recover-late')->everyThirtyMinutes()->withoutOverlapping();

// Recover a night stranded in "computing" (its stage pass never finished — e.g. biosignal was
// down/unhealthy at seal time, so the hypnogram is empty and the app shows an eternal loading card).
// Re-runs the CONFIRMED seal once biosignal is healthy again; idempotent (see RecoverStuckNights).
Schedule::command('sleep:recover-stuck')->everyFifteenMinutes()->withoutOverlapping();

// Seal completed workouts into activity_sessions (classification + TRIMP + VO2max + HRR).
Schedule::command('biosignal:seal-activities')->everyFifteenMinutes()->withoutOverlapping();

// Pull fresh CGM readings for glucose-enabled profiles (Nightscout / etc.) — incremental + dedup.
Schedule::command('glucose:sync')->everyFiveMinutes()->withoutOverlapping();

// Walk-after-spike: when glucose is actively rising, offer a 2-min walk (Dunstan ~24–30% cut). Waking hours only.
Schedule::command('glucose:walk-nudge')->everyFifteenMinutes()->between('08:00', '21:00')->timezone(config('app.timezone'))->withoutOverlapping();

// Proactive AI coach: morning briefing + evening nudge (behaviour-triggered windows).
Schedule::command('coach:morning-briefing')->dailyAt('07:00')->timezone(config('app.timezone'));
Schedule::command('coach:evening-nudge')->dailyAt('18:30')->timezone(config('app.timezone'));

// Meal-timing coach: nudge people who forget to eat when a planned meal comes due (per-slot deduped).
Schedule::command('meals:remind')->everyFifteenMinutes()->withoutOverlapping();

// Weekly coaching review — the longitudinal "is this working?" arc (Sunday evening).
Schedule::command('coach:weekly-review')->weeklyOn(0, '18:00')->timezone(config('app.timezone'));

// Living knowledge (COACH v2 · P3): synthesize each profile's WEEK OF DATA into durable Brain pages
// (patterns / milestones / shifts), so the wiki grows from data — not only from chat at compaction.
Schedule::command('coach:enrich-knowledge')->weeklyOn(1, '05:00')->timezone(config('app.timezone'));

// The correlation engine: learn each user's "what helps/hurts my recovery" overnight (after seals),
// and fire a one-time Discovery push when a new pattern is confirmed.
Schedule::command('insights:behavior')->dailyAt('05:30')->timezone(config('app.timezone'));

// Proactive coach through the day — gated by each profile's coaching intensity + per-type prefs.
Schedule::command('coach:nudge training')->dailyAt('08:30')->timezone(config('app.timezone'));
Schedule::command('coach:nudge cycle')->dailyAt('07:30')->timezone(config('app.timezone'));
Schedule::command('coach:nudge move')->dailyAt('14:30')->timezone(config('app.timezone'));
// Strain coach: mid-afternoon, tell the user where their day-strain sits vs the recovery-based target
// and the concrete session to hit it (Whoop-style) — emailed, gated on the 'strain' reminder type.
Schedule::command('coach:nudge strain')->dailyAt('15:30')->timezone(config('app.timezone'));
Schedule::command('coach:nudge sleep')->everyThirtyMinutes()->between('20:00', '23:30')->timezone(config('app.timezone'));

// Stress monitor: sample the day strip every 15 min from the HR trend + motion gate, and — during
// waking hours — offer a breathing minute when stress has held high for a sustained window.
Schedule::command('stress:sample')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('stress:nudge')->everyThirtyMinutes()->between('09:00', '21:00')->timezone(config('app.timezone'));

// Living goal-physique: weekly render of the progress step toward the dream physique.
Schedule::command('physique:living-render')->weeklyOn(1, '06:00');

// Longevity Index: weekly Titan Age snapshot so pace-of-aging becomes a real trend over months.
Schedule::command('longevity:snapshot')->weeklyOn(1, '05:30')->timezone(config('app.timezone'));

// Community: Sunday-evening "week vs the group" recap push + a nightly badge sweep (so time-window
// achievements like 100km-month / streaks land even on a day nothing sealed).
Schedule::command('community:weekly-recap')->weeklyOn(0, '19:00')->timezone(config('app.timezone'));
Schedule::command('community:badge-sweep')->dailyAt('04:30')->timezone(config('app.timezone'));

// App Review demo account: `titan:seed-demo` dates every night, meal and recovery score relative
// to the run, so the account it leaves behind is only "lived-in" for a day or two — then readiness
// reads null, the home screen shows "No data · Connect a device", and that is what a reviewer sees.
// It has gone empty on us twice. Refresh it nightly instead of before each submission. Re-seeding
// keeps the existing password (see SeedDemo::handle), so App Store Connect never goes out of date.
// Unset DEMO_REVIEWER_EMAIL — the default — schedules nothing, so a self-hosted Titan never grows
// a reviewer account.
if (filled($demoReviewer = config('demo.reviewer_email'))) {
    Schedule::command('titan:seed-demo', [$demoReviewer])
        ->dailyAt('04:00')
        ->timezone(config('app.timezone'))
        ->withoutOverlapping();
}
