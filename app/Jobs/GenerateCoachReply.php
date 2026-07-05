<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\Coach\CoachService;
use App\Services\Coach\ScanService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generate a coach reply OFF the request path. The controller has already persisted the user's
 * message and an empty `pending` assistant placeholder; this job fills that placeholder in — writing
 * the answer as it streams so a polling client sees it "type", then marking it `complete`/`failed`.
 *
 * This is what makes the coach behave like ChatGPT: you can send a message (even a big photo), lock
 * the phone, and the reply still generates + persists server-side, waiting for you when you reopen.
 *
 * Two flavours in one job:
 *   • text turn  → the tool-calling coach brain (CoachService::generate).
 *   • photo turn → the vision snap-to-log pipeline over the already-stored image (ScanService).
 */
class GenerateCoachReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Give a tool-calling turn room to finish (OpenAI + up to 8 tool steps). */
    public int $timeout = 180;

    /**
     * @param  int  $conversationId  the thread
     * @param  int  $assistantMessageId  the pending placeholder to fill
     * @param  string  $userText  the user's message text (caption, for a photo turn)
     * @param  string|null  $imagePath  a photo already stored on the `public` disk, or null for a text turn
     */
    public function __construct(
        public int $conversationId,
        public int $assistantMessageId,
        public string $userText,
        public ?string $imagePath = null,
    ) {}

    public function handle(CoachService $coach, ScanService $scans, NotificationService $notifications): void
    {
        $assistant = ChatMessage::find($this->assistantMessageId);
        $conversation = Conversation::find($this->conversationId);
        $profile = $conversation?->profile;

        if (! $assistant || ! $conversation || ! $profile) {
            return;   // the thread/placeholder was deleted before we ran — nothing to do.
        }

        // If the user already retried and this placeholder was resolved, don't clobber it.
        if (! in_array($assistant->status, [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING], true)) {
            return;
        }

        if ($this->imagePath !== null) {
            $this->handlePhoto($scans, $conversation, $profile, $assistant);
        } else {
            // Fills + statuses the placeholder itself; never throws.
            $coach->generate($conversation, $profile, $assistant, $this->userText);
        }

        $this->notifyDone($notifications, $profile, $conversation, $assistant);
    }

    private function handlePhoto(ScanService $scans, Conversation $conversation, $profile, ChatMessage $assistant): void
    {
        $assistant->update(['status' => ChatMessage::STATUS_STREAMING]);

        try {
            $result = $scans->scanStored($profile, $this->imagePath, $this->userText !== '' ? $this->userText : null);

            if (blank($conversation->title)) {
                $conversation->update(['title' => ($result['kind'] ?? '') === 'bloodwork' ? 'Bloodwork scan' : 'Photo log']);
            }

            $assistant->update([
                'content' => $result['reply'] ?? "I logged that photo for you.",
                'status' => ChatMessage::STATUS_COMPLETE,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Coach] background photo scan failed', ['error' => $e->getMessage()]);
            $assistant->update([
                'content' => "I couldn't read that photo just now (the vision service is unavailable). Try again in a moment.",
                'status' => ChatMessage::STATUS_FAILED,
            ]);
        }
    }

    /** Best-effort push so the reply surfaces even if the app is backgrounded (no-op without a token). */
    private function notifyDone(NotificationService $notifications, $profile, Conversation $conversation, ChatMessage $assistant): void
    {
        if ($assistant->status !== ChatMessage::STATUS_COMPLETE) {
            return;
        }

        try {
            $notifications->notify(
                $profile,
                'Coach',
                Str::limit(strip_tags((string) $assistant->content), 120),
                url: '/coach?c='.$conversation->id,
            );
        } catch (\Throwable $e) {
            Log::info('[Coach] reply push skipped', ['error' => $e->getMessage()]);
        }
    }

    /** A worker crash / timeout shouldn't leave the placeholder spinning forever. */
    public function failed(\Throwable $e): void
    {
        $assistant = ChatMessage::find($this->assistantMessageId);
        if ($assistant && in_array($assistant->status, [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING], true)) {
            $assistant->update([
                'content' => trim((string) $assistant->content) !== '' ? $assistant->content
                    : 'Something interrupted your coach mid-reply. Please try again.',
                'status' => ChatMessage::STATUS_FAILED,
            ]);
        }
    }
}
