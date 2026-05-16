<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RuleDefinition;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    protected AlertService $alertService;

    public function __construct(AlertService $alertService)
    {
        $this->alertService = $alertService;
    }

    /**
     * GET /api/alerts
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $filters = $request->only(['status', 'severity', 'rule_key', 'sort', 'limit', 'offset']);
        $skipCompute = $request->boolean('skipCompute');

        $data = $this->alertService->list($companyId, $filters, $skipCompute);

        return response()->json($data);
    }

    /**
     * GET /api/alerts/rules
     */
    public function rules(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $rules = RuleDefinition::where('company_id', $companyId)->orderBy('rule_key')->get();

        // Normally we'd merge with registry metadata here, 
        // for now just returning the DB definitions
        return response()->json(['rules' => $rules]);
    }

    /**
     * GET /api/alerts/groups
     */
    public function groups(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $data = $this->alertService->getGroups($companyId);

        return response()->json($data);
    }

    /**
     * GET /api/alerts/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $detail = $this->alertService->detail($companyId, $id);

        if (!$detail) {
            return response()->json(['error' => 'Alert not found'], 404);
        }

        return response()->json($detail);
    }

    /**
     * PATCH /api/alerts/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $request->validate(['status' => 'required|in:open,reviewed,dismissed']);

        $alert = $this->alertService->updateStatus(
            $companyId,
            $request->user()->id,
            $id,
            $request->input('status')
        );

        if (!$alert) {
            return response()->json(['error' => 'Alert not found'], 404);
        }

        return response()->json(['alert' => $alert]);
    }

    // Note: The following endpoints require the CasesService and Case Models to be fully migrated
    // POST /api/alerts/groups/{id}/case
    // POST /api/alerts/{id}/dismiss-pattern
    // POST /api/alerts/{id}/create-case
    // POST /api/alerts/bulk-case
}
