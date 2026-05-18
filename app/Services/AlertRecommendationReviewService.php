<?php

namespace App\Services;

use App\Exceptions\AlertRecommendationReviewConflict;
use App\Models\Alert;
use App\Models\AlertRecommendation;
use Illuminate\Support\Facades\DB;

class AlertRecommendationReviewService
{
    /**
     * @return array{recommendation: AlertRecommendation, alert: Alert}|null
     */
    public function approve(string $companyId, string $userId, string $recommendationId): ?array
    {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId): ?array {
            $recommendation = $this->lockedRecommendation($companyId, $recommendationId);
            if (! $recommendation) {
                return null;
            }

            $this->ensurePendingReview($recommendation);

            $alert = Alert::create([
                'company_id' => $recommendation->company_id,
                'alert_recommendation_id' => $recommendation->id,
                'rule_key' => $recommendation->alert_type,
                'severity' => $recommendation->severity,
                'title' => $recommendation->title,
                'detail' => $recommendation->summary,
                'evidence' => $this->alertEvidence($recommendation),
                'status' => 'open',
                'priority_score' => $this->priorityScore($recommendation->severity),
            ]);

            $recommendation->update([
                'status' => AlertRecommendation::STATUS_APPROVED,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
            ]);

            return [
                'recommendation' => $recommendation->fresh(['alert']),
                'alert' => $alert->fresh(['recommendation']),
            ];
        });
    }

    public function dismiss(string $companyId, string $userId, string $recommendationId, ?string $reviewNote = null): ?AlertRecommendation
    {
        return DB::transaction(function () use ($companyId, $userId, $recommendationId, $reviewNote): ?AlertRecommendation {
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

        return $evidence;
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
}
