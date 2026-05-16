<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountingService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function __construct(private readonly AccountingService $accountingService)
    {
    }

    /**
     * GET /api/accounting/tax-estimate
     */
    public function taxEstimate(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            return response()->json($this->accountingService->taxEstimate($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to calculate tax estimate'], 500);
        }
    }
}
