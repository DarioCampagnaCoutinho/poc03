<?php

use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

// Rotas públicas

Route::get('/health', HealthCheckController::class);

// Até 10 tentativas de login por minuto por IP (proteção contra força bruta).
// Não há cadastro público: os usuários são criados pelo administrador.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Rotas autenticadas (header Authorization: Bearer <token>).
// Tarefas e administração também exigem permissão (ver o método middleware() de cada controller).

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('tasks', TaskController::class)
        ->where(['task' => '[0-9]+']);
    Route::post('/tasks/{task}/restore', [TaskController::class, 'restore'])
        ->whereNumber('task')
        ->withTrashed();

    // Administração: usuários (users.manage), grupos (roles.manage) e permissões.
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::apiResource('users', UserController::class)
            ->where(['user' => '[0-9]+']);
        Route::put('/users/{user}/roles', [UserController::class, 'syncRoles'])
            ->whereNumber('user')
            ->name('users.roles');
        Route::put('/users/{user}/permissions', [UserController::class, 'syncPermissions'])
            ->whereNumber('user')
            ->name('users.permissions');

        Route::apiResource('roles', RoleController::class)
            ->where(['role' => '[0-9]+']);

        Route::get('/permissions', PermissionController::class)->name('permissions.index');
    });
});
