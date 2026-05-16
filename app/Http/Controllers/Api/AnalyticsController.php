<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    protected AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * GET /api/analytics/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $summary = $this->analyticsService->getPerformanceSummary($companyId);
            return response()->json($summary);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to fetch analytics summary'], 500);
        }
    }

    /**
     * GET /api/analytics/vendors
     */
    public function vendors(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $limit = $request->integer('limit', 5);
            $vendors = $this->analyticsService->getTopVendors($companyId, $limit);
            return response()->json($vendors);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to fetch top vendors'], 500);
        }
    }

    /**
     * GET /api/analytics/cash-flow
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $cashFlow = $this->analyticsService->getCashFlowAnalytics($companyId);
            return response()->json($cashFlow);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to fetch cash flow analytics'], 500);
        }
    }
}
