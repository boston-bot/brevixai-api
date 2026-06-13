<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InvestigationPlatformContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestigationPlatformContractController extends Controller
{
    public function __construct(
        private readonly InvestigationPlatformContractService $contractService,
    ) {}

    /**
     * GET /api/investigation-platform/contract
     */
    public function contract(): JsonResponse
    {
        return response()->json($this->contractService->contract());
    }

    /**
     * GET /api/investigations/{id}/contract
     */
    public function investigation(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $payload = $this->contractService->investigationContract($companyId, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/investigations/{id}/findings
     */
    public function findings(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $payload = $this->contractService->findings($companyId, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/investigations/{id}/suggested-records
     */
    public function suggestedRecords(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $payload = $this->contractService->suggestedRecords($companyId, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/investigations/{id}/activity
     */
    public function activity(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $payload = $this->contractService->reviewEvents($companyId, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/investigations/{id}/packages
     */
    public function packages(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $payload = $this->contractService->casePackages($companyId, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }

    private function companyId(Request $request): ?string
    {
        return $request->user()?->company_id;
    }
}
