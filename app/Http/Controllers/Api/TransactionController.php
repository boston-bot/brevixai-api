<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessProfileAccessException;
use App\Http\Controllers\Controller;
use App\Services\BusinessProfileContextService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService, private readonly BusinessProfileContextService $businessProfileContext)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * List transactions with filtering and signal enrichment.
     * GET /api/transactions
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $filters = $request->only([
            'date_from', 'date_to', 'status', 'vendor', 'category',
            'source_type', 'min_amount', 'type', 'uncategorized',
            'review_state', 'limit', 'offset',
        ]);
        if ($context->businessProfileId) {
            $filters['business_profile_id'] = $context->businessProfileId;
        }

        $data = $this->transactionService->list($context->companyId, $filters);

        return response()->json($data);
    }

    /**
     * Get full detail for a single transaction.
     * GET /api/transactions/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $detail = $this->transactionService->detail($context->companyId, $id, $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $request->validate(['marked' => 'required|boolean']);

        $result = $this->transactionService->setReviewState(
            $context->companyId,
            $request->user()->id,
            $id,
            (bool)$request->input('marked'),
            $context->businessProfileId
        );

        if (!$result) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        return response()->json($result);
    }

    private function resolveContext(Request $request): \App\Services\BusinessProfileContext|JsonResponse
    {
        try {
            return $this->businessProfileContext->resolveForRequest($request);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
    }
}
