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

// Proactive AI coach: morning briefing + evening nudge (behaviour-triggered windows).
Schedule::command('coach:morning-briefing')->dailyAt('07:00')->timezone(config('app.timezone'));
Schedule::command('coach:evening-nudge')->dailyAt('18:30')->timezone(config('app.timezone'));

// Living goal-physique: weekly render of the progress step toward the dream physique.
Schedule::command('physique:living-render')->weeklyOn(1, '06:00');
