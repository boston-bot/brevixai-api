<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AlertRecommendationReviewConflict;
use App\Http\Controllers\Controller;
use App\Models\AlertRecommendation;
use App\Models\RecommendationReviewEvent;
use App\Services\Agents\AlertRecommendationService;
use App\Services\AlertRecommendationReviewService;
use App\Services\RecommendationReviewAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class AlertRecommendationController extends Controller
{
    public function __construct(
        private readonly AlertRecommendationReviewService $reviewService,
        private readonly RecommendationReviewAuditService $reviewAuditService,
        private readonly AlertRecommendationService $alertRecommendationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
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
            ->where('company_id', $context->companyId)
            ->when(
                $context->businessProfileId && Schema::hasColumn('alert_recommendations', 'business_profile_id'),
                fn ($query) => $query->where('business_profile_id', $context->businessProfileId),
            );

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
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $recommendation = AlertRecommendation::with('alert')
                ->where('company_id', $context->companyId)
                ->when(
                    $context->businessProfileId && Schema::hasColumn('alert_recommendations', 'business_profile_id'),
                    fn ($query) => $query->where('business_profile_id', $context->businessProfileId),
                )
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
                companyId: $context->companyId,
                recommendationType: RecommendationReviewEvent::TYPE_ALERT,
                recommendationId: $recommendation->id,
                eventType: RecommendationReviewEvent::EVENT_VIEWED,
                actorType: RecommendationReviewEvent::ACTOR_USER,
                actorId: $request->user()->id,
                metadata: [
                    'source' => 'api',
                ],
                businessProfileId: $context->businessProfileId,
            );

            $payload = $recommendation->toArray();
            $payload['review_events'] = $this->reviewAuditService->history(
                $context->companyId,
                RecommendationReviewEvent::TYPE_ALERT,
                $recommendation->id,
                $context->businessProfileId,
            );
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'alert_recommendation_history');
        }

        return response()->json(['recommendation' => $payload]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->reviewService->approve(
                $context->companyId,
                $request->user()->id,
                $id,
                RecommendationReviewEvent::ACTOR_USER,
                $context->businessProfileId,
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
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'review_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        try {
            $recommendation = $this->reviewService->dismiss(
                $context->companyId,
                $request->user()->id,
                $id,
                $validated['review_note'] ?? null,
                RecommendationReviewEvent::ACTOR_USER,
                $context->businessProfileId,
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

    public function run(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->alertRecommendationService->getAlertRecommendations(
                $context->companyId,
                $context->businessProfileId,
            );
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'alert_recommendation_run');
        }

        $recommendations = $result['recommended_alerts'] ?? [];

        return response()->json([
            'recommendations_generated' => count($recommendations),
            'pending_review' => collect($recommendations)
                ->where('status', AlertRecommendation::STATUS_PENDING_REVIEW)
                ->count(),
            'recommendations' => $recommendations,
        ]);
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
