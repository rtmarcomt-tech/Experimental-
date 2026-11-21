<?php

use App\Http\Controllers\Api\AdminCreatorVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CreatorApplicationController;
use App\Http\Controllers\Api\CreatorMediaController;
use App\Http\Controllers\Api\CreatorMediaPublicationController;
use App\Http\Controllers\Api\CreatorVerificationDocumentController;
use App\Http\Controllers\Api\OrderCheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'application' => 'digital-creator-marketplace',
    'version' => '0.6.0',
]));

Route::get('/media', [CreatorMediaPublicationController::class, 'publicIndex']);
Route::get('/media/{slug}/preview', [CreatorMediaPublicationController::class, 'publicPreview']);

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/creator/application', [CreatorApplicationController::class, 'show']);
    Route::post('/creator/application', [CreatorApplicationController::class, 'submit']);
    Route::post('/creator/application/documents', [CreatorVerificationDocumentController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('/creator/media', [CreatorMediaController::class, 'index']);
    Route::post('/creator/media', [CreatorMediaController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/creator/media/{media}', [CreatorMediaController::class, 'show']);
    Route::patch('/creator/media/{media}', [CreatorMediaController::class, 'update']);
    Route::post('/creator/media/{media}/rights', [CreatorMediaPublicationController::class, 'declareRights']);
    Route::post('/creator/media/{media}/preview', [CreatorMediaPublicationController::class, 'uploadPreview']);
    Route::post('/creator/media/{media}/publish', [CreatorMediaPublicationController::class, 'publish']);
    Route::post('/media/{slug}/checkout', [OrderCheckoutController::class, 'create'])->middleware('throttle:10,1');
    Route::post('/orders/{reference}/capture', [OrderCheckoutController::class, 'capture'])->middleware('throttle:10,1');
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('/creator-verification-cases', [AdminCreatorVerificationController::class, 'index']);
        Route::get('/creator-verification-documents/{document}', [AdminCreatorVerificationController::class, 'downloadDocument']);
        Route::patch('/creator-verification-cases/{case}', [AdminCreatorVerificationController::class, 'decide']);
    });
