<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\User;
use App\Support\Cycle;
use App\Support\Hydration;
use Database\Seeders\CoachSeeder;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\HealthDataSeeder;
use Database\Seeders\MealsSeeder;
use Database\Seeders\PhysiqueSeeder;
use Database\Seeders\SleepRecoverySeeder;
use Database\Seeders\WorkoutsSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provision a fully populated DEMO / App-Review account in one shot.
 *
 * Apple's reviewers can't pair the DIY band, so the app must be reviewable with a "lived-in"
 * account: this creates (or refreshes) a user, marks onboarding done, and seeds ~3 weeks of
 * correlated sleep + recovery, meals & hydration, workouts, steps/biomarkers, a dream-physique
 * goal and a coach conversation — all through the SAME seeders `db:seed` uses, scoped to this
 * profile. Re-running always yields a clean, identical demo (idempotent).
 *
 *   php artisan titan:seed-demo reviewer@titan.app
 *   php artisan titan:seed-demo reviewer@titan.app --password='Review-Me-1234' --female
 */
class SeedDemo extends Command
{
    protected $signature = 'titan:seed-demo
        {email : The reviewer login email}
        {--password= : Login password (a strong one is generated if omitted)}
        {--name=Demo Reviewer : Display name}
        {--female : Seed a female profile + menstrual-cycle data (showcases the Cycle feature)}';

    protected $description = 'Provision a fully populated demo / App-Review account (login + ~3 weeks of realistic data).';

    /** Profile relations wiped before re-seeding so a re-run is always a clean, identical demo. */
    private const WIPE = [
        'sleepLogs', 'recoveryLogs', 'meals', 'workouts', 'activitySessions', 'dailyActivity',
        'progressPhotos', 'physiqueGoals', 'livingGoalRenders', 'mealSuggestions', 'conversations',
        'menstrualCycles', 'cycleLogs', 'biomarkerReadings', 'bodyMetrics', 'hydrationLogs', 'coachMemories',
    ];

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) ($this->option('password') ?: 'Titan-'.Str::random(10));
        $female = (bool) $this->option('female');

        $user = User::firstOrNew(['email' => $email]);
        $user->name = (string) $this->option('name');
        $user->password = Hash::make($password);
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        $profile = $user->ensureProfile();

        $this->line("Demo account: <info>{$user->name}</info> <{$email}>  (user {$user->id}, profile {$profile->id})");

        // Fresh slate so the seed is deterministic on every run.
        foreach (self::WIPE as $rel) {
            if (method_exists($profile, $rel)) {
                $profile->{$rel}()->delete();
            }
        }

        // A completed onboarding: NOT-NULL goal/tone, body basics, macro targets, onboarded gate open.
        $profile->forceFill([
            'display_name' => $user->name,
            'sex' => $female ? 'female' : 'male',
            'birthdate' => Carbon::today()->subYears($female ? 29 : 32)->toDateString(),
            'height_cm' => $female ? 168 : 179,
            'primary_goal' => $female
                ? 'Lean strength, steady energy across my cycle, longevity'
                : '+10 lbs lean muscle, longevity, strong biomarkers',
            'coach_tone' => 'balanced',
            'onboarded_at' => now(),
            'settings' => array_merge($profile->settings ?? [], [
                'macro_targets' => $female
                    ? ['calories' => 2100, 'protein_g' => 150, 'carbs_g' => 210, 'fat_g' => 65]
                    : ['calories' => 2600, 'protein_g' => 185, 'carbs_g' => 265, 'fat_g' => 78],
            ]),
        ])->save();

        // ── Seed the domains through the real seeders, scoped to this profile ─────────────
        $this->components->task('Steps, biomarkers & body metrics', fn () => $this->seed(HealthDataSeeder::class, $profile));
        $this->components->task('Exercise library', fn () => $this->seed(ExerciseLibrarySeeder::class, null));
        $this->components->task('Workouts', fn () => $this->seed(WorkoutsSeeder::class, $profile));
        $this->components->task('Meals', fn () => $this->seed(MealsSeeder::class, $profile));
        $this->components->task('Sleep & recovery (~3 weeks)', fn () => $this->seed(SleepRecoverySeeder::class, $profile));
        $this->components->task('Dream physique + progress', fn () => $this->seed(PhysiqueSeeder::class, $profile));
        $this->components->task('Coach conversation', fn () => $this->seed(CoachSeeder::class, $profile));

        // Today's hydration so the Fuel card isn't empty.
        $this->components->task('Hydration (today)', function () use ($profile) {
            Hydration::add($profile, 500);
            Hydration::add($profile, 250);
        });

        if ($female) {
            $this->components->task('Menstrual cycle history', function () use ($profile) {
                $s = $profile->settings ?? [];
                $s['cycle'] = array_merge($s['cycle'] ?? [], ['enabled' => true, 'avg_length' => 29, 'avg_period' => 5]);
                $profile->update(['settings' => $s]);
                // Two prior cycles + the current one → predictions, phase, history all populate.
                Cycle::startPeriod($profile, Carbon::today()->subDays(58));
                Cycle::startPeriod($profile, Carbon::today()->subDays(29));
                Cycle::startPeriod($profile, Carbon::today()->subDays(1));
                Cycle::logDay($profile, Carbon::today(), ['flow' => 'medium', 'symptoms' => ['cramps', 'fatigue'], 'mood' => 3, 'energy' => 3]);
            });
        }

        $this->newLine();
        $this->info('✓ Demo account ready.');
        $this->newLine();
        $this->line('  <comment>Email</comment>    '.$email);
        $this->line('  <comment>Password</comment> '.$password);
        $this->line('  <comment>Server</comment>   '.config('app.url'));
        $this->newLine();
        $this->line('Paste the email + password into App Store Connect → App Review Information → Sign-In Required.');

        return self::SUCCESS;
    }

    /** Run a domain seeder, hanging its data off $profile (null = a global seeder like the exercise library). */
    private function seed(string $class, ?Profile $profile): void
    {
        /** @var Seeder $seeder */
        $seeder = $this->laravel->make($class);
        $seeder->setContainer($this->laravel)->setCommand($this);
        if ($profile !== null && property_exists($seeder, 'seedProfile')) {
            $seeder->seedProfile = $profile;
        }
        $seeder->run();
    }
}
