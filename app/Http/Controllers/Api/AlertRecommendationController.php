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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

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

        try {
            $total = (clone $query)->count();
            $recommendations = $query
                ->orderByDesc('created_at')
                ->offset($offset)
                ->limit($limit)
                ->get();
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'alert_recommendation_list');
        }

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

        try {
            $recommendation = AlertRecommendation::with('alert')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->first();
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'alert_recommendation_detail');
        }

        if (! $recommendation) {
            return response()->json(['error' => 'Alert recommendation not found'], 404);
        }

        try {
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
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'alert_recommendation_history');
        }

        return response()->json(['recommendation' => $payload]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->reviewService->approve(
                $companyId,
                $request->user()->id,
                $id,
                RecommendationReviewEvent::ACTOR_USER,
            );
        } catch (AlertRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'alert_recommendation_approve', [403]);
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
                $validated['review_note'] ?? null,
                RecommendationReviewEvent::ACTOR_USER,
            );
        } catch (AlertRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'alert_recommendation_dismiss', [403]);
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

    /**
     * @param  array<int, int>  $safeStatusCodes
     */
    private function safeReviewError(
        Throwable $e,
        string $operation,
        array $safeStatusCodes = [403, 404, 422],
    ): JsonResponse {
        $status = (int) $e->getCode();

        if (in_array($status, $safeStatusCodes, true)) {
            return response()->json(['error' => $e->getMessage()], $status);
        }

        Log::warning('alert_recommendation_review.failed', [
            'operation' => $operation,
            'error_class' => $e::class,
            'error_code' => $status ?: null,
        ]);

        return response()->json([
            'error' => 'Alert recommendation request could not be completed safely',
        ], 500);
    }
}
