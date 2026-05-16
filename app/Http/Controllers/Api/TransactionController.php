<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * List transactions with filtering and signal enrichment.
     * GET /api/transactions
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $filters = $request->only([
            'date_from', 'date_to', 'status', 'vendor', 'category',
            'source_type', 'min_amount', 'type', 'uncategorized',
            'review_state', 'limit', 'offset',
        ]);

        $data = $this->transactionService->list($companyId, $filters);

        return response()->json($data);
    }

    /**
     * Get full detail for a single transaction.
     * GET /api/transactions/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $detail = $this->transactionService->detail($companyId, $id);

        if (!$detail) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        return response()->json(['transaction' => $detail]);
    }

    /**
     * Toggle the review/marked state of a transaction.
     * PATCH /api/transactions/{id}/review
     */
    public function review(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $request->validate(['marked' => 'required|boolean']);

        $result = $this->transactionService->setReviewState(
            $companyId,
            $request->user()->id,
            $id,
            (bool)$request->input('marked')
        );

        if (!$result) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        return response()->json($result);
    }
}
