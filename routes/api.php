<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TodoSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'active', 'throttle:sync'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout/all', [AuthController::class, 'logoutAll']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/sync/push', [TodoSyncController::class, 'push']);
    Route::get('/sync/pull', [TodoSyncController::class, 'pull']);
});
