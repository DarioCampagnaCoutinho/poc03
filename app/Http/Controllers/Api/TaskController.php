<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /**
     * Lista as tarefas, paginadas e da mais recente para a mais antiga.
     * Filtro opcional por status; status=deleted lista as tarefas excluídas.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $status = $request->enum('status', TaskStatus::class);
        $query = Task::query();

        if ($status === TaskStatus::Deleted) {
            $query->onlyTrashed();
        } elseif ($status) {
            $query->where('status', $status);
        }

        $tasks = $query->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    /**
     * Cria uma tarefa.
     */
    public function store(StoreTaskRequest $request): TaskResource
    {
        return new TaskResource(Task::create($request->validated()));
    }

    /**
     * Exibe uma tarefa.
     */
    public function show(Task $task): TaskResource
    {
        return new TaskResource($task);
    }

    /**
     * Atualiza uma tarefa (apenas os campos enviados).
     */
    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $task->update($request->validated());

        return new TaskResource($task);
    }

    /**
     * Exclui uma tarefa (soft delete: status passa a ser "excluído").
     */
    public function destroy(Task $task): Response
    {
        $task->delete();

        return response()->noContent();
    }

    /**
     * Restaura uma tarefa excluída (volta como "pendente").
     */
    public function restore(Task $task): TaskResource
    {
        $task->restore();

        return new TaskResource($task);
    }
}
