<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sign-up and account deletion from inside the native app.
 *
 * Until 2026-11-13 neither existed: LoginView offered only "Sign in" and the API had no register
 * route, so anybody who installed Titan from the App Store could not create an account at all —
 * which is exactly why there were zero signups in the three weeks after launch. Deletion ships in
 * the same breath because App Store Guideline 5.1.1(v) requires it the moment an app supports
 * account creation.
 */
class MobileAccountTest extends TestCase
{
    use RefreshDatabase;

    // ---- sign-up -------------------------------------------------------------------------

    public function test_a_stranger_can_create_an_account_and_the_token_works_immediately(): void
    {
        $res = $this->postJson('/api/register', [
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => "New Person's iPhone",
        ]);

        $res->assertStatus(201)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'onboarded']])
            // A brand-new account has not onboarded — this is what sends the app into
            // OnboardingFlow instead of an empty dashboard.
            ->assertJsonPath('user.onboarded', false);

        $this->assertDatabaseHas('users', ['email' => 'new.person@example.com']);

        // The returned token must authenticate at once, or the app would have to ask for the
        // password a second time on the screen right after sign-up.
        $this->withHeader('Authorization', 'Bearer '.$res->json('token'))
            ->getJson('/api/me')->assertOk();
    }

    public function test_a_capitalised_email_is_accepted_and_folded(): void
    {
        // Phones autocapitalise. The web form's `lowercase` rule rejects this outright, which
        // would be a 422 on a person's first ever action.
        $this->postJson('/api/register', [
            'name' => 'Shift Key',
            'email' => '  Alex@Example.COM ',
            'password' => 'correct-horse-battery',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'alex@example.com']);
    }

    public function test_a_capitalised_email_still_logs_in(): void
    {
        $user = User::factory()->create([
            'email' => 'folded@example.com',
            'password' => Hash::make('s3cret-pass'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'Folded@Example.com',
            'password' => 's3cret-pass',
        ])->assertOk();

        $this->assertSame('folded@example.com', $user->fresh()->email);
    }

    public function test_one_address_cannot_make_two_accounts_even_in_different_case(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Impostor',
            'email' => 'TAKEN@example.com',
            'password' => 'correct-horse-battery',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    // ---- deletion ------------------------------------------------------------------------

    public function test_deleting_the_account_erases_the_person_and_their_health_data(): void
    {
        Storage::fake('public');
        Storage::fake('raw');

        [$user, $token] = $this->signedIn();
        $profile = $user->profile;

DB::table('sleep_logs')->insert([
            'profile_id' => $profile->id,
            'slept_at' => '2026-11-12',
            'duration_min' => 430,
        ]);

        Storage::disk('public')->put('photos/body.jpg', 'jpeg-bytes');
        DB::table('progress_photos')->insert([
            'profile_id' => $profile->id,
            'photo_path' => 'photos/body.jpg',
            'taken_at' => '2026-11-12',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/me/account', ['password' => 's3cret-pass'])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        // The record itself, and the 48 cascading foreign keys behind it.
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('sleep_logs', ['profile_id' => $profile->id]);

        // The bytes the database cannot reach. A photograph of somebody's body outliving their
        // account is precisely what 5.1.1(v) is about.
        Storage::disk('public')->assertMissing('photos/body.jpg');

        // And the token must stop working, or a stolen phone keeps reading a deleted account.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')->assertUnauthorized();
    }

    public function test_the_wrong_password_deletes_nothing(): void
    {
        [$user, $token] = $this->signedIn();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/me/account', ['password' => 'not-my-password'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);

        // Still usable afterwards — a failed attempt must not half-close the account.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')->assertOk();
    }

    public function test_deletion_needs_a_password_at_all(): void
    {
        [$user, $token] = $this->signedIn();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/me/account', [])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_a_stranger_cannot_delete_an_account(): void
    {
        [$user] = $this->signedIn();

        $this->deleteJson('/api/me/account', ['password' => 's3cret-pass'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /** @return array{0: User, 1: string} */
    private function signedIn(): array
    {
        $user = User::factory()->create(['password' => Hash::make('s3cret-pass')]);
        $user->ensureProfile();

        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 's3cret-pass',
        ])->json('token');

        return [$user->fresh(), $token];
    }
}
