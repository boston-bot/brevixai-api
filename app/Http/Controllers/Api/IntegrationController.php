<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QboService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    protected QboService $qboService;

    public function __construct(QboService $qboService)
    {
        $this->qboService = $qboService;
    }

    /**
     * GET /api/integrations/qbo/connect
     */
    public function qboConnect(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $url = $this->qboService->generateAuthUri($companyId);
            return response()->json(['url' => $url]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/integrations/qbo/callback
     */
    public function qboCallback(Request $request)
    {
        $stateNonce = $request->query('state');
        $realmId = $request->query('realmId');
        $code = $request->query('code');

        if (!$stateNonce || !$realmId || !$code) {
            return response('Missing required callback parameters', 400);
        }

        $companyId = $this->qboService->consumeOAuthStateNonce($stateNonce);

        if (!$companyId) {
            return response('Invalid or expired OAuth state. Please try connecting again.', 403);
        }

        try {
            $this->qboService->exchangeTokens($companyId, $realmId, $code, $this->qboService->redirectUri());
            
            // Redirect back to frontend
            return redirect(config('app.frontend_url', config('app.url')) . '/settings');
        } catch (Exception $e) {
            return response('Failed to complete QuickBooks authentication: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/integrations/status
     */
    public function status(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            // For now only QBO is fully supported in backend
            $integrations = $this->qboService->getStatus($companyId);
            return response()->json(['integrations' => $integrations]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to fetch integrations status'], 500);
        }
    }

    /**
     * POST /api/integrations/qbo/sync
     */
    public function qboSync(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $realmId = $request->input('realmId');

        if (!$companyId || !$realmId) return response()->json(['error' => 'Invalid request'], 400);

        try {
            $sync = $this->qboService->sync($companyId, $realmId);

            return response()->json($sync);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * DELETE /api/integrations/qbo/disconnect/{realmId}
     */
    public function qboDisconnect(Request $request, string $realmId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $this->qboService->disconnect($companyId, $realmId);
            return response()->json(['message' => "QuickBooks company {$realmId} disconnected successfully"]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to disconnect'], 500);
        }
    }

    /**
     * DELETE /api/integrations/qbo/purge/{realmId}
     */
    public function qboPurge(Request $request, string $realmId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $this->qboService->purge($companyId, $realmId);
            return response()->json(['message' => "Data for company {$realmId} has been purged."]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * POST /api/integrations/qbo/credentials
     */
    public function qboSaveCredentials(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $request->validate([
            'clientId' => 'required|string',
            'clientSecret' => 'required|string',
            'environment' => 'required|string',
        ]);

        try {
            $this->qboService->saveCredentials($companyId, $request->all());
            return response()->json(['message' => 'QuickBooks credentials saved successfully']);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to save credentials'], 500);
        }
    }

    /**
     * DELETE /api/integrations/qbo/credentials
     */
    public function qboRemoveCredentials(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $this->qboService->removeCredentials($companyId);
            return response()->json(['message' => 'QuickBooks credentials removed successfully']);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }
}
