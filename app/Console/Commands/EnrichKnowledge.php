<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Brain\KnowledgeIngestor;
use App\Support\KnowledgeEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * COACH v2 · Phase 3 — the weekly LIVING-KNOWLEDGE pass. For each active profile, synthesize the week's
 * DATA into durable wiki knowledge (patterns / milestones / shifts) and file it through KnowledgeIngestor
 * (append-safe, slug-deduped, re-embedded). Deduped once per calendar week so a re-run never double-files.
 *
 *   php artisan coach:enrich-knowledge
 *   php artisan coach:enrich-knowledge --profile=1
 */
class EnrichKnowledge extends Command
{
    protected $signature = 'coach:enrich-knowledge {--profile= : Only this profile id}';

    protected $description = 'Synthesize each active profile\'s weekly data into durable Brain knowledge.';

    public function handle(KnowledgeEnricher $enricher, KnowledgeIngestor $ingestor): int
    {
        $filed = 0;
        foreach ($this->resolveProfiles() as $profile) {
            try {
                $user = $profile->user;
                if ($user === null) {
                    continue;   // the ingestor stamps updated_by_user_id — needs a user
                }

                $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
                $weekKey = 'enrich:'.Carbon::now($tz)->startOfWeek()->toDateString();
                if (data_get($profile->settings, 'nudge_sent.enrich') === $weekKey) {
                    continue;   // already synthesized this week
                }

                $knowledge = $enricher->synthesize($profile);
                if ($knowledge === null) {
                    continue;   // unconfigured, too thin, or nothing durable this week
                }

                $ingestor->ingest($profile, $user, $knowledge);
                $filed++;

                // Stamp the week so the next run is a no-op until a new week starts.
                $settings = (array) $profile->settings;
                $settings['nudge_sent'] = array_merge((array) ($settings['nudge_sent'] ?? []), ['enrich' => $weekKey]);
                $profile->forceFill(['settings' => $settings])->save();
            } catch (\Throwable $e) {
                Log::warning('[coach] knowledge enrichment failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Enriched knowledge for {$filed} profile(s).");

        return self::SUCCESS;
    }

    private function resolveProfiles()
    {
        if ($id = $this->option('profile')) {
            return Profile::with('user')->where('id', $id)->get();
        }

        return Profile::with('user')->orderBy('id')->get();
    }
}
