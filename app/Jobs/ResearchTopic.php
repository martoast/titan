<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\KnowledgePage;
use App\Models\Profile;
use App\Services\Coach\ResearchService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The coach's "go research this and report back" job. Runs the deep-research synthesis off the request
 * path (existing redis queue), files the result as a Brain wiki page (embedded so it's instantly
 * searchable), notifies the user, and drops the summary back into their chat thread so it's waiting
 * for them. Best-effort: a failure notifies gracefully instead of vanishing.
 */
class ResearchTopic implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 110;   // stays under the queue worker's 120s ceiling

    public function __construct(
        public int $profileId,
        public string $topic,
        public ?string $focus = null,
        public ?int $conversationId = null,
    ) {}

    public function handle(ResearchService $research, NotificationService $notifications): void
    {
        $profile = Profile::find($this->profileId);
        if (! $profile) {
            return;
        }

        try {
            $result = $research->run($profile, $this->topic, $this->focus);
        } catch (\Throwable $e) {
            Log::warning('[coach] research failed', ['topic' => $this->topic, 'error' => $e->getMessage()]);
            \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
            $notifications->notify($profile, __('📚 Research hit a snag'), "I couldn't finish researching \"{$this->topic}\" -- ask me to try again.", '/coach', 'research');
            $this->appendToChat("I tried to research **{$this->topic}** but ran into a problem -- ask me to try again in a moment.");

            return;
        }

        // File it in the Brain (idempotent on title), and embed it so it's searchable immediately.
        if (class_exists(KnowledgePage::class)) {
            $page = $profile->knowledgePages()->updateOrCreate(
                ['title' => $result['title']],
                ['content' => $result['markdown'], 'type' => 'research', 'slug' => KnowledgePage::slugFor($result['title'])],
            );
            if (class_exists(\App\Services\Brain\KnowledgeSearch::class)) {
                rescue(fn () => app(\App\Services\Brain\KnowledgeSearch::class)->embedPage($page), null, false);
            }
        }

        \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
        $notifications->notify($profile, __('📚 Research ready: :topic', ['topic' => $this->topic]), $result['summary'], '/coach', 'research');
        $this->appendToChat("📚 I finished researching **{$this->topic}**.\n\n{$result['summary']}\n\nI've saved the full writeup to your Brain as *\"{$result['title']}\"* -- ask me anything about it.");
    }

    /** Drop the result into the originating chat thread so it's there when they return. */
    private function appendToChat(string $content): void
    {
        if (! $this->conversationId) {
            return;
        }
        $conversation = Conversation::find($this->conversationId);
        if ($conversation && $conversation->profile_id === $this->profileId) {
            $conversation->messages()->create(['role' => 'assistant', 'content' => $content]);
        }
    }
}
