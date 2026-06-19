<?php

namespace App\Console\Commands;

use App\Exceptions\AiException;
use App\Models\Profile;
use App\Services\Physique\LivingGoalService;
use Illuminate\Console\Command;

/**
 * Weekly heartbeat of the living goal-physique loop. For every profile that has an active
 * dream-physique goal AND a recent progress photo, render the next "one step closer" image
 * (calibrated to the last ~2 weeks of adherence) and refresh the "are you on track?" % to
 * goal comparison. This is what makes the rendered "you" advance on its own as the user stays
 * consistent -- the founding wedge, on a cadence.
 *
 *   php artisan physique:living-render                 # all eligible profiles
 *   php artisan physique:living-render --profile=1     # one profile
 *   php artisan physique:living-render --skip-compare  # render the step only, no vision compare
 *
 * AI/image errors are caught per-profile so one offline provider or one bad photo never aborts
 * the whole run. Schedule it weekly (the schedule line lives in routes/console.php -- not edited
 * here): Schedule::command('physique:living-render')->weeklyOn(1, '06:00');
 */
class LivingGoalRender extends Command
{
    protected $signature = 'physique:living-render
        {--profile= : Only process this profile id (defaults to all eligible profiles)}
        {--skip-compare : Render the progress step but skip the % to goal vision comparison}';

    protected $description = 'Weekly: advance each profile\'s living goal image one adherence-calibrated step and refresh % to goal.';

    public function handle(LivingGoalService $living): int
    {
        $profiles = $this->resolveProfiles();
        if ($profiles->isEmpty()) {
            $this->warn('No profiles to process.');

            return self::SUCCESS;
        }

        $rendered = 0;
        $compared = 0;
        $skipped = 0;

        foreach ($profiles as $profile) {
            $label = "#{$profile->id} ".($profile->display_name ?: 'profile');

            // Eligibility: must have an active goal image + a progress photo to morph.
            $hasGoal = $profile->physiqueGoals()->where('is_active', true)->whereNotNull('goal_image_path')->exists()
                || $profile->physiqueGoals()->whereNotNull('goal_image_path')->exists();
            $hasPhoto = $profile->progressPhotos()->whereNotNull('photo_path')->exists();

            if (! $hasGoal || ! $hasPhoto) {
                $this->line("  <fg=gray>-</> {$label} skipped (".(! $hasGoal ? 'no goal image' : 'no progress photo').')');
                $skipped++;

                continue;
            }

            // --- Render the calibrated step ---
            try {
                $step = $living->renderProgressStep($profile);
                if (($step['status'] ?? null) === 'rendered') {
                    $adh = (int) round(($step['adherence'] ?? 0) * 100);
                    $this->line("  <info>✓</info> {$label} stepped to {$step['step_pct']}% (adherence {$adh}%)");
                    $rendered++;
                } else {
                    $this->line("  <fg=gray>-</> {$label} no render ({$step['message']})");
                    $skipped++;
                }
            } catch (AiException $e) {
                $this->line("  <fg=yellow>…</> {$label} render unavailable ({$e->getMessage()})");
            }

            // --- Refresh the "are you on track?" comparison ---
            if (! $this->option('skip-compare')) {
                try {
                    $cmp = $living->compareToGoal($profile);
                    if (($cmp['status'] ?? null) === 'compared') {
                        $pct = $cmp['pct_to_goal'] !== null ? "{$cmp['pct_to_goal']}% to goal" : 'compared';
                        $this->line("      <info>✓</info> {$pct}");
                        $compared++;
                    }
                } catch (AiException $e) {
                    $this->line("      <fg=yellow>…</> comparison unavailable ({$e->getMessage()})");
                }
            }
        }

        $this->newLine();
        $this->info("Living goal loop: {$rendered} rendered, {$compared} compared, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,Profile> */
    private function resolveProfiles(): \Illuminate\Support\Collection
    {
        if ($id = $this->option('profile')) {
            return Profile::query()->whereKey($id)->get();
        }

        return Profile::query()->orderBy('id')->get();
    }
}
