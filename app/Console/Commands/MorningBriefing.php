<?php

namespace App\Console\Commands;

use App\Exceptions\AiException;
use App\Mail\CoachBriefing;
use App\Models\Profile;
use App\Services\Coach\CoachBriefingService;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Proactive coach -- MORNING briefing.
 *
 *   php artisan coach:morning-briefing                 # every opted-in profile
 *   php artisan coach:morning-briefing --profile=1     # just one
 *   php artisan coach:morning-briefing --no-mail       # generate + store, skip email
 *
 * For each profile we generate + persist the briefing (it lands in their "Daily
 * Briefings" coach thread and shows on the coach page) and email it via Mailgun. The
 * per-profile opt-in lives at profile.settings['briefings'] and DEFAULTS ON; a profile
 * is skipped only if it's explicitly false. AI failures and mail failures are caught
 * per-profile so one bad row never aborts the run.
 *
 * The orchestrator wires the schedule -- see scheduleLines() for the lines to add to
 * routes/console.php (this command does NOT edit that file itself).
 */
class MorningBriefing extends Command
{
    protected $signature = 'coach:morning-briefing
        {--profile= : Only run for this profile id}
        {--no-mail : Generate + store the briefing but do not send email}';

    protected $description = 'Generate, store and email each profile a proactive morning coaching briefing.';

    public function handle(CoachBriefingService $briefings, NotificationService $notifications): int
    {
        $profiles = $this->resolveProfiles();
        if ($profiles->isEmpty()) {
            $this->warn('No profiles to brief.');

            return self::SUCCESS;
        }

        $sent = 0;
        $generated = 0;
        $skipped = 0;

        foreach ($profiles as $profile) {
            if (! self::briefingsEnabled($profile)) {
                $this->line("  · profile #{$profile->id} -- briefings off, skipped");
                $skipped++;

                continue;
            }

            try {
                $message = $briefings->morningBriefing($profile);
                $generated++;
            } catch (AiException $e) {
                Log::warning('[coach] morning briefing AI failure', ['profile' => $profile->id, 'error' => $e->getMessage()]);
                $this->line("  <fg=red>✗</> profile #{$profile->id} -- coach offline ({$e->getMessage()})");

                continue;
            }

            $this->line("  <info>✓</info> profile #{$profile->id} -- ".\Illuminate\Support\Str::limit($message, 70));

            // Surface the briefing as an in-app notification + Web Push (best-effort).
            $notifications->notify(
                $profile,
                'Your morning briefing',
                \Illuminate\Support\Str::limit($message, 140),
                '/coach',
                'briefing',
            );

            if ($this->option('no-mail')) {
                continue;
            }

            if ($this->mail($profile, $message)) {
                $sent++;
            }
        }

        $this->newLine();
        $this->info("Morning briefings: {$generated} generated, {$sent} emailed, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** Email the briefing; failures are logged + reported, never fatal. */
    private function mail(Profile $profile, string $message): bool
    {
        $email = $profile->user?->email;
        if (! $email) {
            $this->line("  <fg=yellow>…</> profile #{$profile->id} -- no email on file, not sent");

            return false;
        }

        try {
            Mail::to($email)->send(new CoachBriefing($profile, $message, 'morning'));

            return true;
        } catch (\Throwable $e) {
            Log::warning('[coach] morning briefing email failure', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            $this->line("  <fg=yellow>…</> profile #{$profile->id} -- email not delivered ({$e->getMessage()})");

            return false;
        }
    }

    /** @return \Illuminate\Support\Collection<int,Profile> */
    private function resolveProfiles(): \Illuminate\Support\Collection
    {
        $query = Profile::query()->with('user')->orderBy('id');
        if ($id = $this->option('profile')) {
            $query->whereKey($id);
        }

        return $query->get();
    }

    /** On per the profile's coaching intensity (and the legacy settings['briefings'] flag). */
    public static function briefingsEnabled(Profile $profile): bool
    {
        return \App\Support\Reminders::enabled($profile, 'briefing');
    }

    /**
     * Schedule lines for the orchestrator to add to routes/console.php. We do NOT edit
     * that file ourselves (it's shared / owned by the orchestrator).
     *
     * @return array<int,string>
     */
    public static function scheduleLines(): array
    {
        return [
            "Schedule::command('coach:morning-briefing')->dailyAt('07:00')->timezone(config('app.timezone'));",
            "Schedule::command('coach:evening-nudge')->dailyAt('18:30')->timezone(config('app.timezone'));",
        ];
    }
}
