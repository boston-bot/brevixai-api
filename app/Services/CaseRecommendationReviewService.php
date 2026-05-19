<?php

namespace App\Services;

use App\Exceptions\CaseRecommendationReviewConflict;
use App\Models\AuditCase;
use App\Models\AuditCaseEvent;
use App\Models\CaseRecommendation;
use App\Models\InvestigationActivityEvent;
use App\Models\RecommendationReviewEvent;
use App\Services\Agents\CaseRecommendationService;
use App\Services\InvestigationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CaseRecommendationReviewService
{
    public function __construct(
        private readonly CaseRecommendationService $caseRecommendationService,
        private readonly RecommendationReviewAuditService $reviewAuditService,
        private readonly InvestigationService $investigationService,
    ) {}

    /**
     * @return array{recommendation: array<string, mixed>, case: AuditCase}|null
     */
    public function approve(string $companyId, string $userId, string $recommendationId): ?array
    {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId): ?array {
            $recommendation = $this->lockedRecommendation($companyId, $recommendationId);
            if (! $recommendation) {
                return null;
            }

            $this->ensurePendingReview($recommendation);

            $case = AuditCase::create([
                'company_id' => $recommendation->company_id,
                'case_recommendation_id' => $recommendation->id,
                'title' => $recommendation->title,
                'description' => $this->caseDescription($recommendation),
                'severity' => $recommendation->severity,
                'status' => 'open',
                'created_by' => $userId,
            ]);

            AuditCaseEvent::create([
                'case_id' => $case->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'event_type' => 'case_created_from_recommendation',
                'payload' => [
                    'case_recommendation_id' => $recommendation->id,
                    'case_type' => $recommendation->case_type,
                    'source_risk_domains' => $recommendation->source_risk_domains ?? [],
                    'related_alert_recommendation_ids' => $recommendation->related_alert_recommendation_ids ?? [],
                ],
            ]);

            if (Schema::hasTable('investigation_activity_events')) {
                $this->investigationService->recordActivity(
                    caseId: $case->id,
                    companyId: $companyId,
                    eventType: InvestigationActivityEvent::EVENT_CASE_CREATED,
                    actorType: InvestigationActivityEvent::ACTOR_USER,
                    actorId: $userId,
                    eventSummary: 'Investigation opened from approved case recommendation',
                    eventMetadata: [
                        'case_recommendation_id' => $recommendation->id,
                        'case_type' => $recommendation->case_type,
                        'source_risk_domains' => $recommendation->source_risk_domains ?? [],
                        'confidence_score' => $recommendation->confidence_score,
                    ],
                );
            }

            $recommendation->update([
                'status' => CaseRecommendation::STATUS_APPROVED,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
            ]);

            $this->reviewAuditService->record(
                companyId: $companyId,
                recommendationType: RecommendationReviewEvent::TYPE_CASE,
                recommendationId: $recommendation->id,
                eventType: RecommendationReviewEvent::EVENT_APPROVED,
                actorType: RecommendationReviewEvent::ACTOR_USER,
                actorId: $userId,
                metadata: [
                    'case_id' => $case->id,
                    'case_type' => $recommendation->case_type,
                    'severity' => $recommendation->severity,
                ],
            );

            return [
                'recommendation' => $this->caseRecommendationService->recommendationPayload(
                    $recommendation->fresh(['auditCase']),
                ),
                'case' => $case->fresh(['caseRecommendation']),
            ];
        });
    }

    public function dismiss(string $companyId, string $userId, string $recommendationId, ?string $reviewNote = null): ?array
    {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId, $reviewNote): ?array {
            $recommendation = $this->lockedRecommendation($companyId, $recommendationId);
            if (! $recommendation) {
                return null;
            }

            $this->ensurePendingReview($recommendation);

            $recommendation->update([
                'status' => CaseRecommendation::STATUS_DISMISSED,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ]);

            $this->reviewAuditService->record(
                companyId: $companyId,
                recommendationType: RecommendationReviewEvent::TYPE_CASE,
                recommendationId: $recommendation->id,
                eventType: RecommendationReviewEvent::EVENT_DISMISSED,
                actorType: RecommendationReviewEvent::ACTOR_USER,
                actorId: $userId,
                metadata: [
                    'case_type' => $recommendation->case_type,
                    'severity' => $recommendation->severity,
                    'has_review_note' => $reviewNote !== null && $reviewNote !== '',
                ],
            );

            return [
                'recommendation' => $this->caseRecommendationService->recommendationPayload(
                    $recommendation->fresh(['auditCase']),
                ),
            ];
        });
    }

    private function lockedRecommendation(string $companyId, string $recommendationId): ?CaseRecommendation
    {
        return CaseRecommendation::where('company_id', $companyId)
            ->where('id', $recommendationId)
            ->lockForUpdate()
            ->first();
    }

    private function ensurePendingReview(CaseRecommendation $recommendation): void
    {
        if ($recommendation->status !== CaseRecommendation::STATUS_PENDING_REVIEW) {
            throw new CaseRecommendationReviewConflict((string) $recommendation->status);
        }
    }

    private function caseDescription(CaseRecommendation $recommendation): string
    {
        $sourceDomains = implode(', ', $recommendation->source_risk_domains ?? []);

        return trim($recommendation->summary."\n\nSource risk domains: ".$sourceDomains);
    }
}
