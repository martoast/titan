<?php

namespace Tests\Feature;

use App\Models\KnowledgePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_lists_research_briefs_only(): void
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->knowledgePages()->create(['title' => '5/3/1 Program', 'slug' => '531', 'type' => 'research', 'content' => '# 5/3/1\nA strength program.']);
        $p->knowledgePages()->create(['title' => 'Family History', 'slug' => 'fam', 'type' => 'note', 'content' => 'Dad has high cholesterol.']);

        $resp = $this->actingAs($u)->get('/research');
        $resp->assertOk()->assertSee('5/3/1 Program');
        $resp->assertDontSee('Family History');
    }

    public function test_brief_renders_as_markdown_and_is_owner_scoped(): void
    {
        $u = User::factory()->create();
        $page = $u->ensureProfile()->knowledgePages()->create(['title' => 'Carb Cycling', 'slug' => 'cc', 'type' => 'research', 'content' => "# Carb Cycling\n\n- Point one\n- Point two"]);

        $this->actingAs($u)->get("/research/{$page->id}")->assertOk()->assertSee('Carb Cycling')->assertSee('Point one');

        // Another user can't read it.
        $this->actingAs(User::factory()->create())->get("/research/{$page->id}")->assertNotFound();
    }

    public function test_empty_state_when_no_research(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get('/research')->assertOk()->assertSee('No research yet');
    }
}
