<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    protected ReconciliationService $reconciliationService;

    public function __construct(ReconciliationService $reconciliationService)
    {
        $this->reconciliationService = $reconciliationService;
    }

    /**
     * GET /api/reconciliation/summary
     */
    public function getSummary(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $summary = $this->reconciliationService->getSummary($companyId);

        if (!$summary['hasRun']) {
            return response()->json(['error' => 'No reconciliation run found'], 404);
        }

        return response()->json($summary);
    }

    /**
     * GET /api/reconciliation/discrepancies
     */
    public function getDiscrepancies(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $data = $this->reconciliationService->getDiscrepancies($companyId, $request->all());

        return response()->json($data);
    }

    /**
     * GET /api/reconciliation/discrepancies/{id}
     */
    public function getDiscrepancy(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $discrepancy = $this->reconciliationService->getDiscrepancyDetail($companyId, $id);

        if (!$discrepancy) {
            return response()->json(['error' => 'Discrepancy not found'], 404);
        }

        return response()->json(['discrepancy' => $discrepancy]);
    }

    /**
     * PATCH /api/reconciliation/discrepancies/{id}/status
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:new,in_review,confirmed_action,ignored,escalated,resolved',
            'note'   => 'nullable|string',
        ]);

        $updated = $this->reconciliationService->updateStatus(
            $companyId,
            $request->user()->id,
            $id,
            $request->input('status'),
            $request->input('note')
        );

        if (!$updated) {
            return response()->json(['error' => 'Discrepancy not found'], 404);
        }

        return response()->json(['discrepancy' => $updated]);
    }

    /**
     * POST /api/reconciliation/discrepancies/{id}/confirm-action
     */
    public function confirmAction(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $request->validate([
            'action' => 'required|string',
            'note'   => 'nullable|string',
        ]);

        $updated = $this->reconciliationService->confirmAction(
            $companyId,
            $request->user()->id,
            $id,
            $request->input('action'),
            $request->input('note')
        );

        if (!$updated) {
            return response()->json(['error' => 'Discrepancy not found'], 404);
        }

        return response()->json(['discrepancy' => $updated]);
    }

    /**
     * POST /api/reconciliation/discrepancies/{id}/notes
     */
    public function addNote(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $request->validate(['note' => 'required|string']);

        $updated = $this->reconciliationService->addNote(
            $companyId,
            $request->user()->id,
            $id,
            $request->input('note')
        );

        if (!$updated) {
            return response()->json(['error' => 'Discrepancy not found'], 404);
        }

        return response()->json(['discrepancy' => $updated]);
    }
}
