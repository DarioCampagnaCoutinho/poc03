<?php

use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\HelloWorldController;
use App\Http\Controllers\Api\TaskController;
use App\Services\HealthCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloWorldController::class);

Route::get('/health', [HealthCheckController::class, 'index']);
Route::get('/health/{service}', [HealthCheckController::class, 'show'])
    ->whereIn('service', HealthCheckService::SERVICES);

Route::apiResource('tasks', TaskController::class)
    ->where(['task' => '[0-9]+']);
Route::post('/tasks/{task}/restore', [TaskController::class, 'restore'])
    ->whereNumber('task')
    ->withTrashed();

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
