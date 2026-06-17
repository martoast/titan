<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\CoachMemoryBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_stores_and_dedupes_near_duplicates(): void
    {
        $p = User::factory()->create()->ensureProfile();

        CoachMemoryBook::remember($p, 'injury', 'Tweaked left shoulder on bench press', 3);
        CoachMemoryBook::remember($p, 'injury', 'tweaked the left shoulder on bench press', 2);  // near-dup → merge
        CoachMemoryBook::remember($p, 'dislike', 'Hates RDLs');

        $this->assertSame(2, $p->coachMemories()->active()->count());
        $shoulder = $p->coachMemories()->where('category', 'injury')->first();
        $this->assertSame(3, $shoulder->importance);   // kept the higher importance on merge
    }

    public function test_forget_archives_matching_memory(): void
    {
        $p = User::factory()->create()->ensureProfile();
        CoachMemoryBook::remember($p, 'dislike', 'Hates RDLs');
        CoachMemoryBook::remember($p, 'equipment', 'Trains at a commercial gym with full equipment');

        $removed = CoachMemoryBook::forget($p, 'hates RDLs');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $p->coachMemories()->active()->count());
    }

    public function test_digest_groups_by_category_for_the_prompt(): void
    {
        $p = User::factory()->create()->ensureProfile();
        CoachMemoryBook::remember($p, 'injury', 'Bad left knee — no deep lunges');
        CoachMemoryBook::remember($p, 'schedule', 'Can only train mornings before 8am');

        $digest = CoachMemoryBook::digest($p);
        $this->assertStringContainsString('Injuries', $digest);
        $this->assertStringContainsString('left knee', $digest);
        $this->assertStringContainsString('mornings', $digest);
    }

    public function test_tool_remember_then_memory_book_card(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $t = new CoachTools($p);

        $r = $t->dispatch('remember', ['category' => 'preference', 'content' => 'Loves push/pull/legs splits', 'importance' => 2]);
        $this->assertTrue($r['ok']);

        $card = $t->dispatch('memory_book', []);
        $this->assertSame('memory', $card['card']['type']);
        $this->assertSame(1, $card['card']['count']);
        $this->assertSame('Preferences', $card['card']['groups'][0]['label']);
    }

    public function test_memory_book_guides_when_empty(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $res = (new CoachTools($p))->dispatch('memory_book', []);
        $this->assertArrayHasKey('note', $res);
    }
}
