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
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $this->contractService->investigationContractForContext($context, $id);
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
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $this->contractService->findingsForContext($context, $id);
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
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $this->contractService->suggestedRecordsForContext($context, $id);
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
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $this->contractService->reviewEventsForContext($context, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/investigations/{id}/reviewer-notes
     * GET /api/investigations/{id}/notes
     */
    public function reviewerNotes(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $this->contractService->reviewerNotesForContext($context, $id);
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
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $this->contractService->casePackagesForContext($context, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }
}
