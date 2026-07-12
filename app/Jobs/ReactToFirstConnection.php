<?php

namespace App\Jobs;

use App\Models\WearableConnection;
use App\Services\Notifications\NotificationService;
use App\Support\DeviceStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The "your band is LIVE" moment -- the payoff of pairing. The instant a freshly-paired
 * band streams its very first data, the coach reacts with a one-time celebration: a push +
 * a note in the chat confirming the connection (and the first vital, if one's already
 * processed). Distinct from ReactToDeviceSync's recurring morning recovery greeting -- this
 * fires exactly once per band, the moment it comes alive, closing the pairing arc.
 */
class ReactToFirstConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $connectionId) {}

    public function handle(NotificationService $notifications): void
    {
        $conn = WearableConnection::with('profile')->find($this->connectionId);
        if (! $conn || ! $conn->profile) {
            return;
        }
        $profile = $conn->profile;

        // Fire exactly once per band -- even if two first batches race in.
        $announced = (array) data_get($profile->settings, 'bands_announced', []);
        if (in_array($conn->id, $announced, true)) {
            return;
        }

        $source = DeviceStatus::sourceLabel($conn);

        // Surface whatever's already real: a just-processed vital, else the battery, else
        // an honest "I'm reading it now" -- the raw windows are still being crunched.
        $vital = $this->firstVital($profile->id);
        if ($vital) {
            $detail = "First read: {$vital}. Signal looks clean.";
        } elseif ($conn->battery_pct !== null) {
            $detail = "Battery's at {$conn->battery_pct}% -- I'm reading your first signals now; your vitals will land in a moment.";
        } else {
            $detail = "I'm reading your first signals now -- your vitals will land on the dashboard in a moment.";
        }

        $body = "Your {$source} just came online and started streaming. {$detail}";

        \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
        $notifications->notify($profile, __('🎉 Your :source is live!', ['source' => $source]), $body, '/coach', 'band_live');

        $convo = $profile->conversations()->firstOrCreate(['title' => 'Daily Briefings']);
        $convo->messages()->create([
            'role' => 'assistant',
            'content' => "🎉 **Your {$source} is live!**\n\n{$body}\n\nFrom here I'll watch your recovery, sleep and strain automatically -- no need to log them by hand. Ask me anything once your first numbers settle.",
        ]);

        $settings = $profile->settings ?? [];
        $announced[] = $conn->id;
        $settings['bands_announced'] = array_values(array_unique($announced));
        $profile->update(['settings' => $settings]);
    }

    /** A freshly-processed first vital to celebrate with, if one already landed. */
    private function firstVital(int $profileId): ?string
    {
        if (! class_exists(\App\Models\RecoveryLog::class)) {
            return null;
        }
        $r = \App\Models\RecoveryLog::where('profile_id', $profileId)
            ->whereNotNull('resting_hr')
            ->latest('logged_at')->first();
        if (! $r) {
            return null;
        }
        $bits = [];
        if ($r->resting_hr) {
            $bits[] = "resting HR {$r->resting_hr}";
        }
        if ($r->hrv_ms) {
            $bits[] = "HRV {$r->hrv_ms}ms";
        }

        return $bits ? implode(', ', $bits) : null;
    }
}
