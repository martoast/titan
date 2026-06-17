<?php

namespace App\Http\Controllers\Coach;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Coach\CoachBriefingService;
use App\Services\Coach\CoachService;
use App\Services\Coach\ScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The AI coach chat. Renders the chat page (continuing the latest conversation),
 * starts new conversations, and handles message sends. The send endpoint persists the
 * user message, runs CoachService, stores + returns the assistant reply. If the AI is
 * offline it degrades gracefully — the page still renders, and a send returns a
 * friendly "coach is offline" message instead of crashing.
 */
class CoachController extends Controller
{
    public function __construct(
        protected CoachService $coach,
        protected CoachBriefingService $briefings,
        protected ScanService $scans,
    ) {}

    /** The chat page — opens the requested conversation, else the most recent, else a fresh one. */
    public function index(Request $request): View
    {
        $profile = $request->user()->ensureProfile();

        $conversation = null;
        if ($request->filled('c')) {
            $conversation = $profile->conversations()->whereKey($request->integer('c'))->first();
        }
        // Default to the latest CHAT thread, not the "Daily Briefings" thread — the
        // briefing already has its own card up top, so chats shouldn't land in it.
        $conversation ??= $profile->conversations()
            ->where(fn ($q) => $q->whereNull('title')->orWhere('title', '!=', 'Daily Briefings'))
            ->latest('id')->first();

        // Briefings thread is shown via the card, not the conversation switcher.
        $conversations = $profile->conversations()
            ->where(fn ($q) => $q->whereNull('title')->orWhere('title', '!=', 'Daily Briefings'))
            ->latest('id')->get();

        return view('coach.index', [
            'profile' => $profile,
            'conversation' => $conversation,
            'conversations' => $conversations,
            'messages' => $conversation
                ? $conversation->messages()->whereIn('role', ['user', 'assistant'])->orderBy('id')->get()
                : collect(),
            'starters' => CoachService::STARTERS,
            'aiOffline' => ! app(\App\Services\Ai\AiService::class)->configured(),
            'latestBriefing' => $this->briefings->latestBriefing($profile),
        ]);
    }

    /**
     * Regenerate today's briefing on demand (the "Today's briefing" card button). Picks
     * morning vs evening by local time, stores it in the Daily Briefings thread, and
     * redirects back to the coach. Never 500s — AI failure shows a friendly status.
     */
    public function briefing(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();

        try {
            $hour = (int) now(config('app.timezone'))->format('G');
            $hour >= 15
                ? $this->briefings->eveningNudge($profile)
                : $this->briefings->morningBriefing($profile);
        } catch (AiException $e) {
            Log::warning('[Coach] briefing regenerate failed', ['error' => $e->getMessage()]);

            return redirect('/coach')->with('status', 'Your coach is offline right now — try regenerating your briefing in a moment.');
        }

        return redirect('/coach')->with('status', 'Fresh briefing ready.');
    }

    /** Start a fresh conversation and open it. */
    public function store(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        $conversation = $profile->conversations()->create();

        return redirect('/coach?c='.$conversation->id);
    }

    /**
     * Send a message in a conversation. Creates the conversation on the fly if needed.
     * Returns JSON (for the Alpine fetch UX) with the assistant reply, or redirects
     * back for the no-JS form fallback. Never 500s on AI failure.
     */
    public function send(Request $request, ?Conversation $conversation = null): JsonResponse|RedirectResponse
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        // Resolve (and authorize) the target conversation, creating one if absent.
        if (! $conversation || $conversation->profile_id !== $profile->id) {
            $conversation = $profile->conversations()->create();
        }

        $wantsJson = $request->expectsJson() || $request->boolean('ajax');

