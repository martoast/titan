<?php

namespace App\Jobs;

use App\Models\Profile;
use App\Models\SleepLog;
use App\Support\NightTruncation;
use App\Support\SleepCoach;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A band reboot was just observed — go back and correct the night it cut short.
 *
 * The seal applies {@see NightTruncation} itself when the reboot is already known
 * (SealNightJob reads `wearable_connections.last_reboot_at`), which covers a band that died, was charged
 * and reconnected all before the night sealed. But the ordering is usually the other way round: Tester B's
 * band died at 05:53, her night sealed at 10:00, and the band did not reconnect — and so did not reveal
 * the power loss — until 00:19 the NEXT morning, fourteen hours after the row was written. Without this
 * job that night stays a believed 4.8h forever.
 *
 * Deliberately narrow: it considers only the single most recent sleep session before the reboot, because
 * that is the only one the power loss can have interrupted. Everything else is decided by
 * NightTruncation, so the two entry points cannot drift apart.
 */
class FlagTruncatedNightJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $profileId,
    ) {}

    public function handle(): void
    {
        $profile = Profile::find($this->profileId);
        if (! $profile) {
            return;
        }

        // Read the reboot from the CONNECTION rather than taking it as an argument. The caller already
        // wrote it there, and the seal-time path reads the same column — so neither entry point can act on
        // a power loss that is not actually on record, and the two can never disagree about when it was.
        $rebootAt = $profile->wearableConnections()
            ->whereNotNull('last_reboot_at')
            ->orderByDesc('last_reboot_at')
            ->value('last_reboot_at');

        if ($rebootAt === null) {
            return;
        }
        $rebootAt = CarbonImmutable::parse($rebootAt);

        $tz = $profile->effectiveTimezone();

        // The last sleep session the band recorded before it lost power. A nap (or a later night) sitting
        // between the night and the reboot means the band went on recording afterwards — so the night was
        // not what the power loss interrupted, and nothing here applies to it.
        $candidate = SleepLog::query()
            ->where('profile_id', $profile->id)
            ->whereNotNull('wake_time')
            ->where('slept_at', '>=', $rebootAt->setTimezone($tz)->subDays(3)->toDateString())
            ->orderByDesc('slept_at')->orderByDesc('id')
            ->get()
            ->map(fn (SleepLog $l) => [$l, NightTruncation::sessionEndOf($l, $tz)])
            ->filter(fn (array $p) => $p[1] !== null && $p[1]->lessThan($rebootAt))
            ->sortByDesc(fn (array $p) => $p[1]->getTimestamp())
            ->first();

        if ($candidate === null) {
            return;
        }

        [$night, $sessionEnd] = $candidate;

        if ($night->truncated) {
            return; // already recorded — a still-un-synced band keeps re-reporting the same power loss
        }

        $needH = class_exists(SleepCoach::class) ? SleepCoach::baselineFor($profile) : null;

        if (! NightTruncation::applies($night, $rebootAt, $sessionEnd, $needH)) {
            return;
        }

        $night->forceFill(['truncated' => true, 'low_confidence' => true])->save();

        Log::info('[Sleep] night marked truncated — the band lost power before it ended', [
            'profile_id' => $profile->id,
            'sleep_log_id' => $night->id,
            'slept_at' => $night->slept_at?->toDateString(),
            'stored_duration_min' => $night->duration_min,
            'session_end_local' => $sessionEnd->toIso8601String(),
            'reboot_at' => $rebootAt->toIso8601String(),
        ]);
    }
}
