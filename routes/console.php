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

// Seal completed workouts into activity_sessions (classification + TRIMP + VO2max + HRR).
Schedule::command('biosignal:seal-activities')->everyFifteenMinutes()->withoutOverlapping();

// Proactive AI coach: morning briefing + evening nudge (behaviour-triggered windows).
Schedule::command('coach:morning-briefing')->dailyAt('07:00')->timezone(config('app.timezone'));
Schedule::command('coach:evening-nudge')->dailyAt('18:30')->timezone(config('app.timezone'));

// Meal-timing coach: nudge people who forget to eat when a planned meal comes due (per-slot deduped).
Schedule::command('meals:remind')->everyFifteenMinutes()->withoutOverlapping();

// Weekly coaching review — the longitudinal "is this working?" arc (Sunday evening).
Schedule::command('coach:weekly-review')->weeklyOn(0, '18:00')->timezone(config('app.timezone'));

// The correlation engine: learn each user's "what helps/hurts my recovery" overnight (after seals),
// and fire a one-time Discovery push when a new pattern is confirmed.
Schedule::command('insights:behavior')->dailyAt('05:30')->timezone(config('app.timezone'));

// Proactive coach through the day — gated by each profile's coaching intensity + per-type prefs.
Schedule::command('coach:nudge training')->dailyAt('08:30')->timezone(config('app.timezone'));
Schedule::command('coach:nudge cycle')->dailyAt('07:30')->timezone(config('app.timezone'));
Schedule::command('coach:nudge move')->dailyAt('14:30')->timezone(config('app.timezone'));
Schedule::command('coach:nudge sleep')->everyThirtyMinutes()->between('20:00', '23:30')->timezone(config('app.timezone'));

// Living goal-physique: weekly render of the progress step toward the dream physique.
Schedule::command('physique:living-render')->weeklyOn(1, '06:00');
