<?php

namespace App\Services\Agents;

use App\Models\AlertRecommendation;
use App\Models\RecommendationReviewEvent;
use App\Services\RecommendationReviewAuditService;
use Illuminate\Support\Facades\Schema;

class AlertRecommendationService
{
    /** @var array<int, string> */
    private const MANAGED_ALERT_TYPES = [
        'critical_aggregate_risk_review',
        'vendor_risk_review',
        'reconciliation_risk_review',
        'entity_relationship_review',
    ];

    private const DOMAIN_VENDOR = 'vendor_risk';

    private const DOMAIN_RECONCILIATION = 'reconciliation_risk';

    private const DOMAIN_ENTITY_RELATIONSHIP = 'entity_relationship_risk';

    public function __construct(
        private readonly AggregateRiskSummaryService $aggregateRiskSummaryService,
        private readonly VendorRiskScoringService $vendorRiskScoringService,
        private readonly ReconciliationRiskScoringService $reconciliationRiskScoringService,
        private readonly EntityRelationshipRiskScoringService $entityRelationshipRiskScoringService,
        private readonly RecommendationReviewAuditService $reviewAuditService,
    ) {}

    /**
     * Convert deterministic risk findings into alert recommendations.
     *
     * @return array<string, mixed>
     */
    public function getAlertRecommendations(string $companyId, ?string $businessProfileId = null): array
    {
        $aggregateSummary = $this->aggregateRiskSummaryService->getAggregateRiskSummary($companyId, $businessProfileId);
        $vendorScores = $this->vendorRiskScoringService->scoreAllVendors($companyId, $businessProfileId);
        $reconciliationRisk = $this->reconciliationRiskScoringService->scoreReconciliation($companyId, $businessProfileId);
        $entityRelationshipRisk = $this->entityRelationshipRiskScoringService->scoreEntityRelationships($companyId, $businessProfileId);

        $recommendations = [];

        if ((int) ($aggregateSummary['overall_risk_score'] ?? 0) >= 90) {
            $recommendations[] = $this->aggregateRecommendation($aggregateSummary);
        }

        $vendorRecommendation = $this->vendorRecommendation($vendorScores);
        if ($vendorRecommendation !== null) {
            $recommendations[] = $vendorRecommendation;
        }

        $reconciliationRecommendation = $this->reconciliationRecommendation($reconciliationRisk);
        if ($reconciliationRecommendation !== null) {
            $recommendations[] = $reconciliationRecommendation;
        }

        $entityRecommendation = $this->entityRelationshipRecommendation($entityRelationshipRisk);
        if ($entityRecommendation !== null) {
            $recommendations[] = $entityRecommendation;
        }

        $persistedRecommendations = $this->persistRecommendations($companyId, $recommendations, $businessProfileId);

        return [
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'recommended_alerts' => $persistedRecommendations,
        ];
    }

