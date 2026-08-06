<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\User;
use App\Infrastructure\Persistence\Eloquent\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_the_users_saved_locale_preference(): void
    {
        $user = User::factory()->create();
        UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['locale' => 'pt_BR']);

        $this->actingAs($user)
            ->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.preferences.locale', 'pt_BR');
    }

    public function test_me_defaults_locale_to_english_without_a_saved_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.preferences.locale', 'en');
    }

    public function test_user_can_update_locale_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/v1/me/preferences', ['locale' => 'pt_BR'])
            ->assertOk()
            ->assertJsonPath('data.preferences.locale', 'pt_BR');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'locale' => 'pt_BR',
        ]);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/v1/me/preferences', ['locale' => 'fr'])
            ->assertStatus(422);
    }
}
