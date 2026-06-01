<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CaseRecommendationReviewConflict;
use App\Http\Controllers\Controller;
use App\Models\CaseRecommendation;
use App\Models\RecommendationReviewEvent;
use App\Services\Agents\CaseRecommendationService;
use App\Services\CaseRecommendationReviewService;
use App\Services\RecommendationReviewAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class CaseRecommendationController extends Controller
{
    public function __construct(
        private readonly CaseRecommendationReviewService $reviewService,
        private readonly CaseRecommendationService $caseRecommendationService,
        private readonly RecommendationReviewAuditService $reviewAuditService,
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
            ->where('company_id', $context->companyId)
            ->when(
                $context->businessProfileId && Schema::hasColumn('case_recommendations', 'business_profile_id'),
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
                ->get()
                ->map(fn (CaseRecommendation $recommendation): array => $this->caseRecommendationService->recommendationPayload($recommendation))
                ->values()
                ->all();
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'case_recommendation_list');
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
            $recommendation = CaseRecommendation::with('auditCase')
                ->where('company_id', $context->companyId)
                ->when(
                    $context->businessProfileId && Schema::hasColumn('case_recommendations', 'business_profile_id'),
                    fn ($query) => $query->where('business_profile_id', $context->businessProfileId),
                )
                ->where('id', $id)
                ->first();
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'case_recommendation_detail');
        }

        if (! $recommendation) {
            return response()->json(['error' => 'Case recommendation not found'], 404);
        }

        try {
            $this->reviewAuditService->record(
                companyId: $context->companyId,
                recommendationType: RecommendationReviewEvent::TYPE_CASE,
                recommendationId: $recommendation->id,
                eventType: RecommendationReviewEvent::EVENT_VIEWED,
                actorType: RecommendationReviewEvent::ACTOR_USER,
                actorId: $request->user()->id,
                metadata: [
                    'source' => 'api',
                ],
                businessProfileId: $context->businessProfileId,
            );

            $payload = $this->caseRecommendationService->recommendationPayload($recommendation);
            $payload['review_events'] = $this->reviewAuditService->history(
                $context->companyId,
                RecommendationReviewEvent::TYPE_CASE,
                $recommendation->id,
                $context->businessProfileId,
            );
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'case_recommendation_history');
        }

        return response()->json([
            'recommendation' => $payload,
        ]);
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
        } catch (CaseRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'case_recommendation_approve', [403]);
        }

        if (! $result) {
            return response()->json(['error' => 'Case recommendation not found'], 404);
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
            $result = $this->reviewService->dismiss(
                $context->companyId,
                $request->user()->id,
                $id,
                $validated['review_note'] ?? null,
                RecommendationReviewEvent::ACTOR_USER,
                $context->businessProfileId,
            );
        } catch (CaseRecommendationReviewConflict $e) {
            return $this->reviewConflict($e);
        } catch (Throwable $e) {
            return $this->safeReviewError($e, 'case_recommendation_dismiss', [403]);
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

        Log::warning('case_recommendation_review.failed', [
            'operation' => $operation,
            'error_class' => $e::class,
            'error_code' => $status ?: null,
        ]);

        return response()->json([
            'error' => 'Case recommendation request could not be completed safely',
        ], 500);
    }
}
