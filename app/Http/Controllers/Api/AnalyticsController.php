<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService) {}

    /**
     * GET /api/analytics/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $summary = $this->analyticsService->getPerformanceSummary($context->companyId, $context->businessProfileId);

            return response()->json($summary);
        } catch (Throwable) {
            return response()->json(['error' => 'Failed to fetch analytics summary'], 500);
        }
    }

    /**
     * GET /api/analytics/vendors
     */
    public function vendors(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $limit = $request->integer('limit', 5);
            $vendors = $this->analyticsService->getTopVendors($context->companyId, $context->businessProfileId, $limit);

            return response()->json($vendors);
        } catch (Throwable) {
            return response()->json(['error' => 'Failed to fetch top vendors'], 500);
        }
    }

    /**
     * GET /api/analytics/cash-flow
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $cashFlow = $this->analyticsService->getCashFlowAnalytics($context->companyId, $context->businessProfileId);

            return response()->json($cashFlow);
        } catch (Throwable) {
            return response()->json(['error' => 'Failed to fetch cash flow analytics'], 500);
        }
    }
}
