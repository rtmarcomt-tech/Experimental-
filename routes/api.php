<?php

use App\Http\Controllers\Api\AdminCreatorVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CreatorApplicationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'application' => 'digital-creator-marketplace',
    'version' => '0.2.0',
]));

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/creator/application', [CreatorApplicationController::class, 'show']);
    Route::post('/creator/application', [CreatorApplicationController::class, 'submit']);
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('/creator-verification-cases', [AdminCreatorVerificationController::class, 'index']);
        Route::patch('/creator-verification-cases/{case}', [AdminCreatorVerificationController::class, 'decide']);
    });
