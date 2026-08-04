<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_login_establishes_a_session_when_grandpasson_is_enabled(): void
    {
        config()->set('grandpasson.inbound_enabled', true);

        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->withSession(['_token' => 'test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/v1/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.email', $user->email);

        $this->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id);
    }

    public function test_local_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->withSession(['_token' => 'test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_local_logout_invalidates_the_session(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->withSession(['_token' => 'test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/v1/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertOk();

        $this->postJson('/v1/auth/logout')
            ->assertNoContent();

        $this->app['auth']->forgetGuards();

        $this->getJson('/v1/me')
            ->assertUnauthorized();
    }
}
