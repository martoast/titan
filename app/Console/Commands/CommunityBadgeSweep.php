<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Community\AchievementEngine;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/**
 * Nightly: re-evaluate badges so time-window milestones (100km month, weekly streak…) still land
 * even on a day nothing sealed. Pushes a celebration for anything newly earned.
 */
class CommunityBadgeSweep extends Command
{
    protected $signature = 'community:badge-sweep';

    protected $description = 'Re-evaluate community achievements and notify newly-earned badges';

    public function handle(AchievementEngine $engine, NotificationService $notify): int
    {
        $awarded = 0;

        Profile::where('community_enabled', true)->chunkById(100, function ($profiles) use ($engine, $notify, &$awarded) {
            foreach ($profiles as $p) {
                foreach ($engine->evaluate($p) as $key) {
                    [$title] = AchievementEngine::CATALOG[$key] ?? ['New badge'];
                    \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($p->primary_language));
                    $notify->notify($p, __('Badge earned: :title', ['title' => $title]), __('You just unlocked a new achievement on Titan.'), '/community', 'community');
                    $awarded++;
                }
            }
        });

        $this->info("Awarded {$awarded} new badge(s).");

        return self::SUCCESS;
    }
}
