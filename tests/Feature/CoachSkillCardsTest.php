<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachSkillCardsTest extends TestCase
{
    use RefreshDatabase;

    private function profile()
    {
        return User::factory()->create()->ensureProfile();
    }

    public function test_skill_card_tools_return_card_objects(): void
    {
        $t = new CoachTools($this->profile());

        foreach (['daily_checkin', 'strain_status', 'macros_today'] as $tool) {
            $res = $t->dispatch($tool, []);
            $this->assertArrayHasKey('card', $res, "{$tool} should return a card");
            $this->assertArrayHasKey('type', $res['card']);
            $this->assertArrayHasKey('_show', $res);
        }
    }

    public function test_logging_a_meal_returns_a_meal_confirmation_card(): void
    {
        // COACH CARDS v2 · B4: log_meal now confirms with a dedicated `meal` card (the day-total `macros`
        // card is one tap away via the card action), not the flat macros card.
        $p = $this->profile();
        $p->update(['settings' => ['timezone' => 'UTC', 'macro_targets' => ['calories' => 3000, 'protein_g' => 200]]]);
        $t = new CoachTools($p->refresh());

        $res = $t->dispatch('log_meal', ['name' => 'Steak & rice', 'calories' => 800, 'protein_g' => 60, 'carbs_g' => 70, 'fat_g' => 25]);
        $this->assertTrue($res['ok']);

        $card = $res['card'];
        $this->assertSame('meal', $card['type']);
        $this->assertSame('Steak & rice', $card['name']);
        $this->assertSame(800, $card['calories']);
        $this->assertSame(60.0, $card['protein_g']);
        $this->assertSame(70.0, $card['carbs_g']);
        $this->assertSame(25.0, $card['fat_g']);
        // The card is actionable (edit the macros + jump to today's totals) — every action has a typed
        // equivalent, so both are prompts.
        $this->assertNotEmpty($card['actions']);
        $this->assertContains('Edit', array_column($card['actions'], 'label'));
    }

    public function test_start_workout_returns_a_live_card(): void
    {
        $res = (new CoachTools($this->profile()))->dispatch('start_workout', ['name' => 'Push day']);
        $this->assertSame('workout', $res['card']['type']);
        $this->assertSame('Push day', $res['card']['name']);
    }

    public function test_macros_today_sums_the_day(): void
    {
        $p = $this->profile();
        $p->update(['settings' => ['timezone' => 'UTC']]);
        $p->meals()->create(['name' => 'A', 'eaten_at' => now(), 'calories' => 500, 'protein_g' => 40, 'carbs_g' => 50, 'fat_g' => 15]);
        $p->meals()->create(['name' => 'B', 'eaten_at' => now(), 'calories' => 300, 'protein_g' => 25, 'carbs_g' => 20, 'fat_g' => 10]);

        $card = (new CoachTools($p->refresh()))->dispatch('macros_today', [])['card'];
        $this->assertSame(800, $card['calories']['value']);
        $this->assertSame(65, $card['protein']['value']);
        $this->assertStringContainsString('2 meals', $card['footer']);
    }

    public function test_all_new_card_tools_are_registered(): void
    {
        $names = array_map(fn ($t) => $t['function']['name'], (new CoachTools($this->profile()))->schemas());
        foreach (['daily_checkin', 'sleep_detail', 'strain_status', 'bloodwork_panel', 'macros_today'] as $n) {
            $this->assertContains($n, $names, "missing {$n}");
        }
    }
}
