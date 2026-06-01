<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CaseService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseController extends Controller
{
    public function __construct(
        protected readonly CaseService $caseService,
    ) {}

    /**
     * POST /api/cases
     */
    public function store(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $request->validate([
            'title' => 'required|string',
            'severity' => 'nullable|in:critical,warning,info',
            'alert_ids' => 'nullable|array',
            'alert_ids.*' => 'uuid',
            'transaction_ids' => 'nullable|array',
            'transaction_ids.*' => 'uuid',
        ]);

        try {
            $case = $this->caseService->create(
                $context->companyId,
                $request->user()->id,
                $request->all(),
                $context->businessProfileId,
            );

            return response()->json(['case' => $case], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }
    }

    /**
     * GET /api/cases
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $data = $this->caseService->list($context->companyId, $request->all(), $context->businessProfileId);

        return response()->json($data);
    }

    /**
     * GET /api/cases/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $detail = $this->caseService->detail($context->companyId, $id, $context->businessProfileId);
        if (! $detail) {
            return response()->json(['error' => 'Case not found'], 404);
        }

        return response()->json($detail);
    }

    /**
     * PATCH /api/cases/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->caseService->update(
                $context->companyId,
                $request->user()->id,
                $id,
                $request->all(),
                $context->businessProfileId,
            );

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }
    }

    /**
     * POST /api/cases/{id}/events
     */
    public function addEvent(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $request->validate([
            'event_type' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        try {
            $result = $this->caseService->addEvent(
                $context->companyId,
                $request->user()->id,
                $id,
                $request->input('event_type'),
                $request->input('payload', []),
                $context->businessProfileId,
            );

            return response()->json($result, 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }
    }

    /**
     * POST /api/cases/{id}/alerts
     */
    public function linkAlert(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $request->validate(['alert_id' => 'required|uuid']);

        try {
            $result = $this->caseService->linkAlert(
                $context->companyId,
                $request->user()->id,
                $id,
                $request->input('alert_id'),
                $context->businessProfileId,
            );

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }
    }

    /**
     * DELETE /api/cases/{id}/alerts/{alertId}
     */
    public function unlinkAlert(Request $request, string $id, string $alertId): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->caseService->unlinkAlert(
                $context->companyId,
                $request->user()->id,
                $id,
                $alertId,
                $context->businessProfileId,
            );

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }
    }

    /**
     * GET /api/cases/{id}/summary
     */
    public function summary(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $summary = $this->caseService->summary($context->companyId, $id, $context->businessProfileId);
        if (! $summary) {
            return response()->json(['error' => 'Case not found'], 404);
        }

        return response()->json($summary);
    }

    private function safeStatus(Exception $e): int
    {
        return in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 500;
    }
}
