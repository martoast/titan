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
use Illuminate\Support\Str;

/**
 * Proactive coach -- EVENING nudge (the research report's "behavior-triggered push,
 * evening 5-7pm" -- up to 3x open rates vs generic blasts).
 *
 *   php artisan coach:evening-nudge
 *   php artisan coach:evening-nudge --profile=1
 *   php artisan coach:evening-nudge --no-mail
 *
 * Same contract as coach:morning-briefing: respects profile.settings['briefings']
 * (defaults ON), stores the nudge in the "Daily Briefings" coach thread, emails via
 * Mailgun, and isolates AI + mail failures per profile.
 */
class EveningNudge extends Command
{
    protected $signature = 'coach:evening-nudge
        {--profile= : Only run for this profile id}
        {--no-mail : Generate + store the nudge but do not send email}';

    protected $description = 'Generate, store and email each profile a proactive evening coaching nudge.';

    public function handle(CoachBriefingService $briefings, NotificationService $notifications): int
    {
        $profiles = $this->resolveProfiles();
        if ($profiles->isEmpty()) {
            $this->warn('No profiles to nudge.');

            return self::SUCCESS;
        }

        $sent = 0;
        $generated = 0;
        $skipped = 0;

        foreach ($profiles as $profile) {
            if (! MorningBriefing::briefingsEnabled($profile)) {
                $this->line("  · profile #{$profile->id} -- briefings off, skipped");
                $skipped++;

                continue;
            }

            try {
                $message = $briefings->eveningNudge($profile);
                $generated++;
            } catch (AiException $e) {
                Log::warning('[coach] evening nudge AI failure', ['profile' => $profile->id, 'error' => $e->getMessage()]);
                $this->line("  <fg=red>✗</> profile #{$profile->id} -- coach offline ({$e->getMessage()})");

                continue;
            }

            $this->line("  <info>✓</info> profile #{$profile->id} -- ".Str::limit($message, 70));

            // Surface the nudge as an in-app notification + Web Push (best-effort). The
            // research report's evening "behavior-triggered push" lands here.
            $notifications->notify(
                $profile,
                'Evening check-in',
                Str::limit($message, 140),
                '/coach',
                'nudge',
            );

            if ($this->option('no-mail')) {
                continue;
            }

            if ($this->mail($profile, $message)) {
                $sent++;
            }
        }

        $this->newLine();
        $this->info("Evening nudges: {$generated} generated, {$sent} emailed, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** Email the nudge; failures are logged + reported, never fatal. */
    private function mail(Profile $profile, string $message): bool
    {
        $email = $profile->user?->email;
        if (! $email) {
            $this->line("  <fg=yellow>…</> profile #{$profile->id} -- no email on file, not sent");

            return false;
        }

        try {
            Mail::to($email)->send(new CoachBriefing($profile, $message, 'evening'));

            return true;
        } catch (\Throwable $e) {
            Log::warning('[coach] evening nudge email failure', ['profile' => $profile->id, 'error' => $e->getMessage()]);
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

    /**
     * Schedule lines for the orchestrator (we do NOT edit routes/console.php).
     *
     * @return array<int,string>
     */
    public static function scheduleLines(): array
    {
        return MorningBriefing::scheduleLines();
    }
}
