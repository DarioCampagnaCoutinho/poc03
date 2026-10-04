<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // "editor" tem todas as permissões de tarefas; as regras de autorização ficam em TaskAuthorizationTest.
        Sanctum::actingAs(User::factory()->create()->assignRole('editor'));
    }

    #[DataProvider('taskRoutes')]
    public function test_routes_require_authentication(string $method, string $uri): void
    {
        $this->app['auth']->forgetGuards();

        $this->json($method, $uri)->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function taskRoutes(): array
    {
        return [
            'listar' => ['GET', '/api/tasks'],
            'criar' => ['POST', '/api/tasks'],
            'exibir' => ['GET', '/api/tasks/1'],
            'atualizar' => ['PATCH', '/api/tasks/1'],
            'excluir' => ['DELETE', '/api/tasks/1'],
            'restaurar' => ['POST', '/api/tasks/1/restore'],
        ];
    }

    public function test_index_lists_active_tasks_paginated(): void
    {
        Task::factory()->count(3)->create();
        Task::factory()->create()->delete();

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure([
                'data' => [['id', 'title', 'description', 'status', 'status_label', 'created_at', 'updated_at', 'deleted_at']],
                'links',
                'meta',
            ]);
    }

    public function test_index_filters_by_status(): void
    {
        Task::factory()->create(['status' => TaskStatus::Completed]);
        Task::factory()->count(2)->create();

        $this->getJson('/api/tasks?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_index_lists_deleted_tasks(): void
    {
        Task::factory()->create();
        Task::factory()->create()->delete();

        $this->getJson('/api/tasks?status=deleted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'deleted');
    }

    public function test_index_rejects_invalid_filters(): void
    {
        $this->getJson('/api/tasks?status=archived&per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'per_page']);
    }

    public function test_store_creates_pending_task(): void
    {
        $this->postJson('/api/tasks', ['title' => 'Nova tarefa'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Nova tarefa')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Pendente');

        $this->assertDatabaseHas('tasks', ['title' => 'Nova tarefa', 'status' => 'pending']);
    }

    public function test_store_validates_input(): void
    {
        $this->postJson('/api/tasks', ['description' => 123, 'status' => 'deleted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'status']);
    }

    public function test_show_returns_task(): void
    {
        $task = Task::factory()->create();

        $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $task->id)
            ->assertJsonPath('data.title', $task->title);
    }

    public function test_show_returns_not_found_for_missing_or_deleted_task(): void
    {
        $task = Task::factory()->create();
        $task->delete();

        $this->getJson("/api/tasks/{$task->id}")->assertNotFound();
        $this->getJson('/api/tasks/999')->assertNotFound();
        $this->getJson('/api/tasks/abc')->assertNotFound();
    }

    public function test_update_changes_only_sent_fields(): void
    {
        $task = Task::factory()->create(['title' => 'Original', 'description' => 'Descrição']);

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Original')
            ->assertJsonPath('data.description', 'Descrição')
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.status_label', 'Em andamento');
    }

    public function test_update_rejects_deleted_status(): void
    {
        $task = Task::factory()->create();

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'deleted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertNotSoftDeleted($task);
    }

    public function test_destroy_soft_deletes_task(): void
    {
        $task = Task::factory()->create();

        $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

        $this->assertSoftDeleted($task);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'deleted']);
    }

    public function test_restore_brings_back_deleted_task_as_pending(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::InProgress]);
        $task->delete();

        $this->postJson("/api/tasks/{$task->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.deleted_at', null);

        $this->assertNotSoftDeleted($task);
    }

    public function test_restore_keeps_status_of_active_task(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::InProgress]);

        $this->postJson("/api/tasks/{$task->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }
}
