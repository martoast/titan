<?php

namespace App\Http\Controllers\Coach;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Coach\CoachBriefingService;
use App\Services\Coach\CoachService;
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
    ) {}

    /** The chat page — opens the requested conversation, else the most recent, else a fresh one. */
    public function index(Request $request): View
    {
        $profile = $request->user()->ensureProfile();

        $conversation = null;
        if ($request->filled('c')) {
            $conversation = $profile->conversations()->whereKey($request->integer('c'))->first();
        }
        $conversation ??= $profile->conversations()->latest('id')->first();

        $conversations = $profile->conversations()->latest('id')->get();

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
}