    /**
     * @param  array<string, mixed>  $aggregateSummary
     * @return array<string, mixed>
     */
    private function aggregateRecommendation(array $aggregateSummary): array
    {
        $overallScore = (int) ($aggregateSummary['overall_risk_score'] ?? 0);
        $findings = $aggregateSummary['highest_risk_findings'] ?? [];

        return $this->recommendation(
            alertType: 'critical_aggregate_risk_review',
            severity: 'critical',
            title: 'Review critical aggregate risk signals',
            summary: 'Aggregate deterministic scoring identified critical cross-domain risk that requires human review.',
            evidence: [
                'overall_risk_score' => $overallScore,
                'overall_risk_level' => $aggregateSummary['overall_risk_level'] ?? 'critical',
                'contributing_risk_domains' => $aggregateSummary['contributing_risk_domains'] ?? [],
                'highest_risk_findings' => array_slice($findings, 0, 5),
            ],
            sourceRiskDomain: 'aggregate_risk',
            sourceRuleIds: $this->ruleIds($findings),
            confidenceScore: $this->confidenceScore($overallScore),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $vendorScores
     * @return array<string, mixed>|null
     */
    private function vendorRecommendation(array $vendorScores): ?array
    {
        $flaggedVendors = array_values(array_filter(
            $vendorScores,
            fn (array $vendor): bool => (int) ($vendor['vendor_risk_score'] ?? 0) >= 40
        ));

        if ($flaggedVendors === []) {
            return null;
        }

        usort($flaggedVendors, function (array $a, array $b): int {
            return [-(int) ($a['vendor_risk_score'] ?? 0), (string) ($a['vendor_name'] ?? '')]
                <=> [-(int) ($b['vendor_risk_score'] ?? 0), (string) ($b['vendor_name'] ?? '')];
        });

        $highestScore = (int) ($flaggedVendors[0]['vendor_risk_score'] ?? 0);
        $sourceRules = $this->ruleIdsFromDomainResults($flaggedVendors);
        $vendorNames = array_values(array_filter(array_map(
            fn (array $vendor): ?string => isset($vendor['vendor_name']) ? (string) $vendor['vendor_name'] : null,
            $flaggedVendors
        )));

        return $this->recommendation(
            alertType: 'vendor_risk_review',
            severity: $this->severity($highestScore),
            title: 'Review vendor risk signals',
            summary: 'Vendor risk scoring identified deterministic payment or vendor-profile patterns that require human review.',
            evidence: [
                'highest_vendor_risk_score' => $highestScore,
                'flagged_vendor_count' => count($flaggedVendors),
                'flagged_vendors' => array_slice($vendorNames, 0, 5),
                'top_vendor_findings' => array_slice($flaggedVendors, 0, 3),
            ],
            sourceRiskDomain: self::DOMAIN_VENDOR,
            sourceRuleIds: $sourceRules,
            confidenceScore: $this->confidenceScore($highestScore),
        );
    }

    /**
     * @param  array<string, mixed>  $reconciliationRisk
     * @return array<string, mixed>|null
     */
    private function reconciliationRecommendation(array $reconciliationRisk): ?array
    {
        $triggeredRules = $reconciliationRisk['triggered_rules'] ?? [];
        if ($triggeredRules === []) {
            return null;
        }

        $score = (int) ($reconciliationRisk['reconciliation_risk_score'] ?? 0);

        return $this->recommendation(
            alertType: 'reconciliation_risk_review',
            severity: $this->severity($score),
            title: 'Review reconciliation risk signals',
            summary: 'Reconciliation scoring identified deterministic mismatch or manual-adjustment signals that require human review.',
            evidence: [
                'reconciliation_risk_score' => $score,
                'risk_level' => $reconciliationRisk['risk_level'] ?? $this->riskLevel($score),
                'triggered_rules' => $triggeredRules,
                'supporting_evidence' => $reconciliationRisk['supporting_evidence'] ?? [],
            ],
            sourceRiskDomain: self::DOMAIN_RECONCILIATION,
            sourceRuleIds: $this->ruleIds($triggeredRules),
            confidenceScore: $this->confidenceScore($score),
        );
    }

    /**
     * @param  array<string, mixed>  $entityRelationshipRisk
     * @return array<string, mixed>|null
     */
    private function entityRelationshipRecommendation(array $entityRelationshipRisk): ?array
    {
        $triggeredRules = $entityRelationshipRisk['triggered_rules'] ?? [];
        if ($triggeredRules === []) {
            return null;
        }

        $score = (int) ($entityRelationshipRisk['entity_relationship_risk_score'] ?? 0);

        return $this->recommendation(
            alertType: 'entity_relationship_review',
            severity: $this->severity($score),
            title: 'Review entity relationship risk signals',
            summary: 'Entity relationship scoring identified deterministic overlap or shared-entity indicators that require human review.',
            evidence: [
                'entity_relationship_risk_score' => $score,
                'risk_level' => $entityRelationshipRisk['risk_level'] ?? $this->riskLevel($score),
                'triggered_rules' => $triggeredRules,
                'supporting_evidence' => $entityRelationshipRisk['supporting_evidence'] ?? [],
                'related_entities' => $entityRelationshipRisk['related_entities'] ?? [],
            ],
            sourceRiskDomain: self::DOMAIN_ENTITY_RELATIONSHIP,
            sourceRuleIds: $this->ruleIds($triggeredRules),
            confidenceScore: $this->confidenceScore($score),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $domainResults
     * @return array<int, string>
     */
    private function ruleIdsFromDomainResults(array $domainResults): array
    {
        $rules = [];

        foreach ($domainResults as $result) {
            foreach ($result['triggered_rules'] ?? [] as $rule) {
                if (isset($rule['rule_key'])) {
                    $rules[] = (string) $rule['rule_key'];
                }
            }
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, string>
     */
    private function ruleIds(array $rules): array
    {
        $ruleIds = [];

        foreach ($rules as $rule) {
            if (isset($rule['rule_key'])) {
                $ruleIds[] = (string) $rule['rule_key'];
            }
        }

        return array_values(array_unique($ruleIds));
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<int, string>  $sourceRuleIds
     * @return array<string, mixed>
     */
    private function recommendation(
        string $alertType,
        string $severity,
        string $title,
        string $summary,
        array $evidence,
        string $sourceRiskDomain,
        array $sourceRuleIds,
        float $confidenceScore,
    ): array {
        return [
            'alert_type' => $alertType,
            'severity' => $severity,
            'title' => $title,
            'summary' => $summary,
            'evidence' => $evidence,
            'source_risk_domain' => $sourceRiskDomain,
            'source_rule_ids' => $sourceRuleIds,
            'confidence_score' => $confidenceScore,
            'requires_human_review' => true,
            'can_auto_create' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $recommendations
     * @return array<int, array<string, mixed>>
     */
    private function persistRecommendations(string $companyId, array $recommendations, ?string $businessProfileId = null): array
    {
        $persisted = [];
        $activeKeys = [];

        foreach ($recommendations as $recommendation) {
            $alertType = (string) ($recommendation['alert_type'] ?? '');
            $sourceRiskDomain = (string) ($recommendation['source_risk_domain'] ?? '');
            $activeKeys[] = $this->recommendationKey($alertType, $sourceRiskDomain);

            $record = AlertRecommendation::where('company_id', $companyId)
                ->when($businessProfileId && Schema::hasColumn('alert_recommendations', 'business_profile_id'), fn ($query) => $query->where('business_profile_id', $businessProfileId))
                ->where('alert_type', $alertType)
                ->where('source_risk_domain', $sourceRiskDomain)
                ->where('status', AlertRecommendation::STATUS_PENDING_REVIEW)
                ->first();

            if (! $record) {
                $record = new AlertRecommendation([
                    'company_id' => $companyId,
                    'alert_type' => $alertType,
                    'source_risk_domain' => $sourceRiskDomain,
                    'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
                ]);
            }

            if ($businessProfileId && Schema::hasColumn('alert_recommendations', 'business_profile_id')) {
                $record->business_profile_id = $businessProfileId;
            }

            $isNewRecommendation = ! $record->exists;

            $record->fill([
                'severity' => (string) ($recommendation['severity'] ?? 'medium'),
                'title' => (string) ($recommendation['title'] ?? 'Review alert recommendation'),
                'summary' => (string) ($recommendation['summary'] ?? ''),
                'evidence' => is_array($recommendation['evidence'] ?? null) ? $recommendation['evidence'] : [],
                'source_rule_ids' => is_array($recommendation['source_rule_ids'] ?? null) ? $recommendation['source_rule_ids'] : [],
                'confidence_score' => (float) ($recommendation['confidence_score'] ?? 0),
                'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
            ]);
            $record->save();

            if ($isNewRecommendation) {
                $this->reviewAuditService->record(
                    companyId: $companyId,
                    recommendationType: RecommendationReviewEvent::TYPE_ALERT,
                    recommendationId: $record->id,
                    eventType: RecommendationReviewEvent::EVENT_CREATED,
                    actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
                    metadata: [
                        'alert_type' => $record->alert_type,
                        'severity' => $record->severity,
                        'source_risk_domain' => $record->source_risk_domain,
                        'source_rule_ids' => $record->source_rule_ids ?? [],
                        'confidence_score' => $record->confidence_score,
                    ],
                    businessProfileId: $businessProfileId,
                );
            }

            $persisted[] = $this->recommendationPayload($record);
        }

        $this->expireStalePendingRecommendations($companyId, $activeKeys, $businessProfileId);

        return $persisted;
    }

    /**
     * @param  array<int, string>  $activeKeys
     */
    private function expireStalePendingRecommendations(string $companyId, array $activeKeys, ?string $businessProfileId = null): void
    {
        AlertRecommendation::where('company_id', $companyId)
            ->when($businessProfileId && Schema::hasColumn('alert_recommendations', 'business_profile_id'), fn ($query) => $query->where('business_profile_id', $businessProfileId))
            ->where('status', AlertRecommendation::STATUS_PENDING_REVIEW)
            ->whereIn('alert_type', self::MANAGED_ALERT_TYPES)
            ->get()
            ->each(function (AlertRecommendation $recommendation) use ($activeKeys, $businessProfileId): void {
                if (! in_array($this->recommendationKey($recommendation->alert_type, $recommendation->source_risk_domain), $activeKeys, true)) {
                    $recommendation->update(['status' => AlertRecommendation::STATUS_EXPIRED]);

                    $this->reviewAuditService->record(
                        companyId: $recommendation->company_id,
                        recommendationType: RecommendationReviewEvent::TYPE_ALERT,
                        recommendationId: $recommendation->id,
                        eventType: RecommendationReviewEvent::EVENT_EXPIRED,
                        actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
                        metadata: [
                            'alert_type' => $recommendation->alert_type,
                            'source_risk_domain' => $recommendation->source_risk_domain,
                        ],
                        businessProfileId: $businessProfileId,
                    );
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function recommendationPayload(AlertRecommendation $recommendation): array
    {
        return [
            'id' => $recommendation->id,
            'business_profile_id' => $recommendation->business_profile_id ?? null,
            'alert_type' => $recommendation->alert_type,
            'severity' => $recommendation->severity,
            'title' => $recommendation->title,
            'summary' => $recommendation->summary,
            'evidence' => $recommendation->evidence ?? [],
            'source_risk_domain' => $recommendation->source_risk_domain,
            'source_rule_ids' => $recommendation->source_rule_ids ?? [],
            'confidence_score' => $recommendation->confidence_score,
            'status' => $recommendation->status,
            'requires_human_review' => true,
            'can_auto_create' => false,
            // Moat Visibility Fields
            'reasonCodes' => $recommendation->source_rule_ids ?? [],
            'sourceSystem' => $recommendation->source_risk_domain,
            'evidenceRefs' => $recommendation->evidence ?? [],
            'confidenceScore' => $recommendation->confidence_score,
            'deterministicCheckName' => $recommendation->alert_type,
            'comparisonWindow' => null,
            'sourceFreshness' => $recommendation->created_at?->diffForHumans(),
            'humanReviewStatus' => $recommendation->status,
        ];
    }

    private function recommendationKey(string $alertType, string $sourceRiskDomain): string
    {
        return "{$sourceRiskDomain}:{$alertType}";
    }

    private function severity(int $score): string
    {
        return match (true) {
            $score >= 90 => 'critical',
            $score >= 70 => 'high',
            default => 'medium',
        };
    }

    private function riskLevel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'critical',
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }

    private function confidenceScore(int $score): float
    {
        return match (true) {
            $score >= 90 => 0.95,
            $score >= 70 => 0.90,
            $score >= 40 => 0.75,
            default => 0.60,
        };
    }
}
