<?php

namespace App\Services;

use App\Exceptions\AlertRecommendationReviewConflict;
use App\Models\Alert;
use App\Models\AlertRecommendation;
use App\Models\RecommendationReviewEvent;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AlertRecommendationReviewService
{
    public function __construct(
        private readonly RecommendationReviewAuditService $reviewAuditService,
    ) {}

    /**
     * @return array{recommendation: AlertRecommendation, alert: Alert}|null
     */
    public function approve(
        string $companyId,
        string $userId,
        string $recommendationId,
        string $actorType = RecommendationReviewEvent::ACTOR_USER,
    ): ?array {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId, $actorType): ?array {
            $this->assertHumanReviewer($companyId, $userId, $actorType);

            $recommendation = $this->lockedRecommendation($companyId, $recommendationId);
            if (! $recommendation) {
                return null;
            }

            $this->ensurePendingReview($recommendation);

            $alertPayload = [
                'company_id' => $recommendation->company_id,
                'alert_recommendation_id' => $recommendation->id,
                'rule_key' => $recommendation->alert_type,
                'severity' => $recommendation->severity,
                'title' => $recommendation->title,
                'detail' => $recommendation->summary,
                'evidence' => $this->alertEvidence($recommendation),
                'status' => 'open',
                'priority_score' => $this->priorityScore($recommendation->severity),
            ];

            if (Schema::hasColumn('alerts', 'reason_codes')) {
                $alertPayload = array_merge($alertPayload, [
                    'reason_codes' => $this->reasonCodes($recommendation),
                    'source_system' => 'deterministic_recommendation_engine',
                    'source_recommendation_id' => $recommendation->id,
                    'confidence_score' => $recommendation->confidence_score,
                    'evidence_refs' => $this->evidenceRefs($recommendation),
                    'comparison_window' => $this->comparisonWindow($recommendation),
                ]);
            }

            $alert = Alert::create($alertPayload);

            $recommendation->update([
                'status' => AlertRecommendation::STATUS_APPROVED,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
            ]);

            $this->reviewAuditService->record(
                companyId: $companyId,
                recommendationType: RecommendationReviewEvent::TYPE_ALERT,
                recommendationId: $recommendation->id,
                eventType: RecommendationReviewEvent::EVENT_APPROVED,
                actorType: RecommendationReviewEvent::ACTOR_USER,
                actorId: $userId,
                metadata: [
                    'alert_id' => $alert->id,
                    'alert_type' => $recommendation->alert_type,
                    'severity' => $recommendation->severity,
                ],
            );

            return [
                'recommendation' => $recommendation->fresh(['alert']),
                'alert' => $alert->fresh(['recommendation']),
            ];
        });
    }

    public function dismiss(
        string $companyId,
        string $userId,
        string $recommendationId,
        ?string $reviewNote = null,
        string $actorType = RecommendationReviewEvent::ACTOR_USER,
    ): ?AlertRecommendation {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId, $reviewNote, $actorType): ?AlertRecommendation {
            $this->assertHumanReviewer($companyId, $userId, $actorType);

            $recommendation = $this->lockedRecommendation($companyId, $recommendationId);
            if (! $recommendation) {
                return null;
            }

            $this->ensurePendingReview($recommendation);

            $recommendation->update([
                'status' => AlertRecommendation::STATUS_DISMISSED,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ]);

            $this->reviewAuditService->record(
                companyId: $companyId,
                recommendationType: RecommendationReviewEvent::TYPE_ALERT,
                recommendationId: $recommendation->id,
                eventType: RecommendationReviewEvent::EVENT_DISMISSED,
                actorType: RecommendationReviewEvent::ACTOR_USER,
                actorId: $userId,
                metadata: [
                    'alert_type' => $recommendation->alert_type,
                    'severity' => $recommendation->severity,
                    'has_review_note' => $reviewNote !== null && $reviewNote !== '',
                ],
            );

            return $recommendation->fresh(['alert']);
        });
    }

    private function lockedRecommendation(string $companyId, string $recommendationId): ?AlertRecommendation
    {
        return AlertRecommendation::where('company_id', $companyId)
            ->where('id', $recommendationId)
            ->lockForUpdate()
            ->first();
    }

    private function ensurePendingReview(AlertRecommendation $recommendation): void
    {
        if ($recommendation->status !== AlertRecommendation::STATUS_PENDING_REVIEW) {
            throw new AlertRecommendationReviewConflict((string) $recommendation->status);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function alertEvidence(AlertRecommendation $recommendation): array
    {
        $evidence = is_array($recommendation->evidence) ? $recommendation->evidence : [];
        $evidence['alert_recommendation'] = [
            'id' => $recommendation->id,
            'source_risk_domain' => $recommendation->source_risk_domain,
            'source_rule_ids' => $recommendation->source_rule_ids ?? [],
            'confidence_score' => $recommendation->confidence_score,
        ];
        $evidence['professional_services_notice'] = 'informational_risk_indicator_only';

        return $evidence;
    }

    /**
     * @return list<string>
     */
    private function reasonCodes(AlertRecommendation $recommendation): array
    {
        $codes = is_array($recommendation->source_rule_ids) ? $recommendation->source_rule_ids : [];
        if ($codes === []) {
            $codes[] = (string) $recommendation->alert_type;
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    /**
     * @return list<string>
     */
    private function evidenceRefs(AlertRecommendation $recommendation): array
    {
        $refs = [
            'recommendation:'.$recommendation->id,
            'domain:'.$recommendation->source_risk_domain,
        ];

        foreach ($this->reasonCodes($recommendation) as $reasonCode) {
            $refs[] = 'rule:'.$reasonCode;
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return array<string, mixed>
     */
    private function comparisonWindow(AlertRecommendation $recommendation): array
    {
        $evidence = is_array($recommendation->evidence) ? $recommendation->evidence : [];

        return [
            'basis' => 'current_available_records',
            'source_risk_domain' => $recommendation->source_risk_domain,
            'period' => $evidence['period'] ?? null,
        ];
    }

    private function priorityScore(string $severity): int
    {
        return match ($severity) {
            'critical' => 90,
            'high' => 75,
            'warning' => 60,
            'medium' => 50,
            'low' => 25,
            'info' => 20,
            default => 40,
        };
    }

    private function assertHumanReviewer(string $companyId, string $userId, string $actorType): void
    {
        if ($actorType === RecommendationReviewEvent::ACTOR_AGENT) {
            throw new Exception('Agents cannot approve or dismiss alert recommendations', 403);
        }

        if ($actorType !== RecommendationReviewEvent::ACTOR_USER) {
            throw new Exception('Only users can approve or dismiss alert recommendations', 403);
        }

        $authorized = User::where('id', $userId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $authorized) {
            throw new Exception('Reviewer is not authorized for this company', 403);
        }
    }
}
