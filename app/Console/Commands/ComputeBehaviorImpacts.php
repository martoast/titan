<?php

namespace App\Console\Commands;

use App\Models\BehaviorImpact;
use App\Models\BehaviorLog;
use App\Models\Profile;
use App\Services\Notifications\NotificationService;
use App\Support\BehaviorCorrelations;
use App\Support\Journal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly: recompute every journaling user's behavior→outcome correlations, and fire a one-time
 * "Discovery" push the first time a meaningful insight is confirmed ("we found something: alcohol
 * tends to cut your recovery"). This is the behavior-change flywheel.
 */
class ComputeBehaviorImpacts extends Command
{
    protected $signature = 'insights:behavior {--profile= : Only this profile id}';

    protected $description = 'Recompute behavior→recovery/sleep correlations and surface new discoveries.';

    public function handle(NotificationService $notifications): int
    {
        $found = 0;
        foreach ($this->profiles() as $profile) {
            try {
                BehaviorCorrelations::compute($profile);
                $found += $this->announceDiscoveries($profile, $notifications);
            } catch (\Throwable $e) {
                Log::warning('[insights] behavior impacts failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Behavior insights computed; {$found} new discoveries surfaced.");

        return self::SUCCESS;
    }

    /** Push + a chat note the first time each significant impact appears; stamp discovered_at. */
    private function announceDiscoveries(Profile $profile, NotificationService $notifications): int
    {
        $fresh = $profile->behaviorImpacts()
            ->where('significant', true)->whereNull('discovered_at')
            ->orderByDesc(\Illuminate\Support\Facades\DB::raw('abs(pct_change)'))
            ->get();
        if ($fresh->isEmpty()) {
            return 0;
        }

        // Mark them all discovered now (so we never re-notify), but only announce the single strongest.
        $top = $fresh->first();
        BehaviorImpact::whereIn('id', $fresh->pluck('id'))->update(['discovered_at' => now()]);

        $meta = BehaviorCorrelations::OUTCOMES[$top->outcome_key] ?? ['label' => $top->outcome_key, 'better' => 'higher'];
        $behavior = Journal::label($top->behavior_key);
        $pct = abs((int) round($top->pct_change * 100));
        $raised = $top->pct_change > 0;
        $good = $meta['better'] === 'higher' ? $raised : ! $raised;
        $verb = $good ? 'lifts' : 'lowers';

        \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
        $title = __('🔍 New discovery');
        $body = "{$behavior} tends to {$verb} your {$meta['label']} by about {$pct}% — based on {$top->n_with} days you logged it.";

        $notifications->notify($profile, $title, $body, '/coach', 'insight');

        $convo = $profile->conversations()->firstOrCreate(['title' => 'Daily Briefings']);
        $convo->messages()->create([
            'role' => 'assistant',
            'content' => "🔍 **I found a pattern in your data.** {$body}\n\nAsk me \"what affects my recovery?\" for the full picture.",
        ]);

        return $fresh->count();
    }

    /** @return iterable<Profile> profiles with enough journaling to bother. */
    private function profiles(): iterable
    {
        if ($id = $this->option('profile')) {
            return Profile::where('id', $id)->get();
        }

        // Only users who've journaled on ≥10 distinct days (cheap filter; the engine gates the rest).
        $ids = BehaviorLog::query()
            ->selectRaw('profile_id, count(distinct logged_on) as days')
            ->groupBy('profile_id')->havingRaw('count(distinct logged_on) >= 10')
            ->pluck('profile_id');

        return Profile::whereIn('id', $ids)->get();
    }
}
