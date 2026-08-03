<?php

namespace App\Http\Controllers\Coach;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateCoachReply;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\Coach\CoachBriefingService;
use App\Services\Coach\CoachService;
use App\Services\Coach\ScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The AI coach chat. Renders the chat page (continuing the latest conversation),
 * starts new conversations, and handles message sends. The send endpoint persists the
 * user message, runs CoachService, stores + returns the assistant reply. If the AI is
 * offline it degrades gracefully -- the page still renders, and a send returns a
 * friendly "coach is offline" message instead of crashing.
 */
class CoachController extends Controller
{
    /** Messages loaded per page (initial render + each scroll-up fetch). */
    private const PAGE_SIZE = 20;

    public function __construct(
        protected CoachService $coach,
        protected CoachBriefingService $briefings,
        protected ScanService $scans,
    ) {}

    /**
     * The chat page. Opens the requested day (`?c=` id or `?d=` YYYY-MM-DD), else today's chat.
     * A PAST day is read-only -- you can look back at it, but anything you send belongs to today.
     */
    public function index(Request $request): View
    {
        $profile = $request->user()->ensureProfile();

        $conversation = null;
        if ($request->filled('c')) {
            $conversation = $profile->conversations()->whereKey($request->integer('c'))->first();
        } elseif ($request->filled('d')) {
            $conversation = $profile->conversations()
                ->whereNotNull('day')
                ->whereDate('day', $request->string('d')->toString())
                ->first();
        }

        // Default to today. Resolved WITHOUT creating it -- an empty day chat should exist only
        // once something is actually said, so merely opening the page doesn't litter the list.
        $conversation ??= $profile->conversations()
            ->whereDate('day', Conversation::today()->toDateString())
            ->first();

        $conversations = $profile->conversations()
            ->days()
            ->withCount(['messages as message_count' => fn ($q) => $q->whereIn('role', ['user', 'assistant'])])
            ->get()
            ->filter(fn (Conversation $c) => $c->message_count > 0)
            ->values();

        // Only the most recent page of messages renders up front -- older ones load as you scroll up.
        $page = $conversation ? $this->messagePage($conversation) : ['messages' => collect(), 'has_more' => false, 'oldest_id' => null];

        return view('coach.index', [
            'profile' => $profile,
            'conversation' => $conversation,
            'conversations' => $conversations,
            'messages' => $page['messages'],
            'hasMore' => $page['has_more'],
            'oldestId' => $page['oldest_id'],
            'readOnly' => $conversation !== null && ! $conversation->isToday(),
            'todayLabel' => Conversation::today()->format('l, F j, Y'),
            'starters' => CoachService::startersFor($profile),
            'aiOffline' => ! app(\App\Services\Ai\AiService::class)->configured(),
        ]);
    }

    /**
     * Every send lands in TODAY's chat, whatever conversation the client thought it was in.
     * The day is the thread's identity, so a message typed at 00:01 belongs to the new day even
     * if the browser tab has been sitting open on yesterday since last night -- and viewing an
     * old day can never append to it. Creates today's chat on first use.
     */
    private function todayFor(Request $request): Conversation
    {
        return Conversation::forDay($request->user()->ensureProfile());
    }

