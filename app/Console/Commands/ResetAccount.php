<?php

namespace App\Console\Commands;

use App\Models\PhysiqueAnalysis;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Reset an account back to a pre-onboarding clean slate — wipes all of a user's Titan data
 * (meals, dream physique, progress photos, coach memory, conversations, programs, biomarkers, …)
 * and clears their profile so the `onboarded` gate sends them through onboarding again. The User
 * row itself (email + password) is kept, so they can just log in and start fresh.
 *
 *   php artisan titan:reset-account 7
 *   php artisan titan:reset-account me@example.com --force
 */
class ResetAccount extends Command
{
    protected $signature = 'titan:reset-account {user : User id or email} {--force : Skip the confirmation prompt}';

    protected $description = "Reset a user to pre-onboarding: wipe their Titan data and re-run onboarding. Keeps the login.";

    /** Profile HasMany relations whose rows get wiped. */
    private const RELATIONS = [
        'knowledgePages', 'biomarkerReadings', 'bodyMetrics', 'meals', 'workouts', 'sleepLogs',
        'recoveryLogs', 'activitySessions', 'dailyActivity', 'progressPhotos', 'physiqueGoals',
        'livingGoalRenders', 'mealSuggestions', 'conversations', 'wearableConnections',
        'deviceIngestions', 'menstrualCycles', 'cycleLogs', 'trainingPrograms', 'coachMemories',
        'weeklySnapshots',
    ];

    public function handle(): int
    {
        $arg = (string) $this->argument('user');
        $user = ctype_digit($arg) ? User::find((int) $arg) : User::where('email', $arg)->first();

        if (! $user) {
            $this->error("No user found for \"{$arg}\".");

            return self::FAILURE;
        }

        $this->line("User: <info>{$user->name}</info> <{$user->email}>  (id {$user->id})");

        $profile = $user->profile;
        if (! $profile) {
            $this->warn('No profile on this account — nothing to reset.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This DELETES all of this account\'s Titan data (meals, dream physique, progress photos, '
            .'coach memory, conversations, programs, biomarkers, cycle…) and re-runs onboarding. Continue?'
        )) {
            $this->line('Aborted — nothing changed.');

            return self::SUCCESS;
        }

        $deleted = [];
        DB::transaction(function () use ($profile, &$deleted) {
            foreach (self::RELATIONS as $rel) {
                if (! method_exists($profile, $rel)) {
                    continue;
                }
                $query = $profile->{$rel}();
                $count = (clone $query)->count();
                if ($count === 0) {
                    continue;
                }
                // Force-delete soft-deletables (e.g. knowledge pages) so re-onboarding starts truly clean.
                if (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
                    $query->withTrashed()->forceDelete();
                } else {
                    $query->delete();   // DB FK cascade handles nested rows (e.g. meal items)
                }
                $deleted[$rel] = $count;
            }

            // PhysiqueAnalysis is profile-scoped but not a declared relation.
            if (class_exists(PhysiqueAnalysis::class)) {
                $n = PhysiqueAnalysis::where('profile_id', $profile->id)->count();
                if ($n > 0) {
                    PhysiqueAnalysis::where('profile_id', $profile->id)->delete();
                    $deleted['physiqueAnalyses'] = $n;
                }
            }

            // Reset the profile to a fresh, pre-onboarding state. Clearing onboarded_at re-opens the
            // onboarding gate; clearing settings drops macros/intake/cycle. We leave primary_goal /
            // coach_tone as-is (they're NOT NULL and get overwritten when onboarding completes).
            $profile->forceFill([
                'onboarded_at' => null,
                'settings' => [],
            ])->save();
        });

        $this->newLine();
        if ($deleted === []) {
            $this->info('Profile reset to pre-onboarding. (No logged data to remove.)');
        } else {
            $this->info('Wiped:');
            foreach ($deleted as $rel => $n) {
                $this->line(sprintf('  · %-20s %d', $rel, $n));
            }
            $this->newLine();
            $this->info('Profile reset to pre-onboarding.');
        }
        $this->line("→ <info>{$user->email}</info> will go through onboarding on next login.");

        return self::SUCCESS;
    }
}
