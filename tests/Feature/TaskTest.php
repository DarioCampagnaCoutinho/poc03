<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_is_created_as_pending_with_timestamps(): void
    {
        $task = Task::create(['title' => 'Estudar Laravel']);

        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertNull($task->description);
        $this->assertNotNull($task->created_at);
        $this->assertNotNull($task->updated_at);
        $this->assertNull($task->deleted_at);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'pending']);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $task = Task::factory()->create(['status' => 'in_progress']);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertSame('Em andamento', $task->status->label());
    }

    public function test_updated_at_changes_on_update(): void
    {
        $task = Task::factory()->create();
        $previousUpdatedAt = $task->updated_at;

        $this->travel(5)->minutes();
        $task->update(['status' => TaskStatus::Completed]);

        $this->assertTrue($task->fresh()->updated_at->greaterThan($previousUpdatedAt));
    }

    public function test_database_rejects_invalid_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('tasks')->insert(['title' => 'Inválida', 'status' => 'archived']);
    }

    public function test_deleting_task_marks_it_as_deleted(): void
    {
        $task = Task::factory()->create();

        $task->delete();

        $this->assertSoftDeleted($task);
        $this->assertSame(TaskStatus::Deleted, Task::withTrashed()->find($task->id)->status);
    }

    public function test_restoring_task_sets_it_back_to_pending(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::InProgress]);
        $task->delete();

        $task->restore();

        $this->assertNotSoftDeleted($task);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    public function test_restoring_active_task_keeps_its_status(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::InProgress]);

        $task->restore();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_setting_status_to_deleted_soft_deletes_task(): void
    {
        $task = Task::factory()->create();

        $task->update(['status' => TaskStatus::Deleted]);

        $this->assertSoftDeleted($task);
    }

    public function test_changing_status_of_deleted_task_restores_it(): void
    {
        $task = Task::factory()->create();
        $task->delete();

        $task->update(['status' => TaskStatus::InProgress]);

        $this->assertNotSoftDeleted($task);
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }
}