    /**
     * Paginated message history for a conversation (JSON). Returns the most recent page, or -- with
     * ?before={id} -- the page of messages older than that id. Powers AJAX chat switching and the
     * load-older-as-you-scroll-up behaviour, so a long thread never loads all at once.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $profile = $request->user()->ensureProfile();
        abort_unless($conversation->profile_id === $profile->id, 404);

        $page = $this->messagePage($conversation, $request->integer('before') ?: null);

        return response()->json([
            'day' => $conversation->day?->toDateString(),
            'day_label' => $conversation->dayLabel(),
            'day_full' => $conversation->dayFull(),
            'read_only' => ! $conversation->isToday(),
            'messages' => $page['messages']->map(fn (ChatMessage $m) => $this->messagePayload($m))->values(),
            'has_more' => $page['has_more'],
            'oldest_id' => $page['oldest_id'],
        ]);
    }

    /**
     * The profile's chat history as a list of DAYS (newest first) — what the native app's day
     * picker renders. Empty days are omitted: a day exists only once something was actually said.
     */
    public function days(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $days = $profile->conversations()
            ->days()
            ->withCount(['messages as message_count' => fn ($q) => $q->whereIn('role', ['user', 'assistant'])])
            ->get()
            ->filter(fn (Conversation $c) => $c->message_count > 0)
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'day' => $c->day?->toDateString(),
                'label' => $c->dayLabel(),
                'full' => $c->dayFull(),
                'message_count' => $c->message_count,
                'is_today' => $c->isToday(),
            ])->values();

        return response()->json([
            'today' => Conversation::today()->toDateString(),
            'days' => $days,
        ]);
    }

    /**
     * Messages by conversation id for the NATIVE app, resolved by hand rather than by route-model
     * binding so a stale id degrades to today's chat instead of a 404.
     *
     * The app caches the last conversation it was in; if that conversation is gone (deleted, or
     * belonging to another profile) the old binding 404'd, `loadHistory`'s `try?` swallowed it, and
     * the user was left staring at an empty coach with no way back. Falling back to today is both
     * more useful and what they almost certainly wanted.
     */
    public function apiMessages(Request $request, int $id): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $conversation = $profile->conversations()->whereKey($id)->first()
            ?? $profile->conversations()->whereDate('day', Conversation::today()->toDateString())->first();

        if (! $conversation) {
            return response()->json([
                'day' => Conversation::today()->toDateString(),
                'day_label' => 'Today',
                'day_full' => Conversation::today()->format('l, F j, Y'),
                'read_only' => false,
                'messages' => [],
                'has_more' => false,
                'oldest_id' => null,
            ]);
        }

        return $this->messages($request, $conversation);
    }

    /**
     * One message as the chat renders it. `kind` marks the coach's proactive messages (briefing /
     * reaction) so they're styled as pushes rather than replies, and `at` carries the local
     * timestamp so every message shows when it was said.
     *
     * @return array<string,mixed>
     */
    private function messagePayload(ChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'kind' => $m->kind,
            'content' => (string) $m->content,
            'at' => $m->created_at?->timezone(Conversation::tz())->format('g:i A'),
            'status' => $m->status,   // null|pending|streaming|complete|failed (for the reconcile-on-reopen client)
        ];
    }

    /**
     * Poll one message (the native app's live-reveal + reconcile). Returns the assistant reply's
     * growing content + status while a background {@see GenerateCoachReply} job fills it in, so the
     * chat can "type" it out and know when it's done — the same row survives the phone suspending.
     */
    public function message(Request $request, ChatMessage $message): JsonResponse
    {
        $profile = $request->user()->ensureProfile();
        abort_unless($message->conversation?->profile_id === $profile->id, 404);

        return response()->json([
            'id' => $message->id,
            'role' => $message->role,
            'content' => (string) $message->content,
            'status' => $message->status,
        ]);
    }

    /**
     * Durable send for the native app. Persists the user's message (and banks any photo) + an empty
     * `pending` assistant placeholder, then hands generation to a queue job and returns IMMEDIATELY.
     * The reply is produced off the request path, so the user can send + lock the phone and the coach
     * still finishes — the client polls {@see message()} / reconciles history on reopen. Text and/or
     * photo in one turn.
     */
    public function sendAsync(Request $request, ?Conversation $conversation = null): JsonResponse
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'photo' => ['nullable', 'image', 'max:12288'],   // ≤ 12 MB
        ]);

        $text = trim((string) ($data['message'] ?? ''));
        $hasPhoto = $request->hasFile('photo') && $request->file('photo')->isValid();

        if ($text === '' && ! $hasPhoto) {
            return response()->json(['ok' => false, 'error' => 'Send a message or a photo.'], 422);
        }

        $conversation = $this->todayFor($request);

        // Bank the photo while the request is alive (the upload must land now); the job reads it later.
        $imagePath = null;
        $userContent = $text;
        if ($hasPhoto) {
            $imagePath = $request->file('photo')->store('coach/scans', 'public');
            $imageUrl = Storage::disk('public')->url($imagePath);
            $userContent = ($text !== '' ? $text."\n\n" : '').'![photo]('.$imageUrl.')';
        }

        $userMsg = $conversation->messages()->create(['role' => 'user', 'content' => $userContent]);

        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '',
            'status' => ChatMessage::STATUS_PENDING,
        ]);

        GenerateCoachReply::dispatch($conversation->id, $assistant->id, $text, $imagePath);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'user_message' => ['id' => $userMsg->id, 'role' => 'user', 'content' => (string) $userMsg->content],
            'pending_message_id' => $assistant->id,
        ], 202);
    }

    /**
     * One page of a conversation's user/assistant messages, newest-anchored. Without $before it's the
     * latest page; with it, the page immediately older. Returns chronological order for rendering.
     *
     * @return array{messages:\Illuminate\Support\Collection,has_more:bool,oldest_id:?int}
     */
    private function messagePage(Conversation $conversation, ?int $before = null, int $limit = self::PAGE_SIZE): array
    {
        $q = $conversation->messages()->whereIn('role', ['user', 'assistant']);
        if ($before) {
            $q->where('id', '<', $before);
        }
        // Fetch one extra to know whether there's an older page. reorder() drops the relation's
        // default id-asc ordering so we genuinely get the NEWEST rows.
        $rows = $q->reorder('id', 'desc')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->reverse()->values();

        return ['messages' => $rows, 'has_more' => $hasMore, 'oldest_id' => $rows->first()?->id];
    }

    /**
     * Regenerate today's briefing on demand (the "Today's briefing" card button). Picks
     * morning vs evening by local time, stores it in the Daily Briefings thread, and
     * redirects back to the coach. Never 500s -- AI failure shows a friendly status.
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

            return redirect('/coach')->with('status', 'Your coach is offline right now -- try regenerating your briefing in a moment.');
        }

        return redirect('/coach')->with('status', 'Fresh briefing ready.');
    }

    /**
     * There is no "new chat" any more -- the day is the thread. Kept so an old bookmark, a cached
     * page's form post, or the native app hitting POST /coach lands somewhere sensible: today.
     */
    public function store(Request $request): RedirectResponse
    {
        return redirect('/coach?c='.$this->todayFor($request)->id);
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

        $conversation = $this->todayFor($request);

        $wantsJson = $request->expectsJson() || $request->boolean('ajax');

        try {
            $reply = $this->coach->reply($conversation, $profile, $data['message']);
        } catch (AiException $e) {
            Log::warning('[Coach] AI unavailable', ['error' => $e->getMessage()]);

            $friendly = 'Your coach is offline right now (the AI service is unavailable). Your message was saved -- try again in a moment.';

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

        $conversation = $this->todayFor($request);

        // Release the session lock so this long-lived request doesn't block the user's
        // other tabs/requests while the stream is open. The native app authenticates with a
        // bearer token (no session), so only do this when a session actually exists.
        if ($request->hasSession()) {
            $request->session()->save();
        }

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

                // Follow-up chips are a bonus -- emitted after the answer, never block it.
                $suggestions = $this->coach->suggestFollowUps($conversation, $profile);
                if ($suggestions !== []) {
                    $emit('suggestions', ['items' => $suggestions]);
                }
            } catch (AiException $e) {
                Log::warning('[Coach] AI stream unavailable', ['error' => $e->getMessage()]);
                $emit('error', [
                    'conversation_id' => $conversation->id,
                    'message' => 'Your coach is offline right now (the AI service is unavailable). Your message was saved -- try again in a moment.',
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

        // The client downscales + converts to JPEG before upload, so this should always pass; the
        // friendly JSON reply (vs a raw 422) covers the rare case it doesn't -- e.g. an un-converted HEIC.
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'photo' => ['required', 'image', 'max:12288'],   // ≤ 12 MB
            'message' => ['nullable', 'string', 'max:1000'],   // optional caption: "this is what I ate"
        ]);
        if ($validator->fails()) {
            $tooBig = $request->hasFile('photo') && ! $request->file('photo')->isValid();

            return response()->json([
                'ok' => false,
                'reply' => $tooBig
                    ? "That image was too large to upload. Try again -- it should compress automatically."
                    : "I couldn't read that file -- please attach a photo (JPEG, PNG or HEIC) and try again.",
            ], 200);
        }
        $caption = trim((string) ($validator->validated()['message'] ?? ''));

        $conversation = $this->todayFor($request);

        // Bank the photo while the request is alive (the upload must land now).
        $path = $request->file('photo')->store('coach/scans', 'public');
        $imageUrl = Storage::disk('public')->url($path);

        // The photo (+ caption) becomes a user turn; then the HYBRID coach runs — it SEES the image and
        // can call scan_photo to log a meal/labs accurately. Same path as the native async send, run
        // synchronously here so the web chat gets its reply in one response. The whole exchange persists.
        $conversation->messages()->create([
            'role' => 'user',
            'content' => ($caption !== '' ? $caption."\n\n" : '').'![photo]('.$imageUrl.')',
        ]);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant', 'content' => '', 'status' => ChatMessage::STATUS_PENDING,
        ]);

        // generate() fills + statuses the placeholder itself and never throws (a failure is recorded on it).
        $this->coach->generate($conversation, $profile, $assistant, $caption, $path);
        $assistant->refresh();

        return response()->json([
            'ok' => $assistant->status !== ChatMessage::STATUS_FAILED,
            'conversation_id' => $conversation->id,
            'image_url' => $imageUrl,
            'reply' => (string) $assistant->content,
        ]);
    }

    /**
     * Transcribe a recorded voice clip (OpenAI). Returns the text only -- the client drops
     * it into the input for the user to review and edit before sending. Never auto-sends.
     */
    public function transcribe(Request $request, \App\Services\Ai\AiService $ai): JsonResponse
    {
        $request->user()->ensureProfile();

        // Validate manually so we ALWAYS return JSON (a redirect-back would feed the
        // client HTML, which its res.json() can't parse).
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'audio' => [
                'required', 'file', 'max:25600',   // ≤ 25 MB (OpenAI limit)
                'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/mpga,audio/wav,audio/x-wav,audio/m4a,audio/x-m4a,video/webm,video/mp4',
            ],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => "That recording couldn't be read -- try again."], 422);
        }

        $file = $request->file('audio');
        $ext = strtolower((string) $file->getClientOriginalExtension()) ?: 'webm';
        $text = $ai->transcribe((string) file_get_contents($file->getRealPath()), 'voice.'.$ext);

        if ($text === null) {
            return response()->json(['ok' => false, 'error' => "Couldn't transcribe that -- try again."], 200);
        }

        return response()->json(['ok' => true, 'text' => $text]);
    }
}
