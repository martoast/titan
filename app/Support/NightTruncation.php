<?php

namespace App\Support;

use App\Models\SleepLog;
use Carbon\CarbonImmutable;

/**
 * Decides whether a night ENDED because the user woke, or because the band stopped recording.
 *
 * The seal cannot tell those apart on its own — both look like "windows stop arriving" — so a dead
 * battery seals as a real short night, counts toward debt and earns the user a coaching line about
 * going to bed earlier. Tester B's 2026-08-16: the band died at 05:53, the night sealed as a believed 4.8h,
 * and that single artifact was her entire sleep debt (0.2h → 3.3h "moderate").
 *
 * ## Why the evidence is a REBOOT and nothing else
 *
 * The obvious signal — "the band never contacted us again after the session ended" — was measured against
 * real nights on this box and is worthless. Post-wake contact gaps on ordinary, complete nights ran from
 * +1 minute to **+6.5 days** (Alex routinely goes 100–390 min; Tester B's 2026-07-26 went 9,328 min). Using it
 * would have marked most of Alex's history truncated.
 *
 * A **reboot** is precise: a band only reboots on power loss or a reflash, and since the ingest's clock
 * guard we can see one — it comes back with an un-synced clock
 * ({@see \App\Services\Wearables\DeviceIngestionService::batchClockOffsetSec}). So the question
 * "did this night end in a power loss?" has an actual answer.
 *
 * ## The four conditions
 *
 * A reboot alone is not enough — a band taken off at a normal hour and left to die on a nightstand also
 * reboots when it is next charged, and that night was complete. So all of:
 *
 *   1. **No confirmed wake marker.** If the user tapped "I'm awake" we have her own word for the wake
 *      time and we trust it over any inference.
 *   2. **The session ended inside core sleep hours.** A session ending at 09:30 is a wake; one ending at
 *      05:53 is not a time people usually stop wearing a band.
 *   3. **The reboot follows this night's last sample**, with the band producing nothing in between — i.e.
 *      this session's end really is the last thing the band recorded before it lost power.
 *   4. **The night is short enough to matter.** If the battery died twenty minutes before she woke, the
 *      reading is fine and throwing it away would lose good data for nothing. Only a night that falls
 *      more than SHORTFALL_MIN under her baseline need can produce the false debt this exists to stop.
 *
 * Getting it wrong in one direction costs a night of silence; in the other it costs a fabricated number
 * with advice attached. The conditions are tuned for that asymmetry, but not so loose that an ordinary
 * night is ever discarded.
 */
final class NightTruncation
{
    /** Mirrors SealNightJob's core-sleep window — the hours a session ending is NOT an ordinary wake. */
    public const CORE_SLEEP_START_HR = 22;

    public const CORE_SLEEP_END_HR = 9;

    /** How far under the baseline need a night must fall before truncating it changes anything. */
    public const SHORTFALL_MIN = 60;

    /** A seal whose `updated_via` starts with this came from the user's own "I'm awake" marker. */
    private const CONFIRMED_PREFIX = 'biosignal:sealed-session';

    /**
     * Should this night be marked truncated, given the moment the band was seen to reboot?
     *
     * @param  SleepLog  $night  the sealed row (naps are never truncated — they are short by definition)
     * @param  CarbonImmutable|null  $rebootAt  when a power-loss reboot was observed, or null if none
     * @param  CarbonImmutable|null  $sessionEnd  the session's last sample, in the user's local zone
     * @param  float|null  $needH  the user's baseline need in hours (null → fall back to NEED_FALLBACK_H)
     */
    public static function applies(SleepLog $night, ?CarbonImmutable $rebootAt, ?CarbonImmutable $sessionEnd, ?float $needH): bool
    {
        if ($rebootAt === null || $sessionEnd === null || $night->is_nap) {
            return false;
        }

        // 1. The user told us when she woke — her word beats our inference.
        if (str_starts_with((string) $night->updated_via, self::CONFIRMED_PREFIX)) {
            return false;
        }

        // 3. The reboot has to come AFTER the night's last sample. A reboot that predates the night says
        //    nothing about how the night ended (it is why the band was running at all).
        if (! $rebootAt->greaterThan($sessionEnd)) {
            return false;
        }

        // 2. Stopping at 09:30 is a wake; stopping at 05:53 is not.
        if (! self::insideCoreSleepHours($sessionEnd)) {
            return false;
        }

        // 4. A night that is already long enough loses nothing by being believed.
        $need = $needH ?? 8.0;
        $duration = (int) ($night->duration_min ?? 0);

        return $duration > 0 && $duration < ($need * 60) - self::SHORTFALL_MIN;
    }

    /** Does this local wall-clock fall in the overnight band where a session ending is not a wake? */
    public static function insideCoreSleepHours(CarbonImmutable $localTime): bool
    {
        $h = (int) $localTime->format('G');

        // The window wraps midnight, so it is a union, not a range.
        return $h >= self::CORE_SLEEP_START_HR || $h < self::CORE_SLEEP_END_HR;
    }

    /**
     * Reconstruct a sealed night's last sample as a local wall-clock. `slept_at` is the WAKE date and
     * `wake_time` a TIME column, so the two compose directly — no timezone conversion, because both are
     * already stored in the user's local frame (see SEAL_ARCHITECTURE on local-not-UTC timestamps).
     */
    public static function sessionEndOf(SleepLog $night, string $tz): ?CarbonImmutable
    {
        if (! $night->slept_at || ! $night->wake_time) {
            return null;
        }

        return CarbonImmutable::parse(
            $night->slept_at->toDateString().' '.CarbonImmutable::parse((string) $night->wake_time)->format('H:i:s'),
            $tz,
        );
    }
}
