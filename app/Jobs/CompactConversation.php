<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Coach\CoachService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Condense a long conversation's older turns into its running summary -- off the request path so it
 * never adds latency to a reply. Dispatched to the existing redis "default" queue, which the
 * fitness-ai-queue worker already processes (no new container needed). Idempotent: re-checks the
 * threshold, so a duplicate dispatch is a cheap no-op.
 */
class CompactConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $conversationId) {}

    public function handle(CoachService $coach): void
    {
        $conversation = Conversation::find($this->conversationId);
        if ($conversation) {
            $coach->compact($conversation);
        }
    }
}
