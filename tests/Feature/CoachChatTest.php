<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_page_renders_with_the_markdown_renderer(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get('/coach');
        $resp->assertOk();
        $resp->assertSee('coach-prose', false);               // the rich-markdown container
        $resp->assertSee('renderMarkdown', false);            // wired to the full GFM renderer, not the old regex
    }
}
