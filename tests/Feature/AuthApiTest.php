<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
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
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'roles', 'permissions'], 'token'])
            ->assertJsonMissingPath('data.password');

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

    public function test_me_returns_roles_and_effective_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reader = User::factory()->create()->assignRole('leitor');
        $this->withToken($reader->createToken('api')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['leitor'])
            ->assertJsonPath('data.permissions', ['tasks.view']);

        // O super-admin não tem permissões atribuídas, mas todas são efetivas para ele.
        $admin = User::factory()->create()->assignRole(User::SUPER_ADMIN);
        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [User::SUPER_ADMIN])
            ->assertJsonPath('data.permissions', ['tasks.create', 'tasks.delete', 'tasks.restore', 'tasks.update', 'tasks.view']);
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
