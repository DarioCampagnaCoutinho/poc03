<?php

use App\Http\Controllers\Api\HelloWorldController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloWorldController::class);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
