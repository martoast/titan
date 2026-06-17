<?php

namespace Tests\Feature;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Services\Brain\KnowledgeBase;
use App\Services\Coach\CoachTools;
use App\Support\CoachMemoryBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private function seedKb(): User
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();

        // A coach memory + a wiki page, both touching "shoulder".
        CoachMemoryBook::remember($p, 'injury', 'Left shoulder impingement — avoid heavy overhead pressing', 3);
        $p->knowledgePages()->create([
            'title' => 'Shoulder rehab plan',
            'slug' => KnowledgePage::slugFor('Shoulder rehab plan'),
            'type' => 'note',
            'content' => 'Physio gave band external rotations and face pulls for the shoulder, 3x/week.',
        ]);
        $p->knowledgePages()->create([
            'title' => 'Bloodwork history',
            'slug' => KnowledgePage::slugFor('Bloodwork history'),
            'type' => 'note',
            'content' => 'Ferritin was low in January, supplementing iron.',
        ]);

        return $u;
    }

    public function test_one_search_spans_memory_and_wiki(): void
    {
        $u = $this->seedKb();
        $hits = app(KnowledgeBase::class)->search($u->profile, 'shoulder', 8);

        $sources = array_column($hits, 'source');
        $this->assertContains('memory', $sources);   // the remembered injury
        $this->assertContains('note', $sources);      // the wiki rehab page

        // The unrelated bloodwork page should not crowd the top results for "shoulder".
        $titles = array_filter(array_column($hits, 'title'));
        $this->assertContains('Shoulder rehab plan', $titles);
    }

    public function test_fast_path_returns_without_embeddings_when_keyword_matches(): void
    {
        $u = $this->seedKb();
        // AiService isn't configured in tests, so this is purely the instant lexical path.
        $hits = app(KnowledgeBase::class)->search($u->profile, 'iron ferritin', 8);
        $this->assertNotEmpty($hits);
        $this->assertSame('Bloodwork history', $hits[0]['title']);
    }

    public function test_coach_tool_returns_source_tagged_hits(): void
    {
        $u = $this->seedKb();
        $res = (new CoachTools($u->profile))->dispatch('search_knowledge', ['query' => 'shoulder']);

        $this->assertIsArray($res);
        $this->assertContains('memory', array_column($res, 'source'));
        $this->assertContains('wiki', array_column($res, 'source'));
        $this->assertArrayHasKey('snippet', $res[0]);
    }
}
