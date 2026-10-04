<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Maria')
            ->assertJsonPath('data.email', 'maria@example.com')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'], 'token'])
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'maria@example.com');

        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame(['api'], $user->tokens()->pluck('name')->all());
    }

    public function test_register_validates_input(): void
    {
        User::factory()->create(['email' => 'maria@example.com']);

        $this->postJson('/api/auth/register', [
            'email' => 'maria@example.com',
            'password' => 'password',
            'password_confirmation' => 'outra-senha',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'iPhone da Maria',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['data', 'token']);

        $this->assertSame(['iPhone da Maria'], $user->tokens()->pluck('name')->all());
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'senha-errada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/auth/login', ['email' => 'nao-existe@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited(): void
    {
        $credentials = ['email' => 'maria@example.com', 'password' => 'senha-errada'];

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/auth/login', $credentials)->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', $credentials)->assertTooManyRequests();
    }

    public function test_token_authenticates_requests(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_requests_without_token_are_unauthorized(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();

        // Sem "Accept: application/json" também deve ser 401, e não um redirecionamento para login.
        $this->get('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $user->createToken('outro dispositivo');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertSame(['outro dispositivo'], $user->tokens()->pluck('name')->all());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
