<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaseController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

// Public Auth Routes
Route::prefix('auth')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/login', [AuthController::class, 'login']);
});

// QBO Callback (Handles Intuit redirect, uses state nonce for security, no Auth required)
Route::get('integrations/qbo/callback', [IntegrationController::class, 'qboCallback']);

Route::middleware('auth:sanctum')->group(function () {
    // Protected Auth Routes
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/complete-onboarding', [AuthController::class, 'completeOnboarding']);
    });

    // Integrations
    Route::prefix('integrations')->group(function () {
        Route::get('/status', [IntegrationController::class, 'status']);
        Route::get('/qbo/connect', [IntegrationController::class, 'qboConnect']);
        Route::post('/qbo/sync', [IntegrationController::class, 'qboSync']);
        Route::delete('/qbo/disconnect/{realmId}', [IntegrationController::class, 'qboDisconnect']);
        Route::delete('/qbo/purge/{realmId}', [IntegrationController::class, 'qboPurge']);
        Route::post('/qbo/credentials', [IntegrationController::class, 'qboSaveCredentials']);
        Route::delete('/qbo/credentials', [IntegrationController::class, 'qboRemoveCredentials']);
    });

    // Chat
    Route::prefix('chat')->group(function () {
        Route::get('/usage', [ChatController::class, 'usage']);
        Route::get('/sessions', [ChatController::class, 'index']);
        Route::post('/sessions', [ChatController::class, 'store']);
        Route::get('/sessions/{sessionId}', [ChatController::class, 'show']);
        Route::delete('/sessions/{sessionId}', [ChatController::class, 'destroy']);
        Route::post('/sessions/{sessionId}/messages', [ChatController::class, 'sendMessage']);
        Route::get('/sessions/{sessionId}/workspace', [ChatController::class, 'workspace']);
        Route::post('/sessions/{sessionId}/actions/{actionId}/confirm', [ChatController::class, 'confirmAction']);
        Route::post('/sessions/{sessionId}/actions/{actionId}/reject', [ChatController::class, 'rejectAction']);
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/summary', [AnalyticsController::class, 'summary']);
        Route::get('/vendors', [AnalyticsController::class, 'vendors']);
        Route::get('/cash-flow', [AnalyticsController::class, 'cashFlow']);
    });

    // Uploads
    Route::prefix('uploads')->group(function () {
        Route::get('/', [UploadController::class, 'index']);
        Route::post('/', [UploadController::class, 'store']);
        Route::get('/{id}', [UploadController::class, 'show']);
        Route::post('/{id}/complete', [UploadController::class, 'complete']);
        Route::get('/{id}/preview', [UploadController::class, 'preview']);
        Route::post('/{id}/mappings', [UploadController::class, 'mappings']);
        Route::post('/{id}/validate', [UploadController::class, 'validateUpload']);
        Route::post('/{id}/promote', [UploadController::class, 'promote']);
        Route::delete('/{id}', [UploadController::class, 'destroy']);
    });

    // Cases
    Route::prefix('cases')->group(function () {
        Route::get('/', [CaseController::class, 'index']);
        Route::post('/', [CaseController::class, 'store']);
        Route::get('/{id}', [CaseController::class, 'show']);
        Route::patch('/{id}', [CaseController::class, 'update']);
        Route::get('/{id}/summary', [CaseController::class, 'summary']);
        Route::post('/{id}/events', [CaseController::class, 'addEvent']);
        Route::post('/{id}/alerts', [CaseController::class, 'linkAlert']);
        Route::delete('/{id}/alerts/{alertId}', [CaseController::class, 'unlinkAlert']);
    });

    // Alerts
    Route::prefix('alerts')->group(function () {
        Route::get('/', [AlertController::class, 'index']);
        Route::get('/rules', [AlertController::class, 'rules']);
        Route::get('/groups', [AlertController::class, 'groups']);
        Route::get('/{id}', [AlertController::class, 'show']);
        Route::patch('/{id}', [AlertController::class, 'update']);
    });

    // Transactions
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/{id}', [TransactionController::class, 'show']);
        Route::patch('/{id}/review', [TransactionController::class, 'review']);
    });

    // Reconciliation
    Route::prefix('reconciliation')->group(function () {
        Route::get('/summary', [ReconciliationController::class, 'getSummary']);
        Route::get('/discrepancies', [ReconciliationController::class, 'getDiscrepancies']);
        Route::get('/discrepancies/{id}', [ReconciliationController::class, 'getDiscrepancy']);
        Route::patch('/discrepancies/{id}/status', [ReconciliationController::class, 'updateStatus']);
        Route::post('/discrepancies/{id}/confirm-action', [ReconciliationController::class, 'confirmAction']);
        Route::post('/discrepancies/{id}/notes', [ReconciliationController::class, 'addNote']);
    });
});
