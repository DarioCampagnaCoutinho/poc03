<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'description', 'status'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TaskStatus::Pending->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
        ];
    }

    /**
     * Mantém o status "excluído" sincronizado com o soft delete:
     * a tarefa tem status Deleted se, e somente se, deleted_at estiver preenchido.
     */
    protected static function booted(): void
    {
        // Status alterado manualmente: entra ou sai da lixeira conforme o novo status.
        static::saving(function (Task $task) {
            if (! $task->isDirty('status')) {
                return;
            }

            $deleted = $task->status === TaskStatus::Deleted;

            if ($deleted !== $task->trashed()) {
                $task->{$task->getDeletedAtColumn()} = $deleted ? $task->freshTimestamp() : null;
            }
        });

        // $task->delete(): marca o status como excluído.
        static::softDeleted(function (Task $task) {
            $task->status = TaskStatus::Deleted;
            $task->saveQuietly();
        });

        // $task->restore(): a tarefa excluída volta como pendente.
        static::restoring(function (Task $task) {
            if ($task->trashed()) {
                $task->status = TaskStatus::Pending;
            }
        });
    }
}
