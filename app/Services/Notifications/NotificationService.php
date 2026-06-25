<?php

namespace App\Services\Notifications;

use App\Mail\CoachMessage;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The single entry point for telling a profile something. One call:
 *   1. persists an in-app Notification (always -- drives the header bell),
 *   2. fires a Web Push to all that profile's browser subscriptions (best-effort), and
 *   3. (when $email is set) sends the same message as a Titan-branded email.
 *
 * Push delivery is wrapped in WebPushService and can never break the caller, so
 * commands/jobs can notify() freely without try/catch of their own. The native iOS app can't
 * receive real APNs push without a paid Apple account, so proactive reactions (the workout
 * celebration, the morning sleep summary) pass email: true to reach the user reliably.
 */
class NotificationService
{
    public function __construct(protected WebPushService $push) {}

    /**
     * Create an in-app notification for the profile and push it to their devices.
     *
     * @param  string  $type  coarse category: 'briefing' | 'nudge' | 'recovery' | 'general' …
     * @param  bool  $email  also deliver as an email (the reliable channel for native-app users)
     */
    public function notify(
        Profile $profile,
        string $title,
        string $body,
        ?string $url = null,
        string $type = 'general',
        bool $email = false,
    ): Notification {
        $notification = Notification::create([
            'profile_id' => $profile->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);

        // Best-effort push to every endpoint this profile has registered. Never throws.
        $subscriptions = PushSubscription::query()
            ->where('profile_id', $profile->id)
            ->get();

        $this->push->sendToSubscriptions($subscriptions, [
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);

        // Best-effort email -- the channel that actually reaches native-app users (no APNs without a
        // paid Apple account). Never let a bad address or SMTP hiccup break the calling job.
        if ($email && ($address = $profile->user?->email)) {
            try {
                Mail::to($address)->send(new CoachMessage($profile, $title, $body, $url));
            } catch (\Throwable $e) {
                Log::warning('notify() email failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        return $notification;
    }
}
