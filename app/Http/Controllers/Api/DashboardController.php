<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService)
    {
    }

    /**
     * GET /api/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            return response()->json($this->dashboardService->summary($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch dashboard summary'], 500);
        }
    }
}
