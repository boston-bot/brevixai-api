<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AlertRecommendationReviewConflict;
use App\Http\Controllers\Controller;
use App\Models\AlertRecommendation;
use App\Models\RecommendationReviewEvent;
use App\Services\AlertRecommendationReviewService;
use App\Services\RecommendationReviewAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertRecommendationController extends Controller
{
    public function __construct(
        private readonly AlertRecommendationReviewService $reviewService,
        private readonly RecommendationReviewAuditService $reviewAuditService,
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
                AlertRecommendation::STATUS_PENDING_REVIEW,
                AlertRecommendation::STATUS_APPROVED,
                AlertRecommendation::STATUS_DISMISSED,
                AlertRecommendation::STATUS_EXPIRED,
            ])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $status = $validated['status'] ?? AlertRecommendation::STATUS_PENDING_REVIEW;
        $limit = (int) ($validated['limit'] ?? 50);
        $offset = (int) ($validated['offset'] ?? 0);

        $query = AlertRecommendation::with('alert')
            ->where('company_id', $companyId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $recommendations = $query
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

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

        $recommendation = AlertRecommendation::with('alert')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (! $recommendation) {
            return response()->json(['error' => 'Alert recommendation not found'], 404);
        }

        $this->reviewAuditService->record(
            companyId: $companyId,
            recommendationType: RecommendationReviewEvent::TYPE_ALERT,
            recommendationId: $recommendation->id,
            eventType: RecommendationReviewEvent::EVENT_VIEWED,
            actorType: RecommendationReviewEvent::ACTOR_USER,
            actorId: $request->user()->id,
            metadata: [
                'source' => 'api',
            ],
        );

        $payload = $recommendation->toArray();
        $payload['review_events'] = $this->reviewAuditService->history(
            $companyId,
            RecommendationReviewEvent::TYPE_ALERT,
            $recommendation->id,
        );

        return response()->json(['recommendation' => $payload]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->reviewService->approve($companyId, $request->user()->id, $id);
        } catch (AlertRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        }

        if (! $result) {
            return response()->json(['error' => 'Alert recommendation not found'], 404);
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
            $recommendation = $this->reviewService->dismiss(
                $companyId,
                $request->user()->id,
                $id,
                $validated['review_note'] ?? null
            );
        } catch (AlertRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        }

        if (! $recommendation) {
            return response()->json(['error' => 'Alert recommendation not found'], 404);
        }

        return response()->json(['recommendation' => $recommendation]);
    }

    private function reviewConflict(AlertRecommendationReviewConflict $e): JsonResponse
    {
        return response()->json([
            'error' => 'Alert recommendation has already been reviewed',
            'current_status' => $e->currentStatus,
        ], 409);
    }
}
