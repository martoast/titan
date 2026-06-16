<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ChairStand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChairStandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bands_against_age_norms(): void
    {
        // 70-year-old man: below-average cut is 12 reps.
        $this->assertSame('below', ChairStand::score(9, 70, false)['band']);
        $this->assertSame('average', ChairStand::score(14, 70, false)['band']);
        $this->assertSame('good', ChairStand::score(20, 70, false)['band']);
        // A young adult clears the older-age cut easily but the bar is higher.
        $this->assertSame('good', ChairStand::score(25, 30, false)['band']);
    }

    public function test_route_scores_and_flashes(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['birthdate' => now()->subYears(68)->toDateString(), 'sex' => 'M']);

        $resp = $this->actingAs($user)->post(route('fitness.chair-stand'), ['reps' => 10]);
        $resp->assertRedirect(route('fitness.index'));
        $resp->assertSessionHas('chairStand', fn ($s) => $s['reps'] === 10 && in_array($s['band'], ['below', 'average', 'good'], true));
    }

    public function test_route_needs_birthdate(): void
    {
        $user = User::factory()->create();   // no birthdate
        $resp = $this->actingAs($user)->post(route('fitness.chair-stand'), ['reps' => 10]);
        $resp->assertSessionHasErrors('reps');
    }
}
