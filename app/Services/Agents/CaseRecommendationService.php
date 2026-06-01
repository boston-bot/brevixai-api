<?php

namespace App\Services\Agents;

use App\Models\CaseRecommendation;
use App\Models\RecommendationReviewEvent;
use App\Services\RecommendationReviewAuditService;
use Illuminate\Support\Facades\Schema;

class CaseRecommendationService
{
    /** @var array<int, string> */
    private const MANAGED_CASE_TYPES = [
        'cross_domain_risk_investigation',
        'vendor_payment_reconciliation_investigation',
        'related_party_vendor_investigation',
        'entity_reconciliation_investigation',
    ];

    private const DOMAIN_VENDOR = 'vendor_risk';

    private const DOMAIN_RECONCILIATION = 'reconciliation_risk';

    private const DOMAIN_ENTITY_RELATIONSHIP = 'entity_relationship_risk';

    private const ELEVATED_DOMAIN_THRESHOLD = 40;

    private const HIGH_DOMAIN_THRESHOLD = 70;

    public function __construct(
        private readonly AggregateRiskSummaryService $aggregateRiskSummaryService,
        private readonly AlertRecommendationService $alertRecommendationService,
        private readonly VendorRiskScoringService $vendorRiskScoringService,
        private readonly ReconciliationRiskScoringService $reconciliationRiskScoringService,
        private readonly EntityRelationshipRiskScoringService $entityRelationshipRiskScoringService,
        private readonly RecommendationReviewAuditService $reviewAuditService,
    ) {}

    /**
     * Convert deterministic multi-domain risk signals into case recommendations.
     *
     * No LLM output is used in these calculations.
     *
     * @return array<string, mixed>
     */
    public function getCaseRecommendations(string $companyId, ?string $businessProfileId = null): array
    {
        $aggregateSummary = $this->aggregateRiskSummaryService->getAggregateRiskSummary($companyId, $businessProfileId);
        $alertRecommendations = $this->alertRecommendationService->getAlertRecommendations($companyId, $businessProfileId);
        $vendorScores = $this->vendorRiskScoringService->scoreAllVendors($companyId, $businessProfileId);
        $reconciliationRisk = $this->reconciliationRiskScoringService->scoreReconciliation($companyId, $businessProfileId);
        $entityRelationshipRisk = $this->entityRelationshipRiskScoringService->scoreEntityRelationships($companyId, $businessProfileId);

        $recommendations = $this->buildRecommendations(
            $aggregateSummary,
            $alertRecommendations['recommended_alerts'] ?? [],
            $vendorScores,
            $reconciliationRisk,
            $entityRelationshipRisk,
        );

        return [
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'case_recommendations' => $this->persistRecommendations($companyId, $recommendations, $businessProfileId),
        ];
    }

    /**
     * @param  array<string, mixed>  $aggregateSummary
     * @param  array<int, array<string, mixed>>  $alertRecommendations
     * @param  array<int, array<string, mixed>>  $vendorScores
     * @param  array<string, mixed>  $reconciliationRisk
     * @param  array<string, mixed>  $entityRelationshipRisk
     * @return array<int, array<string, mixed>>
     */
    private function buildRecommendations(
        array $aggregateSummary,
        array $alertRecommendations,
        array $vendorScores,
        array $reconciliationRisk,
        array $entityRelationshipRisk,
    ): array {
        $domainScores = $this->domainScores($aggregateSummary, $vendorScores, $reconciliationRisk, $entityRelationshipRisk);
        $sourceDomains = $this->elevatedSourceDomains($domainScores);

        if (! $this->shouldRecommendCase($aggregateSummary, $domainScores, $sourceDomains)) {
            return [];
        }

        $relatedAlertRecommendations = $this->relatedAlertRecommendations($alertRecommendations, $sourceDomains);
        $caseType = $this->caseType($sourceDomains);
        $severity = $this->severity((int) ($aggregateSummary['overall_risk_score'] ?? 0), $domainScores, $sourceDomains);

        return [[
            'case_type' => $caseType,
            'severity' => $severity,
            'title' => $this->title($sourceDomains),
            'summary' => $this->summary($sourceDomains, $domainScores, $aggregateSummary),
            'source_risk_domains' => $sourceDomains,
            'related_alert_recommendation_ids' => array_values(array_filter(array_map(
                fn (array $recommendation): ?string => isset($recommendation['id']) ? (string) $recommendation['id'] : null,
                $relatedAlertRecommendations,
            ))),
            'evidence' => $this->evidence(
                $aggregateSummary,
                $domainScores,
                $vendorScores,
                $reconciliationRisk,
                $entityRelationshipRisk,
                $relatedAlertRecommendations,
            ),
            'confidence_score' => $this->confidenceScore($aggregateSummary, $domainScores, $sourceDomains, $relatedAlertRecommendations),
            'requires_human_review' => true,
            'can_auto_create' => false,
        ]];
    }

