<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InvestigationService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvestigationController extends Controller
{
    public function __construct(
        private readonly InvestigationService $investigationService,
    ) {}

    /**
     * GET /api/investigations
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'investigation_status' => ['sometimes', 'string', Rule::in(['all', 'open', 'in_review', 'escalated', 'resolved', 'archived'])],
            'investigation_priority' => ['sometimes', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'assigned_to' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $data = $this->investigationService->list($companyId, $validated);

        return response()->json($data);
    }

    /**
     * GET /api/investigations/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $detail = $this->investigationService->detail($companyId, $id);
        if (! $detail) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($detail);
    }

    /**
     * POST /api/investigations/{id}/assign
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'assignee_id' => ['required', 'uuid'],
        ]);

        try {
            $result = $this->investigationService->assign(
                $companyId,
                $request->user()->id,
                $id,
                $validated['assignee_id'],
            );
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                422 => 422,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/status
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'investigation_status' => ['required', 'string', Rule::in(['open', 'in_review', 'escalated', 'resolved', 'archived'])],
        ]);

        try {
            $result = $this->investigationService->updateStatus(
                $companyId,
                $request->user()->id,
                $id,
                $validated['investigation_status'],
            );
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                422 => 422,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/notes
     */
    public function notes(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:10000'],
        ]);

        try {
            $result = $this->investigationService->addNotes(
                $companyId,
                $request->user()->id,
                $id,
                $validated['notes'],
            );
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }
}
