<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    /**
     * GET /api/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            return response()->json($this->dashboardService->summary($context->companyId, $context->businessProfileId));
        } catch (Throwable) {
            return response()->json(['error' => 'Failed to fetch dashboard summary'], 500);
        }
    }
}