        try {
            $reply = $this->coach->reply($conversation, $profile, $data['message']);
        } catch (AiException $e) {
            Log::warning('[Coach] AI unavailable', ['error' => $e->getMessage()]);

            $friendly = 'Your coach is offline right now (the AI service is unavailable). Your message was saved — try again in a moment.';

            if ($wantsJson) {
                return response()->json([
                    'ok' => false,
                    'offline' => true,
                    'conversation_id' => $conversation->id,
                    'reply' => $friendly,
                ], 200);
            }

            return redirect('/coach?c='.$conversation->id)->with('status', $friendly);
        }

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'conversation_id' => $conversation->id,
                'reply' => $reply->content,
            ]);
        }

        return redirect('/coach?c='.$conversation->id);
    }

    /**
     * Stream a reply over Server-Sent Events: live tokens as the coach writes, plus a
     * status line for each data tool it reaches for, and tappable follow-ups at the end.
     * Degrades to an `error` event on AI failure (the message is still saved). The
     * front-end falls back to the JSON send() endpoint if streaming can't start.
     */
    public function stream(Request $request, ?Conversation $conversation = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        if (! $conversation || $conversation->profile_id !== $profile->id) {
            $conversation = $profile->conversations()->create();
        }

        // Release the session lock so this long-lived request doesn't block the user's
        // other tabs/requests while the stream is open.
        $request->session()->save();

        return response()->stream(function () use ($conversation, $profile, $data) {
            $emit = function (string $event, array $payload): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($payload)."\n\n";
                // Push the frame out now without ending any buffer (don't disturb a wrapping
                // output buffer, e.g. the test harness's capture).
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $emit('meta', ['conversation_id' => $conversation->id]);

            try {
                $reply = $this->coach->replyStreaming(
                    $conversation,
                    $profile,
                    $data['message'],
                    onDelta: fn (string $token) => $emit('delta', ['text' => $token]),
                    onTool: fn (string $name, string $label) => $emit('tool', ['name' => $name, 'label' => $label]),
                );

                $emit('done', [
                    'conversation_id' => $conversation->id,
                    'content' => $reply->content,
                ]);

                // Follow-up chips are a bonus — emitted after the answer, never block it.
                $suggestions = $this->coach->suggestFollowUps($conversation, $profile);
                if ($suggestions !== []) {
                    $emit('suggestions', ['items' => $suggestions]);
                }
            } catch (AiException $e) {
                Log::warning('[Coach] AI stream unavailable', ['error' => $e->getMessage()]);
                $emit('error', [
                    'conversation_id' => $conversation->id,
                    'message' => 'Your coach is offline right now (the AI service is unavailable). Your message was saved — try again in a moment.',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',   // tell nginx not to buffer the stream
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Snap-to-log: accept a photo (a meal or a bloodwork sheet), have vision extract and
     * log the data, and drop the photo + the coach's confirmation into the conversation.
     * Returns JSON the chat appends. Never 500s on AI failure.
     */
    public function scan(Request $request, ?Conversation $conversation = null): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'photo' => ['required', 'image', 'max:12288'],   // ≤ 12 MB
            'message' => ['nullable', 'string', 'max:1000'],   // optional caption: "this is what I ate"
        ]);
        $caption = trim((string) ($data['message'] ?? ''));

        if (! $conversation || $conversation->profile_id !== $profile->id) {
            $conversation = $profile->conversations()->create();
        }

        try {
            $result = $this->scans->scan($profile, $request->file('photo'), $caption ?: null);
        } catch (AiException $e) {
            Log::warning('[Coach] scan AI unavailable', ['error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'offline' => true,
                'conversation_id' => $conversation->id,
                'reply' => "I couldn't read that photo just now (the vision service is unavailable). Try again in a moment.",
            ], 200);
        }

        if (blank($conversation->title)) {
            $conversation->update(['title' => $result['kind'] === 'bloodwork' ? 'Bloodwork scan' : 'Photo log']);
        }

        // The photo (+ the user's caption) becomes a user turn; the coach's confirmation an
        // assistant turn — so the whole exchange survives a refresh.
        $conversation->messages()->create([
            'role' => 'user',
            'content' => ($caption !== '' ? $caption."\n\n" : '').'![photo]('.$result['image_url'].')',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $result['reply'],
        ]);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'kind' => $result['kind'],
            'logged' => $result['logged'],
            'image_url' => $result['image_url'],
            'reply' => $result['reply'],
        ]);
    }
}
