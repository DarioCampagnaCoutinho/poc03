<?php

use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\HelloWorldController;
use App\Services\HealthCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloWorldController::class);

Route::get('/health', [HealthCheckController::class, 'index']);
Route::get('/health/{service}', [HealthCheckController::class, 'show'])
    ->whereIn('service', HealthCheckService::SERVICES);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
