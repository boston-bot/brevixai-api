<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CaseService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseController extends Controller
{
    protected CaseService $caseService;

    public function __construct(CaseService $caseService)
    {
        $this->caseService = $caseService;
    }

    /**
     * POST /api/cases
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $request->validate([
            'title' => 'required|string',
            'severity' => 'nullable|in:critical,warning,info',
            'alert_ids' => 'nullable|array',
            'transaction_ids' => 'nullable|array',
        ]);

        try {
            $case = $this->caseService->create($companyId, $request->user()->id, $request->all());
            return response()->json(['case' => $case], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/cases
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $data = $this->caseService->list($companyId, $request->all());
        return response()->json($data);
    }

    /**
     * GET /api/cases/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $detail = $this->caseService->detail($companyId, $id);
        if (!$detail) return response()->json(['error' => 'Case not found'], 404);

        return response()->json($detail);
    }

    /**
     * PATCH /api/cases/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $result = $this->caseService->update($companyId, $request->user()->id, $id, $request->all());
            return response()->json($result);
        } catch (Exception $e) {
            $status = $e->getCode() === 422 ? 422 : ($e->getCode() === 404 ? 404 : 500);
            return response()->json(['error' => $e->getMessage()], $status);
        }
    }

    /**
     * POST /api/cases/{id}/events
     */
    public function addEvent(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $request->validate([
            'event_type' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        try {
            $result = $this->caseService->addEvent(
                $companyId, 
                $request->user()->id, 
                $id, 
                $request->input('event_type'), 
                $request->input('payload', [])
            );
            return response()->json($result, 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() === 404 ? 404 : 500);
        }
    }

    /**
     * POST /api/cases/{id}/alerts
     */
    public function linkAlert(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $request->validate(['alert_id' => 'required|string']);

        try {
            $result = $this->caseService->linkAlert($companyId, $request->user()->id, $id, $request->input('alert_id'));
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() === 404 ? 404 : 500);
        }
    }

    /**
     * DELETE /api/cases/{id}/alerts/{alertId}
     */
    public function unlinkAlert(Request $request, string $id, string $alertId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $result = $this->caseService->unlinkAlert($companyId, $request->user()->id, $id, $alertId);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() === 404 ? 404 : 500);
        }
    }

    /**
     * GET /api/cases/{id}/summary
     */
    public function summary(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $summary = $this->caseService->summary($companyId, $id);
        if (!$summary) return response()->json(['error' => 'Case not found'], 404);

        return response()->json($summary);
    }
}
