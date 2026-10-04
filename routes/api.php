<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\HelloWorldController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

// Rotas públicas

Route::get('/hello', HelloWorldController::class);

Route::get('/health', HealthCheckController::class);

// Até 10 tentativas por minuto por IP (proteção contra força bruta).
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

// Rotas autenticadas (header Authorization: Bearer <token>)

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('tasks', TaskController::class)
        ->where(['task' => '[0-9]+']);
    Route::post('/tasks/{task}/restore', [TaskController::class, 'restore'])
        ->whereNumber('task')
        ->withTrashed();
});
