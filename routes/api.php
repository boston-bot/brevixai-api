<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AlertRecommendationController;
use App\Http\Controllers\Api\InvestigationController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ArAgingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaseController;
use App\Http\Controllers\Api\CaseRecommendationController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ControlsController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GnuCashController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Chat\AgentChatController;
use App\Http\Controllers\Internal\AgentToolController;
use Illuminate\Support\Facades\Route;

// Public Auth Routes
Route::prefix('auth')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/login', [AuthController::class, 'login']);
});

// QBO Callback (Handles Intuit redirect, uses state nonce for security, no Auth required)
Route::get('integrations/qbo/callback', [IntegrationController::class, 'qboCallback']);

Route::prefix('internal/agent-tools')
    ->middleware('agent.tool')
    ->group(function () {
        Route::get('/companies/{companyId}/context', [AgentToolController::class, 'companyContext']);
        Route::get('/companies/{companyId}/risk-summary', [AgentToolController::class, 'riskSummary']);
        Route::get('/company/{companyId}/vendor-risk', [AgentToolController::class, 'vendorRisk']);
        Route::get('/company/{companyId}/reconciliation-risk', [AgentToolController::class, 'reconciliationRisk']);
        Route::get('/company/{companyId}/entity-relationship-risk', [AgentToolController::class, 'entityRelationshipRisk']);
        Route::get('/company/{companyId}/aggregate-risk-summary', [AgentToolController::class, 'aggregateRiskSummary']);
        Route::get('/company/{companyId}/alert-recommendations', [AgentToolController::class, 'alertRecommendations']);
        Route::get('/company/{companyId}/case-recommendations', [AgentToolController::class, 'caseRecommendations']);
    });

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

        // GnuCash Integration
        Route::prefix('gnucash')->group(function () {
            Route::get('status', [GnuCashController::class, 'status']);
            Route::post('upload', [GnuCashController::class, 'upload']);
            Route::delete('purge', [GnuCashController::class, 'purge']);
        });
    });

    // Chat
    Route::prefix('chat')->group(function () {
        Route::post('/agent/messages', [AgentChatController::class, 'store']);
        Route::get('/stream', [ChatController::class, 'stream']);
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

    // Accounting
    Route::prefix('accounting')->group(function () {
        Route::get('/tax-estimate', [AccountingController::class, 'taxEstimate']);
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary']);
    });

    // AR Aging
    Route::prefix('ar-aging')->group(function () {
        Route::get('/summary', [ArAgingController::class, 'summary']);
        Route::get('/customers', [ArAgingController::class, 'customers']);
        Route::get('/invoices', [ArAgingController::class, 'invoices']);
        Route::get('/write-off-candidates', [ArAgingController::class, 'writeOffCandidates']);
        Route::patch('/invoices/{id}', [ArAgingController::class, 'updateInvoice']);
        Route::post('/invoices/{id}/write-off', [ArAgingController::class, 'writeOff']);
    });

    // Controls
    Route::prefix('controls')->group(function () {
        Route::get('/', [ControlsController::class, 'index']);
        Route::get('/health', [ControlsController::class, 'health']);
        Route::get('/violations', [ControlsController::class, 'violations']);
        Route::post('/evaluate', [ControlsController::class, 'evaluate']);
        Route::patch('/violations/{id}', [ControlsController::class, 'updateViolation']);
        Route::patch('/{id}', [ControlsController::class, 'update']);
    });

    // Subscriptions
    Route::prefix('subscriptions')->group(function () {
        Route::get('/', [SubscriptionController::class, 'show']);
        Route::post('/checkout', [SubscriptionController::class, 'checkout']);
        Route::post('/cancel', [SubscriptionController::class, 'cancel']);
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

    // Investigations
    Route::prefix('investigations')->group(function () {
        Route::get('/', [InvestigationController::class, 'index']);
        Route::get('/{id}', [InvestigationController::class, 'show']);
        Route::post('/{id}/assign', [InvestigationController::class, 'assign']);
        Route::post('/{id}/status', [InvestigationController::class, 'status']);
        Route::post('/{id}/notes', [InvestigationController::class, 'notes']);
        Route::get('/{id}/evidence', [InvestigationController::class, 'listEvidence']);
        Route::post('/{id}/evidence', [InvestigationController::class, 'addEvidence']);
        Route::delete('/{id}/evidence/{evidenceItemId}', [InvestigationController::class, 'removeEvidence']);
        Route::post('/{id}/reports', [InvestigationController::class, 'generateReport']);
    });

    // Case Recommendations
    Route::prefix('case-recommendations')->group(function () {
        Route::get('/', [CaseRecommendationController::class, 'index']);
        Route::get('/{id}', [CaseRecommendationController::class, 'show']);
        Route::post('/{id}/approve', [CaseRecommendationController::class, 'approve']);
        Route::post('/{id}/dismiss', [CaseRecommendationController::class, 'dismiss']);
    });

    // Alerts
    Route::prefix('alert-recommendations')->group(function () {
        Route::get('/', [AlertRecommendationController::class, 'index']);
        Route::get('/{id}', [AlertRecommendationController::class, 'show']);
        Route::post('/{id}/approve', [AlertRecommendationController::class, 'approve']);
        Route::post('/{id}/dismiss', [AlertRecommendationController::class, 'dismiss']);
    });

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
