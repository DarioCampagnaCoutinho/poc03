<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    #[DataProvider('taskOperations')]
    public function test_user_without_permission_is_forbidden(string $method, string $uri): void
    {
        $task = Task::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->json($method, str_replace('{id}', $task->id, $uri), ['title' => 'Nova'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function taskOperations(): array
    {
        return [
            'listar' => ['GET', '/api/tasks'],
            'consultar' => ['GET', '/api/tasks/{id}'],
            'criar' => ['POST', '/api/tasks'],
            'atualizar' => ['PATCH', '/api/tasks/{id}'],
            'excluir' => ['DELETE', '/api/tasks/{id}'],
            'restaurar' => ['POST', '/api/tasks/{id}/restore'],
        ];
    }

    public function test_reader_can_only_view_tasks(): void
    {
        $task = Task::factory()->create();
        Sanctum::actingAs(User::factory()->create()->assignRole('leitor'));

        $this->getJson('/api/tasks')->assertOk();
        $this->getJson('/api/tasks?status=deleted')->assertOk();
        $this->getJson("/api/tasks/{$task->id}")->assertOk();

        $this->postJson('/api/tasks', ['title' => 'Nova'])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'completed'])->assertForbidden();
        $this->deleteJson("/api/tasks/{$task->id}")->assertForbidden();
        $this->postJson("/api/tasks/{$task->id}/restore")->assertForbidden();

        $this->assertNotSoftDeleted($task);
    }

    public function test_permission_can_be_granted_directly_to_user(): void
    {
        Sanctum::actingAs(User::factory()->create()->givePermissionTo('tasks.create'));

        $this->postJson('/api/tasks', ['title' => 'Nova'])->assertCreated();
        $this->getJson('/api/tasks')->assertForbidden();
    }

    public function test_super_admin_can_do_everything_without_assigned_permissions(): void
    {
        $task = Task::factory()->create();
        $admin = User::factory()->create()->assignRole(User::SUPER_ADMIN);
        Sanctum::actingAs($admin);

        $this->assertCount(0, $admin->getAllPermissions());

        $this->getJson('/api/tasks')->assertOk();
        $this->postJson('/api/tasks', ['title' => 'Nova'])->assertCreated();
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'completed'])->assertOk();
        $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();
        $this->postJson("/api/tasks/{$task->id}/restore")->assertOk();
    }
}
