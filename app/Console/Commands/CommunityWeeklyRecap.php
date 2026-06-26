<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Community\WeeklyRecap;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/**
 * Sunday evening: push each opted-in athlete their "week vs the group" recap — rank, effort, and
 * who led — the re-engagement loop. Skips anyone who didn't move this week (no spam).
 */
class CommunityWeeklyRecap extends Command
{
    protected $signature = 'community:weekly-recap';

    protected $description = 'Push each opted-in athlete their weekly community recap';

    public function handle(WeeklyRecap $recaps, NotificationService $notify): int
    {
        $sent = 0;

        Profile::where('community_enabled', true)->chunkById(100, function ($profiles) use ($recaps, $notify, &$sent) {
            foreach ($profiles as $p) {
                $r = $recaps->forProfile($p);
                if (($r['your_activities'] ?? 0) < 1) {
                    continue;   // nothing to recap
                }

                $rank = $r['your_rank'];
                $top = $r['top_performer'];
                $lead = $top && ! $top['is_you'] ? " {$top['name']} led the group." : '';
                $rankLine = $rank ? "You're #{$rank} this week" : 'Your week';

                $notify->notify(
                    $p,
                    'Your week on Titan',
                    "{$rankLine} — {$r['your_effort']} effort, {$r['your_distance_km']} km.{$lead}",
                    '/community',
                    'community',
                );
                $sent++;
            }
        });

        $this->info("Weekly recap sent to {$sent} athlete(s).");

        return self::SUCCESS;
    }
}
