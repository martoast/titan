<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Services\Web\WebSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.serpapi.key' => 'test-serp', 'services.scraperapi.key' => 'test-scraper']);
    }

    private function fakeSearch(): void
    {
        Http::fake([
            'serpapi.com/*' => Http::response([
                'answer_box' => ['answer' => '195 calories in 100g grilled chicken breast'],
                'organic_results' => [
                    ['title' => 'FatSecret', 'link' => 'https://www.fatsecret.com/x', 'snippet' => 'Calories 195, Protein 29.55g, Carbs 0g, Fat 7.72g'],
                    ['title' => 'Healthline', 'link' => 'https://www.healthline.com/y', 'snippet' => '165 calories, 31g protein per 100g'],
                ],
            ]),
            'api.scraperapi.com/*' => Http::response('<html><head><style>x{}</style></head><body><script>junk()</script><h1>Title</h1> Real content here.</body></html>'),
        ]);
    }

    public function test_search_parses_answer_and_results(): void
    {
        $this->fakeSearch();
        $r = app(WebSearch::class)->search('calories in chicken breast', 4);

        $this->assertStringContainsString('195 calories', $r['answer']);
        $this->assertCount(2, $r['results']);
        $this->assertSame('https://www.fatsecret.com/x', $r['results'][0]['link']);
    }

    public function test_scrape_strips_scripts_and_tags(): void
    {
        $this->fakeSearch();
        $text = app(WebSearch::class)->scrape('https://example.com/article');

        $this->assertStringContainsString('Real content here', $text);
        $this->assertStringContainsString('Title', $text);
        $this->assertStringNotContainsString('junk()', $text);   // script stripped
    }

    public function test_facts_block_includes_answer_and_source_hosts(): void
    {
        $this->fakeSearch();
        $facts = app(WebSearch::class)->facts('calories in chicken breast');

        $this->assertStringContainsString('195 calories', $facts);
        $this->assertStringContainsString('www.fatsecret.com', $facts);
    }

    public function test_degrades_gracefully_without_a_key(): void
    {
        config(['services.serpapi.key' => null]);
        $w = app(WebSearch::class);
        $this->assertFalse($w->configured());
        $this->assertSame(['answer' => null, 'results' => []], $w->search('anything'));
    }

    public function test_coach_tools_expose_web_search_and_lookup_food(): void
    {
        $this->fakeSearch();
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $names = array_map(fn ($t) => $t['function']['name'], $tools->schemas());
        $this->assertContains('web_search', $names);
        $this->assertContains('lookup_food', $names);   // food lookup itself is covered in FoodLibraryTest

        $ws = $tools->dispatch('web_search', ['query' => 'creatine dose']);
        $this->assertNotEmpty($ws['results']);
    }
}