    /**
     * @param  array<string, mixed>  $aggregateSummary
     * @param  array<int, array<string, mixed>>  $vendorScores
     * @param  array<string, mixed>  $reconciliationRisk
     * @param  array<string, mixed>  $entityRelationshipRisk
     * @return array<string, int>
     */
    private function domainScores(
        array $aggregateSummary,
        array $vendorScores,
        array $reconciliationRisk,
        array $entityRelationshipRisk,
    ): array {
        $aggregateDomains = $aggregateSummary['contributing_risk_domains'] ?? [];

        return [
            self::DOMAIN_VENDOR => max(
                $this->highestVendorRiskScore($vendorScores),
                (int) ($aggregateDomains[self::DOMAIN_VENDOR]['score'] ?? 0),
            ),
            self::DOMAIN_RECONCILIATION => max(
                (int) ($reconciliationRisk['reconciliation_risk_score'] ?? 0),
                (int) ($aggregateDomains[self::DOMAIN_RECONCILIATION]['score'] ?? 0),
            ),
            self::DOMAIN_ENTITY_RELATIONSHIP => max(
                (int) ($entityRelationshipRisk['entity_relationship_risk_score'] ?? 0),
                (int) ($aggregateDomains[self::DOMAIN_ENTITY_RELATIONSHIP]['score'] ?? 0),
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $vendorScores
     */
    private function highestVendorRiskScore(array $vendorScores): int
    {
        if ($vendorScores === []) {
            return 0;
        }

        return max(array_map(
            fn (array $vendor): int => (int) ($vendor['vendor_risk_score'] ?? 0),
            $vendorScores,
        ));
    }

    /**
     * @param  array<string, int>  $domainScores
     * @return array<int, string>
     */
    private function elevatedSourceDomains(array $domainScores): array
    {
        $domains = [];

        foreach ([self::DOMAIN_VENDOR, self::DOMAIN_RECONCILIATION, self::DOMAIN_ENTITY_RELATIONSHIP] as $domain) {
            if (($domainScores[$domain] ?? 0) >= self::ELEVATED_DOMAIN_THRESHOLD) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * @param  array<string, mixed>  $aggregateSummary
     * @param  array<string, int>  $domainScores
     * @param  array<int, string>  $sourceDomains
     */
    private function shouldRecommendCase(array $aggregateSummary, array $domainScores, array $sourceDomains): bool
    {
        if (count($sourceDomains) < 2) {
            return false;
        }

        $aggregateScore = (int) ($aggregateSummary['overall_risk_score'] ?? 0);
        $maxDomainScore = max($domainScores ?: [0]);
        $elevatedDomainScoreSum = array_sum(array_intersect_key($domainScores, array_flip($sourceDomains)));

        return $aggregateScore >= self::HIGH_DOMAIN_THRESHOLD
            || $maxDomainScore >= self::HIGH_DOMAIN_THRESHOLD
            || count($sourceDomains) >= 3
            || $elevatedDomainScoreSum >= 100;
    }

    /**
     * @param  array<int, array<string, mixed>>  $alertRecommendations
     * @param  array<int, string>  $sourceDomains
     * @return array<int, array<string, mixed>>
     */
    private function relatedAlertRecommendations(array $alertRecommendations, array $sourceDomains): array
    {
        return array_values(array_filter(
            $alertRecommendations,
            function (array $recommendation) use ($sourceDomains): bool {
                $sourceRiskDomain = (string) ($recommendation['source_risk_domain'] ?? '');

                return $sourceRiskDomain === 'aggregate_risk'
                    || in_array($sourceRiskDomain, $sourceDomains, true);
            },
        ));
    }

    /**
     * @param  array<int, string>  $sourceDomains
     */
    private function caseType(array $sourceDomains): string
    {
        if (count($sourceDomains) >= 3) {
            return 'cross_domain_risk_investigation';
        }

        if ($sourceDomains === [self::DOMAIN_VENDOR, self::DOMAIN_RECONCILIATION]) {
            return 'vendor_payment_reconciliation_investigation';
        }

        if ($sourceDomains === [self::DOMAIN_VENDOR, self::DOMAIN_ENTITY_RELATIONSHIP]) {
            return 'related_party_vendor_investigation';
        }

        return 'entity_reconciliation_investigation';
    }

    /**
     * @param  array<int, string>  $sourceDomains
     */
    private function title(array $sourceDomains): string
    {
        return match ($this->caseType($sourceDomains)) {
            'cross_domain_risk_investigation' => 'Investigate cross-domain risk signals',
            'vendor_payment_reconciliation_investigation' => 'Investigate vendor and reconciliation risk signals',
            'related_party_vendor_investigation' => 'Investigate related-party vendor risk signals',
            default => 'Investigate entity and reconciliation risk signals',
        };
    }

    /**
     * @param  array<int, string>  $sourceDomains
     * @param  array<string, int>  $domainScores
     * @param  array<string, mixed>  $aggregateSummary
     */
    private function summary(array $sourceDomains, array $domainScores, array $aggregateSummary): string
    {
        $domainLabels = implode(', ', array_map(fn (string $domain): string => str_replace('_', ' ', $domain), $sourceDomains));
        $aggregateScore = (int) ($aggregateSummary['overall_risk_score'] ?? max($domainScores ?: [0]));

        return "Deterministic scoring found elevated signals across {$domainLabels} with an aggregate risk score of {$aggregateScore}. Human review is required before creating an investigation case.";
    }

    /**
     * @param  array<string, mixed>  $aggregateSummary
     * @param  array<string, int>  $domainScores
     * @param  array<int, string>  $sourceDomains
     */
    private function severity(int $aggregateScore, array $domainScores, array $sourceDomains): string
    {
        $maxDomainScore = max($domainScores ?: [0]);

        return match (true) {
            $aggregateScore >= 90 || $maxDomainScore >= 90 || (count($sourceDomains) >= 3 && $maxDomainScore >= 70) => 'critical',
            $aggregateScore >= 70 || $maxDomainScore >= 70 || count($sourceDomains) >= 3 => 'high',
            default => 'medium',
        };
    }

    /**
     * @param  array<string, mixed>  $aggregateSummary
     * @param  array<string, int>  $domainScores
     * @param  array<int, string>  $sourceDomains
     * @param  array<int, array<string, mixed>>  $relatedAlertRecommendations
     */
    private function confidenceScore(
        array $aggregateSummary,
        array $domainScores,
        array $sourceDomains,
        array $relatedAlertRecommendations,
    ): float {
        $aggregateScore = (int) ($aggregateSummary['overall_risk_score'] ?? 0);
        $maxDomainScore = max($domainScores ?: [0]);

        $score = 0.60;
        $score += min(0.20, count($sourceDomains) * 0.07);

        if ($maxDomainScore >= self::HIGH_DOMAIN_THRESHOLD) {
            $score += 0.08;
        }

        if ($aggregateScore >= self::HIGH_DOMAIN_THRESHOLD) {
            $score += 0.05;
        }

        if (count($relatedAlertRecommendations) >= 2) {
            $score += 0.03;
        }

        return round(min(0.95, $score), 2);
    }

    /**
     * @param  array<string, mixed>  $aggregateSummary
     * @param  array<string, int>  $domainScores
     * @param  array<int, array<string, mixed>>  $vendorScores
     * @param  array<string, mixed>  $reconciliationRisk
     * @param  array<string, mixed>  $entityRelationshipRisk
     * @param  array<int, array<string, mixed>>  $relatedAlertRecommendations
     * @return array<string, mixed>
     */
    private function evidence(
        array $aggregateSummary,
        array $domainScores,
        array $vendorScores,
        array $reconciliationRisk,
        array $entityRelationshipRisk,
        array $relatedAlertRecommendations,
    ): array {
        return [
            'aggregate_risk_summary' => [
                'overall_risk_score' => (int) ($aggregateSummary['overall_risk_score'] ?? 0),
                'overall_risk_level' => $aggregateSummary['overall_risk_level'] ?? 'low',
                'contributing_risk_domains' => $aggregateSummary['contributing_risk_domains'] ?? [],
                'triggered_rules_summary' => $aggregateSummary['triggered_rules_summary'] ?? [],
                'highest_risk_findings' => array_slice($aggregateSummary['highest_risk_findings'] ?? [], 0, 5),
            ],
            'domain_scores' => $domainScores,
            'vendor_risk' => [
                'top_flagged_vendors' => $this->topFlaggedVendors($vendorScores),
            ],
            'reconciliation_risk' => [
                'reconciliation_risk_score' => (int) ($reconciliationRisk['reconciliation_risk_score'] ?? 0),
                'risk_level' => $reconciliationRisk['risk_level'] ?? 'low',
                'triggered_rules' => $reconciliationRisk['triggered_rules'] ?? [],
                'supporting_evidence' => $reconciliationRisk['supporting_evidence'] ?? [],
            ],
            'entity_relationship_risk' => [
                'entity_relationship_risk_score' => (int) ($entityRelationshipRisk['entity_relationship_risk_score'] ?? 0),
                'risk_level' => $entityRelationshipRisk['risk_level'] ?? 'low',
                'triggered_rules' => $entityRelationshipRisk['triggered_rules'] ?? [],
                'supporting_evidence' => $entityRelationshipRisk['supporting_evidence'] ?? [],
                'related_entities' => $entityRelationshipRisk['related_entities'] ?? [],
            ],
            'alert_recommendations' => array_map(
                fn (array $recommendation): array => [
                    'id' => $recommendation['id'] ?? null,
                    'alert_type' => $recommendation['alert_type'] ?? null,
                    'severity' => $recommendation['severity'] ?? null,
                    'source_risk_domain' => $recommendation['source_risk_domain'] ?? null,
                    'source_rule_ids' => $recommendation['source_rule_ids'] ?? [],
                    'confidence_score' => $recommendation['confidence_score'] ?? 0,
                ],
                $relatedAlertRecommendations,
            ),
            'deterministic_rules' => [
                'minimum_elevated_domains' => 2,
                'elevated_domain_threshold' => self::ELEVATED_DOMAIN_THRESHOLD,
                'high_domain_threshold' => self::HIGH_DOMAIN_THRESHOLD,
                'minimum_elevated_score_sum_without_high_domain' => 100,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $vendorScores
     * @return array<int, array<string, mixed>>
     */
    private function topFlaggedVendors(array $vendorScores): array
    {
        $flagged = array_values(array_filter(
            $vendorScores,
            fn (array $vendor): bool => (int) ($vendor['vendor_risk_score'] ?? 0) >= self::ELEVATED_DOMAIN_THRESHOLD,
        ));

        usort($flagged, function (array $a, array $b): int {
            return [-(int) ($a['vendor_risk_score'] ?? 0), (string) ($a['vendor_name'] ?? '')]
                <=> [-(int) ($b['vendor_risk_score'] ?? 0), (string) ($b['vendor_name'] ?? '')];
        });

        return array_map(
            fn (array $vendor): array => [
                'vendor_name' => $vendor['vendor_name'] ?? null,
                'vendor_risk_score' => (int) ($vendor['vendor_risk_score'] ?? 0),
                'risk_level' => $vendor['risk_level'] ?? 'low',
                'triggered_rules' => $vendor['triggered_rules'] ?? [],
                'supporting_evidence' => $vendor['supporting_evidence'] ?? [],
            ],
            array_slice($flagged, 0, 3),
        );
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
            $caseType = (string) ($recommendation['case_type'] ?? '');
            $activeKeys[] = $caseType;

            $record = CaseRecommendation::where('company_id', $companyId)
                ->when($businessProfileId && Schema::hasColumn('case_recommendations', 'business_profile_id'), fn ($query) => $query->where('business_profile_id', $businessProfileId))
                ->where('case_type', $caseType)
                ->where('status', CaseRecommendation::STATUS_PENDING_REVIEW)
                ->first();

            if (! $record) {
                $record = new CaseRecommendation([
                    'company_id' => $companyId,
                    'case_type' => $caseType,
                    'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
                ]);
            }

            if ($businessProfileId && Schema::hasColumn('case_recommendations', 'business_profile_id')) {
                $record->business_profile_id = $businessProfileId;
            }

            $isNewRecommendation = ! $record->exists;

            $record->fill([
                'severity' => (string) ($recommendation['severity'] ?? 'medium'),
                'title' => (string) ($recommendation['title'] ?? 'Review case recommendation'),
                'summary' => (string) ($recommendation['summary'] ?? ''),
                'source_risk_domains' => is_array($recommendation['source_risk_domains'] ?? null) ? $recommendation['source_risk_domains'] : [],
                'related_alert_recommendation_ids' => is_array($recommendation['related_alert_recommendation_ids'] ?? null) ? $recommendation['related_alert_recommendation_ids'] : [],
                'evidence' => is_array($recommendation['evidence'] ?? null) ? $recommendation['evidence'] : [],
                'confidence_score' => (float) ($recommendation['confidence_score'] ?? 0),
                'requires_human_review' => true,
                'can_auto_create' => false,
                'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
            ]);
            $record->save();

            if ($isNewRecommendation) {
                $this->reviewAuditService->record(
                    companyId: $companyId,
                    recommendationType: RecommendationReviewEvent::TYPE_CASE,
                    recommendationId: $record->id,
                    eventType: RecommendationReviewEvent::EVENT_CREATED,
                    actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
                    metadata: [
                        'case_type' => $record->case_type,
                        'severity' => $record->severity,
                        'source_risk_domains' => $record->source_risk_domains ?? [],
                        'related_alert_recommendation_ids' => $record->related_alert_recommendation_ids ?? [],
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
        CaseRecommendation::where('company_id', $companyId)
            ->when($businessProfileId && Schema::hasColumn('case_recommendations', 'business_profile_id'), fn ($query) => $query->where('business_profile_id', $businessProfileId))
            ->where('status', CaseRecommendation::STATUS_PENDING_REVIEW)
            ->whereIn('case_type', self::MANAGED_CASE_TYPES)
            ->get()
            ->each(function (CaseRecommendation $recommendation) use ($activeKeys, $businessProfileId): void {
                if (! in_array($recommendation->case_type, $activeKeys, true)) {
                    $recommendation->update(['status' => CaseRecommendation::STATUS_EXPIRED]);

                    $this->reviewAuditService->record(
                        companyId: $recommendation->company_id,
                        recommendationType: RecommendationReviewEvent::TYPE_CASE,
                        recommendationId: $recommendation->id,
                        eventType: RecommendationReviewEvent::EVENT_EXPIRED,
                        actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
                        metadata: [
                            'case_type' => $recommendation->case_type,
                            'source_risk_domains' => $recommendation->source_risk_domains ?? [],
                        ],
                        businessProfileId: $businessProfileId,
                    );
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function recommendationPayload(CaseRecommendation $recommendation): array
    {
        return [
            'id' => $recommendation->id,
            'business_profile_id' => $recommendation->business_profile_id ?? null,
            'case_type' => $recommendation->case_type,
            'severity' => $recommendation->severity,
            'title' => $recommendation->title,
            'summary' => $recommendation->summary,
            'source_risk_domains' => $recommendation->source_risk_domains ?? [],
            'related_alert_recommendation_ids' => $recommendation->related_alert_recommendation_ids ?? [],
            'evidence' => $recommendation->evidence ?? [],
            'confidence_score' => $recommendation->confidence_score,
            'requires_human_review' => true,
            'can_auto_create' => false,
            'status' => $recommendation->status,
            'case_id' => $recommendation->auditCase?->id,
        ];
    }
}
