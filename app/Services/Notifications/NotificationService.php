<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\Profile;
use App\Models\PushSubscription;

/**
 * The single entry point for telling a profile something. One call:
 *   1. persists an in-app Notification (always — drives the header bell), and
 *   2. fires a Web Push to all that profile's browser subscriptions (best-effort).
 *
 * Push delivery is wrapped in WebPushService and can never break the caller, so
 * commands/jobs can notify() freely without try/catch of their own.
 */
class NotificationService
{
    public function __construct(protected WebPushService $push) {}

    /**
     * Create an in-app notification for the profile and push it to their devices.
     *
     * @param  string  $type  coarse category: 'briefing' | 'nudge' | 'recovery' | 'general' …
     */
    public function notify(
        Profile $profile,
        string $title,
        string $body,
        ?string $url = null,
        string $type = 'general',
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

        return $notification;
    }
}
