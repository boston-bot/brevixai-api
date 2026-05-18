<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CaseRecommendationReviewConflict;
use App\Http\Controllers\Controller;
use App\Models\CaseRecommendation;
use App\Services\Agents\CaseRecommendationService;
use App\Services\CaseRecommendationReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CaseRecommendationController extends Controller
{
    public function __construct(
        private readonly CaseRecommendationReviewService $reviewService,
        private readonly CaseRecommendationService $caseRecommendationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                'all',
                CaseRecommendation::STATUS_PENDING_REVIEW,
                CaseRecommendation::STATUS_APPROVED,
                CaseRecommendation::STATUS_DISMISSED,
                CaseRecommendation::STATUS_EXPIRED,
            ])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $status = $validated['status'] ?? CaseRecommendation::STATUS_PENDING_REVIEW;
        $limit = (int) ($validated['limit'] ?? 50);
        $offset = (int) ($validated['offset'] ?? 0);

        $query = CaseRecommendation::with('auditCase')
            ->where('company_id', $companyId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $recommendations = $query
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (CaseRecommendation $recommendation): array => $this->caseRecommendationService->recommendationPayload($recommendation))
            ->values()
            ->all();

        return response()->json([
            'recommendations' => $recommendations,
            'total' => $total,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $recommendation = CaseRecommendation::with('auditCase')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (! $recommendation) {
            return response()->json(['error' => 'Case recommendation not found'], 404);
        }

        return response()->json([
            'recommendation' => $this->caseRecommendationService->recommendationPayload($recommendation),
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->reviewService->approve($companyId, $request->user()->id, $id);
        } catch (CaseRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        }

        if (! $result) {
            return response()->json(['error' => 'Case recommendation not found'], 404);
        }

        return response()->json($result);
    }

    public function dismiss(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'review_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->reviewService->dismiss(
                $companyId,
                $request->user()->id,
                $id,
                $validated['review_note'] ?? null,
            );
        } catch (CaseRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        }

        if (! $result) {
            return response()->json(['error' => 'Case recommendation not found'], 404);
        }

        return response()->json($result);
    }

    private function reviewConflict(CaseRecommendationReviewConflict $e): JsonResponse
    {
        return response()->json([
            'error' => 'Case recommendation has already been reviewed',
            'current_status' => $e->currentStatus,
        ], 409);
    }
}
