<?php

namespace App\Services;

use App\Exceptions\CaseRecommendationReviewConflict;
use App\Models\AuditCase;
use App\Models\AuditCaseEvent;
use App\Models\CaseRecommendation;
use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use App\Models\RecommendationReviewEvent;
use App\Models\User;
use App\Services\Agents\CaseRecommendationService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CaseRecommendationReviewService
{
    public function __construct(
        private readonly CaseRecommendationService $caseRecommendationService,
        private readonly RecommendationReviewAuditService $reviewAuditService,
        private readonly InvestigationService $investigationService,
        private readonly InvestigationEvidenceService $evidenceService,
    ) {}

    /**
     * @return array{recommendation: array<string, mixed>, case: AuditCase}|null
     */
    public function approve(
        string $companyId,
        string $userId,
        string $recommendationId,
        string $actorType = RecommendationReviewEvent::ACTOR_USER,
        ?string $businessProfileId = null,
    ): ?array {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId, $actorType, $businessProfileId): ?array {
            $this->assertHumanReviewer($companyId, $userId, $actorType);

            $recommendation = $this->lockedRecommendation($companyId, $recommendationId, $businessProfileId);
            if (! $recommendation) {
                return null;
            }

            $this->ensurePendingReview($recommendation);

            $casePayload = [
                'company_id' => $recommendation->company_id,
                'case_recommendation_id' => $recommendation->id,
                'title' => $recommendation->title,
                'description' => $this->caseDescription($recommendation),
                'severity' => $recommendation->severity,
                'status' => 'open',
                'created_by' => $userId,
            ];
            if (Schema::hasColumn('audit_cases', 'business_profile_id')) {
                $casePayload['business_profile_id'] = $recommendation->business_profile_id;
            }

            $case = AuditCase::create($casePayload);

            $caseEventPayload = [
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
            ];
            if (Schema::hasColumn('audit_case_events', 'business_profile_id')) {
                $caseEventPayload['business_profile_id'] = $recommendation->business_profile_id;
            }

            AuditCaseEvent::create($caseEventPayload);

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
                        'business_profile_id' => $recommendation->business_profile_id,
                    ],
                );
            }

            if (Schema::hasTable('investigation_evidence_items')) {
                $this->evidenceService->add(
                    companyId: $companyId,
                    actorType: InvestigationEvidenceItem::ACTOR_SYSTEM,
                    actorId: null,
                    caseId: $case->id,
                    data: [
                        'evidence_type' => InvestigationEvidenceItem::TYPE_RECOMMENDATION,
                        'evidence_reference_id' => $recommendation->id,
                        'title' => "Case recommendation: {$recommendation->title}",
                        'summary' => $recommendation->summary,
                        'source' => 'system:case_recommendation_approval',
                        'metadata' => [
                            'case_type' => $recommendation->case_type,
                            'confidence_score' => $recommendation->confidence_score,
                            'source_risk_domains' => $recommendation->source_risk_domains ?? [],
                            'business_profile_id' => $recommendation->business_profile_id,
                        ],
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
                businessProfileId: $businessProfileId,
            );

            return [
                'recommendation' => $this->caseRecommendationService->recommendationPayload(
                    $recommendation->fresh(['auditCase']),
                ),
                'case' => $case->fresh(['caseRecommendation']),
            ];
        });
    }

    public function dismiss(
        string $companyId,
        string $userId,
        string $recommendationId,
        ?string $reviewNote = null,
        string $actorType = RecommendationReviewEvent::ACTOR_USER,
        ?string $businessProfileId = null,
    ): ?array {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId, $reviewNote, $actorType, $businessProfileId): ?array {
            $this->assertHumanReviewer($companyId, $userId, $actorType);

            $recommendation = $this->lockedRecommendation($companyId, $recommendationId, $businessProfileId);
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
                businessProfileId: $businessProfileId,
            );

            return [
                'recommendation' => $this->caseRecommendationService->recommendationPayload(
                    $recommendation->fresh(['auditCase']),
                ),
            ];
        });
    }

    private function lockedRecommendation(string $companyId, string $recommendationId, ?string $businessProfileId): ?CaseRecommendation
    {
        return CaseRecommendation::where('company_id', $companyId)
            ->where('id', $recommendationId)
            ->when(
                $businessProfileId && Schema::hasColumn('case_recommendations', 'business_profile_id'),
                fn ($query) => $query->where('business_profile_id', $businessProfileId),
            )
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

    private function assertHumanReviewer(string $companyId, string $userId, string $actorType): void
    {
        if ($actorType === RecommendationReviewEvent::ACTOR_AGENT) {
            throw new Exception('Agents cannot approve or dismiss case recommendations', 403);
        }

        if ($actorType !== RecommendationReviewEvent::ACTOR_USER) {
            throw new Exception('Only users can approve or dismiss case recommendations', 403);
        }

        $authorized = User::where('id', $userId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $authorized) {
            throw new Exception('Reviewer is not authorized for this company', 403);
        }
    }
}
