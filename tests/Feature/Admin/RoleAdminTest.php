<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs(User::factory()->create()->assignRole(User::SUPER_ADMIN));
    }

    #[DataProvider('roleRoutes')]
    public function test_routes_require_roles_manage_permission(string $method, string $uri): void
    {
        $role = Role::findByName('leitor');
        Sanctum::actingAs(User::factory()->create()->givePermissionTo('users.manage'));

        $this->json($method, str_replace('{id}', $role->id, $uri), ['name' => 'novo'])->assertForbidden();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function roleRoutes(): array
    {
        return [
            'listar' => ['GET', '/api/admin/roles'],
            'criar' => ['POST', '/api/admin/roles'],
            'consultar' => ['GET', '/api/admin/roles/{id}'],
            'atualizar' => ['PATCH', '/api/admin/roles/{id}'],
            'excluir' => ['DELETE', '/api/admin/roles/{id}'],
        ];
    }

    public function test_admin_lists_roles_with_permissions_and_member_count(): void
    {
        User::factory()->count(2)->create()->each->assignRole('leitor');

        $roles = collect($this->getJson('/api/admin/roles')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['tasks.view'], $roles['leitor']['permissions']);
        $this->assertSame(2, $roles['leitor']['users_count']);
        $this->assertCount(5, $roles['editor']['permissions']);
        $this->assertCount(7, $roles[User::SUPER_ADMIN]['permissions']);
    }

    public function test_admin_creates_role_and_members_get_its_permissions(): void
    {
        $this->postJson('/api/admin/roles', ['name' => 'revisor', 'permissions' => ['tasks.view', 'tasks.update']])
            ->assertCreated()
            ->assertJsonPath('data.name', 'revisor')
            ->assertJsonPath('data.permissions', ['tasks.update', 'tasks.view'])
            ->assertJsonPath('data.users_count', 0);

        $member = User::factory()->create()->assignRole('revisor');

        $this->assertTrue($member->can('tasks.update'));
        $this->assertFalse($member->can('tasks.delete'));
    }

    public function test_create_validates_input(): void
    {
        $this->postJson('/api/admin/roles', ['name' => 'Equipe Financeira', 'permissions' => ['tasks.voar']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'permissions.0']);

        $this->postJson('/api/admin/roles', ['name' => 'leitor'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_updates_role_name_and_permissions(): void
    {
        $role = Role::create(['name' => 'revisor']);
        $member = User::factory()->create()->assignRole($role);

        $this->patchJson("/api/admin/roles/{$role->id}", ['name' => 'revisor-senior', 'permissions' => ['tasks.view', 'tasks.delete']])
            ->assertOk()
            ->assertJsonPath('data.name', 'revisor-senior')
            ->assertJsonPath('data.permissions', ['tasks.delete', 'tasks.view'])
            ->assertJsonPath('data.users_count', 1);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($member->fresh()->can('tasks.delete'));
    }

    public function test_admin_deletes_role_and_members_lose_its_permissions(): void
    {
        $role = Role::create(['name' => 'revisor'])->givePermissionTo('tasks.update');
        $member = User::factory()->create()->assignRole($role);

        $this->deleteJson("/api/admin/roles/{$role->id}")->assertNoContent();

        $this->assertModelMissing($role);
        $this->assertFalse($member->fresh()->can('tasks.update'));
    }

    public function test_super_admin_role_cannot_be_changed_or_deleted(): void
    {
        $role = Role::findByName(User::SUPER_ADMIN);

        $this->patchJson("/api/admin/roles/{$role->id}", ['name' => 'chefe'])
            ->assertForbidden()
            ->assertJsonPath('message', 'The super-admin role cannot be changed or deleted.');
        $this->deleteJson("/api/admin/roles/{$role->id}")->assertForbidden();

        $this->assertModelExists($role);
    }

    public function test_permissions_list_is_available_to_any_admin(): void
    {
        $all = ['roles.manage', 'tasks.create', 'tasks.delete', 'tasks.restore', 'tasks.update', 'tasks.view', 'users.manage'];

        $this->getJson('/api/admin/permissions')->assertOk()->assertJsonPath('data', $all);

        Sanctum::actingAs(User::factory()->create()->givePermissionTo('users.manage'));
        $this->getJson('/api/admin/permissions')->assertOk();

        Sanctum::actingAs(User::factory()->create()->givePermissionTo('roles.manage'));
        $this->getJson('/api/admin/permissions')->assertOk();

        Sanctum::actingAs(User::factory()->create()->assignRole('editor'));
        $this->getJson('/api/admin/permissions')->assertForbidden();
    }
}
