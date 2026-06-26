<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntakeEvent;
use App\Models\Profile;
use App\Models\StackItem;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\Stack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "What you take" — supplements & medications. Covers the coach tools (add/log/check) and the
 * native API surface, plus the Stack::today card and the (informational) interaction checker.
 */
class StackTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): Profile
    {
        return User::factory()->create()->ensureProfile();
    }

    private function tools(Profile $profile): CoachTools
    {
        return app(CoachTools::class, ['profile' => $profile]);
    }

    private function auth(User $user): array
    {
        [, $plain] = ApiToken::mint($user, 'test', ['*']);

        return ['Authorization' => 'Bearer '.$plain];
    }

    // --- coach tools --------------------------------------------------------

    public function test_coach_adds_a_stack_item_with_dose_and_schedule(): void
    {
        $p = $this->profile();
        $res = $this->tools($p)->dispatch('add_stack_item', [
            'name' => 'Creatine', 'dose_amount' => 5, 'dose_unit' => 'g', 'times' => ['morning'], 'frequency' => 'daily',
        ]);

        $this->assertTrue($res['ok']);
        $item = StackItem::first();
        $this->assertSame('Creatine', $item->name);
        $this->assertSame('supplement', $item->kind);
        $this->assertSame('5 g', $item->doseLabel());
        $this->assertSame(['morning'], $item->slots());
    }

    public function test_my_stack_card_reflects_a_logged_dose(): void
    {
        $p = $this->profile();
        $this->tools($p)->dispatch('add_stack_item', ['name' => 'Vitamin D3', 'dose_amount' => 5000, 'dose_unit' => 'IU', 'times' => ['morning']]);

        $before = Stack::today($p);
        $this->assertSame(0, $before['counts']['taken']);
        $this->assertSame(1, $before['counts']['total']);

        $this->tools($p)->dispatch('log_intake', ['name' => 'vitamin d', 'slot' => 'morning']);

        $after = Stack::today($p->fresh());
        $this->assertSame(1, $after['counts']['taken']);
        $this->assertSame('All in for today', $after['footer']);
        $this->assertSame('taken', IntakeEvent::first()->status);
        $this->assertSame('coach', IntakeEvent::first()->source);
    }

    public function test_one_off_intake_logs_even_without_a_stack_item(): void
    {
        $p = $this->profile();
        $res = $this->tools($p)->dispatch('log_intake', ['name' => 'Ibuprofen', 'dose_amount' => 200, 'dose_unit' => 'mg']);

        $this->assertTrue($res['ok']);
        $e = IntakeEvent::first();
        $this->assertNull($e->stack_item_id);
        $this->assertSame('Ibuprofen', $e->name);
    }

    public function test_check_interactions_flags_a_known_seed_pair(): void
    {
        $p = $this->profile();
        $t = $this->tools($p);
        $t->dispatch('add_stack_item', ['name' => 'Calcium', 'dose_amount' => 600, 'dose_unit' => 'mg', 'times' => ['morning']]);
        $t->dispatch('add_stack_item', ['name' => 'Iron', 'dose_amount' => 18, 'dose_unit' => 'mg', 'times' => ['morning']]);

        $res = $t->dispatch('check_interactions', []);
        $this->assertTrue($res['ok']);
        $this->assertNotEmpty($res['flags']);
        $this->assertSame('timing', $res['flags'][0]['severity']);
        $this->assertStringContainsString('not medical advice', strtolower($res['disclaimer']));
    }

    public function test_as_needed_items_stay_off_the_daily_checklist(): void
    {
        $p = $this->profile();
        $this->tools($p)->dispatch('add_stack_item', ['name' => 'Ibuprofen', 'kind' => 'medication', 'frequency' => 'as_needed']);

        $this->assertSame(0, Stack::today($p)['counts']['total']);
    }

    // --- native API ---------------------------------------------------------

    public function test_api_index_returns_today_items_and_disclaimer(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $this->getJson('/api/me/stack', $this->auth($user))
            ->assertOk()
            ->assertJsonStructure(['today' => ['slots', 'counts', 'footer'], 'items', 'flags', 'disclaimer']);
    }

    public function test_api_create_log_and_undo_roundtrip(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $headers = $this->auth($user);

        $create = $this->postJson('/api/me/stack', [
            'name' => 'Magnesium Glycinate', 'kind' => 'supplement', 'dose_amount' => 400, 'dose_unit' => 'mg',
            'schedule' => ['frequency' => 'daily', 'times' => ['night'], 'with_food' => false],
        ], $headers)->assertOk()->json();

        $id = $create['item']['id'];
        $this->assertSame('400 mg', $create['item']['dose_label']);
        $this->assertSame(1, $create['today']['counts']['total']);

        $log = $this->postJson("/api/me/stack/{$id}/intake", ['slot' => 'night'], $headers)->assertOk()->json();
        $this->assertSame(1, $log['today']['counts']['taken']);

        $eventId = $log['event_id'];
        $undo = $this->deleteJson("/api/me/stack/intake/{$eventId}", [], $headers)->assertOk()->json();
        $this->assertSame(0, $undo['today']['counts']['taken']);
    }

    public function test_api_search_returns_builtin_results_offline(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $results = $this->getJson('/api/me/stack/search?q=magnesium', $this->auth($user))
            ->assertOk()->json('results');

        $this->assertNotEmpty($results);
        $this->assertStringContainsStringIgnoringCase('magnesium', $results[0]['name']);
    }

    public function test_stack_is_scoped_to_the_owning_profile(): void
    {
        $a = User::factory()->create();
        $a->ensureProfile();
        $b = User::factory()->create();
        $b->ensureProfile();

        $id = $this->postJson('/api/me/stack', ['name' => 'Zinc'], $this->auth($a))->assertOk()->json('item.id');

        // B cannot touch A's item.
        $this->deleteJson("/api/me/stack/{$id}", [], $this->auth($b))->assertNotFound();
        $this->getJson('/api/me/stack', $this->auth($b))->assertOk()->assertJsonCount(0, 'items');
    }
}
