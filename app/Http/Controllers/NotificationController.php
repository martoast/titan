<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification center + Web Push subscription management for the current
 * profile. All routes run inside the auth group (see routes/web.php → routes/titan/
 * notifications.php). Everything is scoped to the authed user's profile by profile_id;
 * we never trust a client-supplied profile id.
 */
class NotificationController extends Controller
{
    /** Full notification center page (mobile-first, dark). */
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $notifications = Notification::query()
            ->where('profile_id', $profile->id)
            ->latest()
            ->limit(100)
            ->get();

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $notifications->whereNull('read_at')->count(),
        ]);
    }

    /** JSON feed for the header bell dropdown (recent + unread count). */
    public function feed(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $recent = Notification::query()
            ->where('profile_id', $profile->id)
            ->latest()
            ->limit(15)
            ->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']);

        return response()->json([
            'unread' => Notification::query()
                ->where('profile_id', $profile->id)
                ->whereNull('read_at')
                ->count(),
            'notifications' => $recent->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'read' => $n->read_at !== null,
                'ago' => $n->created_at?->diffForHumans(),
            ]),
        ]);
    }

    /** Mark one notification read. */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $this->authorizeNotification($request, $notification);

        $notification->forceFill(['read_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** Mark every unread notification read. */
    public function markAllRead(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        Notification::query()
            ->where('profile_id', $profile->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Notification settings: coaching intensity + per-reminder toggles + a test button. */
    public function settings(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        return view('notifications.settings', [
            'profile' => $profile,
            'summary' => \App\Support\Reminders::summary($profile),
            'intensities' => \App\Support\Reminders::INTENSITIES,
            'types' => \App\Support\Reminders::TYPES,
            'hasPush' => PushSubscription::where('profile_id', $profile->id)->exists(),
        ]);
    }

    /** Save the coaching intensity (one section) or the per-type overrides (the other). */
    public function updateSettings(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate([
            'section' => ['required', 'in:intensity,types'],
            'intensity' => ['nullable', 'in:minimal,balanced,intense'],
            'types' => ['nullable', 'array'],
        ]);

        if ($data['section'] === 'intensity' && ! empty($data['intensity'])) {
            \App\Support\Reminders::setIntensity($profile, $data['intensity']);

            return back()->with('status', 'Coaching intensity updated.');
        }

        if ($data['section'] === 'types') {
            $checked = array_keys($data['types'] ?? []);
            foreach (array_keys(\App\Support\Reminders::TYPES) as $type) {
                \App\Support\Reminders::setType($profile, $type, in_array($type, $checked, true));
            }

            return back()->with('status', 'Reminder preferences saved.');
        }

        return back();
    }

    /** Fire a test push so the user can confirm notifications reach their device. */
    public function test(Request $request, \App\Services\Notifications\NotificationService $notifications): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        if (! PushSubscription::where('profile_id', $profile->id)->exists()) {
            return response()->json(['ok' => false, 'reason' => 'no_subscription', 'message' => 'Turn on notifications first, then send a test.'], 200);
        }

        $notifications->notify(
            $profile,
            '🔔 Titan test',
            "Push is working. I'll nudge you to eat, train, move and sleep — at your chosen intensity.",
            '/coach',
            'general',
        );

        return response()->json(['ok' => true]);
    }

    /** Store (upsert) a browser PushManager subscription for this profile. */
    public function subscribe(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'profile_id' => $profile->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
            ],
        );

        return response()->json(['ok' => true, 'id' => $subscription->id]);
    }

    /** Remove a browser subscription (on unsubscribe / permission revoke). */
    public function unsubscribe(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::query()
            ->where('profile_id', $profile->id)
            ->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Guard: a notification must belong to the current profile. */
    private function authorizeNotification(Request $request, Notification $notification): void
    {
        $profile = $request->user()->ensureProfile();

        abort_unless($notification->profile_id === $profile->id, 404);
    }
}
