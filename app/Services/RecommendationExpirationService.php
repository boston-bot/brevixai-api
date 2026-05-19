<?php

namespace App\Services;

use App\Models\AlertRecommendation;
use App\Models\CaseRecommendation;
use App\Models\RecommendationReviewEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RecommendationExpirationService
{
    public function __construct(
        private readonly RecommendationReviewAuditService $reviewAuditService,
    ) {}

    /**
     * @return array{expiration_days: int, cutoff: string, alert_expired: int, case_expired: int, total_expired: int}
     */
    public function expirePending(int $expirationDays): array
    {
        $cutoff = now()->subDays($expirationDays);
        $alertExpired = $this->expireAlertRecommendations($cutoff, $expirationDays);
        $caseExpired = $this->expireCaseRecommendations($cutoff, $expirationDays);

        return [
            'expiration_days' => $expirationDays,
            'cutoff' => $cutoff->toIso8601String(),
            'alert_expired' => $alertExpired,
            'case_expired' => $caseExpired,
            'total_expired' => $alertExpired + $caseExpired,
        ];
    }

    private function expireAlertRecommendations(CarbonInterface $cutoff, int $expirationDays): int
    {
        $expired = 0;

        AlertRecommendation::query()
            ->where('status', AlertRecommendation::STATUS_PENDING_REVIEW)
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($recommendations) use (&$expired, $cutoff, $expirationDays): void {
                foreach ($recommendations as $recommendation) {
                    DB::transaction(function () use (&$expired, $recommendation, $cutoff, $expirationDays): void {
                        $updated = AlertRecommendation::query()
                            ->whereKey($recommendation->id)
                            ->where('status', AlertRecommendation::STATUS_PENDING_REVIEW)
                            ->where('created_at', '<', $cutoff)
                            ->update([
                                'status' => AlertRecommendation::STATUS_EXPIRED,
                                'updated_at' => now(),
                            ]);

                        if ($updated !== 1) {
                            return;
                        }

                        $this->reviewAuditService->record(
                            companyId: $recommendation->company_id,
                            recommendationType: RecommendationReviewEvent::TYPE_ALERT,
                            recommendationId: $recommendation->id,
                            eventType: RecommendationReviewEvent::EVENT_EXPIRED,
                            actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
                            metadata: [
                                'alert_type' => $recommendation->alert_type,
                                'source_risk_domain' => $recommendation->source_risk_domain,
                                'severity' => $recommendation->severity,
                                'expiration_days' => $expirationDays,
                                'expired_before' => $cutoff->toIso8601String(),
                            ],
                        );

                        $expired++;
                    });
                }
            });

        return $expired;
    }

    private function expireCaseRecommendations(CarbonInterface $cutoff, int $expirationDays): int
    {
        $expired = 0;

        CaseRecommendation::query()
            ->where('status', CaseRecommendation::STATUS_PENDING_REVIEW)
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($recommendations) use (&$expired, $cutoff, $expirationDays): void {
                foreach ($recommendations as $recommendation) {
                    DB::transaction(function () use (&$expired, $recommendation, $cutoff, $expirationDays): void {
                        $updated = CaseRecommendation::query()
                            ->whereKey($recommendation->id)
                            ->where('status', CaseRecommendation::STATUS_PENDING_REVIEW)
                            ->where('created_at', '<', $cutoff)
                            ->update([
                                'status' => CaseRecommendation::STATUS_EXPIRED,
                                'updated_at' => now(),
                            ]);

                        if ($updated !== 1) {
                            return;
                        }

                        $this->reviewAuditService->record(
                            companyId: $recommendation->company_id,
                            recommendationType: RecommendationReviewEvent::TYPE_CASE,
                            recommendationId: $recommendation->id,
                            eventType: RecommendationReviewEvent::EVENT_EXPIRED,
                            actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
                            metadata: [
                                'case_type' => $recommendation->case_type,
                                'source_risk_domains' => $recommendation->source_risk_domains ?? [],
                                'severity' => $recommendation->severity,
                                'expiration_days' => $expirationDays,
                                'expired_before' => $cutoff->toIso8601String(),
                            ],
                        );

                        $expired++;
                    });
                }
            });

        return $expired;
    }
}
