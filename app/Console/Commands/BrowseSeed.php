<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deterministic data for the browser-test harness. Creates a known, long "[browse-test]" conversation
 * on a user so the chat pagination + compaction UI has something to exercise. Idempotent: clears any
 * previous browse-test threads first. Prints the conversation id (the harness captures it).
 *
 *   php artisan titan:browse-seed                       # first user, 40 messages
 *   php artisan titan:browse-seed --email=a@b.com --messages=60
 */
class BrowseSeed extends Command
{
    protected $signature = 'titan:browse-seed {--email=} {--messages=40 : Total user+assistant messages}';

    protected $description = 'Seed a long [browse-test] conversation for the browser test harness.';

    public function handle(): int
    {
        $user = $this->option('email')
            ? User::where('email', $this->option('email'))->first()
            : User::query()->oldest('id')->first();

        if (! $user) {
            $this->error('No user found to seed.');

            return self::FAILURE;
        }
        $profile = $user->ensureProfile();

        // Clear prior browse-test threads so the run is deterministic.
        $profile->conversations()->where('title', 'like', '[browse-test]%')->each(function ($c) {
            $c->messages()->delete();
            $c->delete();
        });

        $convo = $profile->conversations()->create(['title' => '[browse-test] long thread']);
        $topics = ['my chest is lagging', 'how much protein today', 'is my sleep ok', 'plan a push day',
            'should I deload', "what's my readiness", 'creatine timing', 'how to grow side delts',
            'cutting calories', 'an RDL alternative for my lower back'];

        $pairs = max(2, (int) ceil($this->option('messages') / 2));
        for ($i = 1; $i <= $pairs; $i++) {
            $t = $topics[($i - 1) % count($topics)];
            $convo->messages()->create(['role' => 'user', 'content' => "Q{$i}: {$t}?"]);
            $convo->messages()->create(['role' => 'assistant', 'content' => "A{$i}: Here's detailed coaching on **{$t}** -- sets, reps, RIR and the rationale, message number {$i} so you can tell the pages apart."]);
        }

        $this->line((string) $convo->id);   // stdout = the conversation id for the harness

        return self::SUCCESS;
    }
}
