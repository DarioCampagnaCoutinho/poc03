<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create()->assignRole(User::SUPER_ADMIN);
        Sanctum::actingAs($this->admin);
    }

    #[DataProvider('userRoutes')]
    public function test_routes_require_users_manage_permission(string $method, string $uri): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->create()->assignRole('editor'));

        $this->json($method, str_replace('{id}', $user->id, $uri), ['name' => 'X', 'roles' => [], 'permissions' => []])
            ->assertForbidden();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function userRoutes(): array
    {
        return [
            'listar' => ['GET', '/api/admin/users'],
            'criar' => ['POST', '/api/admin/users'],
            'consultar' => ['GET', '/api/admin/users/{id}'],
            'atualizar' => ['PATCH', '/api/admin/users/{id}'],
            'excluir' => ['DELETE', '/api/admin/users/{id}'],
            'grupos' => ['PUT', '/api/admin/users/{id}/roles'],
            'permissões' => ['PUT', '/api/admin/users/{id}/permissions'],
        ];
    }

    public function test_admin_lists_users_and_filters_by_role(): void
    {
        User::factory()->count(2)->create()->each->assignRole('leitor');

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'roles', 'permissions', 'direct_permissions']]]);

        $this->getJson('/api/admin/users?role=leitor')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.roles', ['leitor']);

        $this->getJson('/api/admin/users?role=inexistente')->assertUnprocessable();
    }

    public function test_admin_creates_user_with_roles_and_permissions(): void
    {
        $this->postJson('/api/admin/users', [
            'name' => 'João',
            'email' => 'joao@example.com',
            'password' => 'password',
            'roles' => ['leitor'],
            'permissions' => ['tasks.create'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'joao@example.com')
            ->assertJsonPath('data.roles', ['leitor'])
            ->assertJsonPath('data.direct_permissions', ['tasks.create'])
            ->assertJsonPath('data.permissions', ['tasks.create', 'tasks.view'])
            ->assertJsonMissingPath('data.password');

        $this->assertTrue(Hash::check('password', User::firstWhere('email', 'joao@example.com')->password));
    }

    public function test_create_validates_input(): void
    {
        User::factory()->create(['email' => 'joao@example.com']);

        $this->postJson('/api/admin/users', [
            'email' => 'joao@example.com',
            'password' => '123',
            'roles' => ['inexistente'],
            'permissions' => ['tasks.voar'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'roles.0', 'permissions.0']);
    }

    public function test_admin_updates_user(): void
    {
        $user = User::factory()->create();

        $this->patchJson("/api/admin/users/{$user->id}", ['name' => 'Novo nome', 'password' => 'nova-senha-123'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Novo nome');

        $this->assertTrue(Hash::check('nova-senha-123', $user->fresh()->password));
    }

    public function test_admin_syncs_roles_and_direct_permissions(): void
    {
        $user = User::factory()->create()->assignRole('leitor');

        $this->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['editor']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['editor']);

        $this->putJson("/api/admin/users/{$user->id}/permissions", ['permissions' => ['users.manage']])
            ->assertOk()
            ->assertJsonPath('data.direct_permissions', ['users.manage']);

        $this->putJson("/api/admin/users/{$user->id}/roles", ['roles' => []])
            ->assertOk()
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', ['users.manage']);
    }

    public function test_admin_deletes_user_and_revokes_tokens(): void
    {
        $user = User::factory()->create()->assignRole('leitor');
        $user->createToken('api');

        $this->deleteJson("/api/admin/users/{$user->id}")->assertNoContent();

        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $this->deleteJson("/api/admin/users/{$this->admin->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertModelExists($this->admin);
    }

    public function test_admin_cannot_remove_own_super_admin_role(): void
    {
        $this->putJson("/api/admin/users/{$this->admin->id}/roles", ['roles' => ['editor']])
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot remove your own super-admin role.');

        $this->assertTrue($this->admin->fresh()->hasRole(User::SUPER_ADMIN));
    }

    public function test_super_admin_can_grant_super_admin_role(): void
    {
        $user = User::factory()->create();

        $this->putJson("/api/admin/users/{$user->id}/roles", ['roles' => [User::SUPER_ADMIN]])->assertOk();

        $this->assertTrue($user->fresh()->hasRole(User::SUPER_ADMIN));
    }

    public function test_user_manager_cannot_grant_or_touch_super_admin(): void
    {
        $manager = User::factory()->create()->givePermissionTo('users.manage');
        $regular = User::factory()->create();
        Sanctum::actingAs($manager);

        // Pode administrar usuários comuns...
        $this->patchJson("/api/admin/users/{$regular->id}", ['name' => 'Renomeado'])->assertOk();

        // ...mas não pode dar o papel super-admin, nem a si mesmo
        $this->putJson("/api/admin/users/{$regular->id}/roles", ['roles' => [User::SUPER_ADMIN]])->assertForbidden();
        $this->putJson("/api/admin/users/{$manager->id}/roles", ['roles' => [User::SUPER_ADMIN]])->assertForbidden();
        $this->postJson('/api/admin/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'roles' => [User::SUPER_ADMIN],
        ])->assertForbidden();

        // ...e não pode alterar, excluir ou mudar os acessos de um super-admin
        $this->patchJson("/api/admin/users/{$this->admin->id}", ['name' => 'Hack'])->assertForbidden();
        $this->putJson("/api/admin/users/{$this->admin->id}/roles", ['roles' => []])->assertForbidden();
        $this->putJson("/api/admin/users/{$this->admin->id}/permissions", ['permissions' => []])->assertForbidden();
        $this->deleteJson("/api/admin/users/{$this->admin->id}")->assertForbidden();

        $this->assertTrue($this->admin->fresh()->hasRole(User::SUPER_ADMIN));
    }
}
