<?php

namespace App\Services\Agents;

class AggregateRiskSummaryService
{
    private const RISK_DOMAIN_VENDOR = 'vendor_risk';

    private const RISK_DOMAIN_RECONCILIATION = 'reconciliation_risk';

    private const RISK_DOMAIN_ENTITY_RELATIONSHIP = 'entity_relationship_risk';

    public function __construct(
        private readonly VendorRiskScoringService $vendorRiskService,
        private readonly ReconciliationRiskScoringService $reconciliationRiskService,
        private readonly EntityRelationshipRiskScoringService $entityRelationshipRiskService,
    ) {}

    /**
     * Compute a comprehensive, explainable aggregate risk summary for a company.
     *
     * @return array<string, mixed>
     */
    public function getAggregateRiskSummary(string $companyId, ?string $businessProfileId = null): array
    {
        $vendorScores = $this->vendorRiskService->scoreAllVendors($companyId, $businessProfileId);
        $vendorRiskScore = $this->highestVendorRiskScore($vendorScores);

        $reconciliationResult = $this->reconciliationRiskService->scoreReconciliation($companyId, $businessProfileId);
        $reconciliationRiskScore = (int) ($reconciliationResult['reconciliation_risk_score'] ?? 0);

        $entityResult = $this->entityRelationshipRiskService->scoreEntityRelationships($companyId, $businessProfileId);
        $entityRiskScore = (int) ($entityResult['entity_relationship_risk_score'] ?? 0);

        $overallScore = max($vendorRiskScore, $reconciliationRiskScore, $entityRiskScore);

        $contributingRiskDomains = [
            self::RISK_DOMAIN_VENDOR => [
                'score' => $vendorRiskScore,
                'risk_level' => $this->mapRiskLevel($vendorRiskScore),
            ],
            self::RISK_DOMAIN_RECONCILIATION => [
                'score' => $reconciliationRiskScore,
                'risk_level' => $this->mapRiskLevel($reconciliationRiskScore),
            ],
            self::RISK_DOMAIN_ENTITY_RELATIONSHIP => [
                'score' => $entityRiskScore,
                'risk_level' => $this->mapRiskLevel($entityRiskScore),
            ],
        ];

        $triggeredRulesSummary = [
            self::RISK_DOMAIN_VENDOR => $this->uniqueVendorRuleCount($vendorScores),
            self::RISK_DOMAIN_RECONCILIATION => count($reconciliationResult['triggered_rules'] ?? []),
            self::RISK_DOMAIN_ENTITY_RELATIONSHIP => count($entityResult['triggered_rules'] ?? []),
        ];

        $supportingEvidenceSummary = [
            self::RISK_DOMAIN_VENDOR => [
                'total_vendors_analyzed' => count($vendorScores),
                'flagged_vendors' => count(array_filter(
                    $vendorScores,
                    fn (array $vendor): bool => (int) ($vendor['vendor_risk_score'] ?? 0) >= 40
                )),
            ],
            self::RISK_DOMAIN_RECONCILIATION => [
                'triggered_anomalies' => count($reconciliationResult['triggered_rules'] ?? []),
                'stale_unreconciled_items_count' => count(
                    $reconciliationResult['supporting_evidence']['stale_unreconciled']['discrepancies'] ?? []
                ),
            ],
            self::RISK_DOMAIN_ENTITY_RELATIONSHIP => [
                'overlapping_employees_count' => count($entityResult['supporting_evidence']['employee_vendor_overlap']['overlaps'] ?? []),
                'duplicate_vendor_identity_clusters_count' => count($entityResult['supporting_evidence']['duplicate_vendor_cluster']['clusters'] ?? []),
            ],
        ];

        return [
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'overall_risk_score' => $overallScore,
            'overall_risk_level' => $this->mapRiskLevel($overallScore),
            'contributing_risk_domains' => $contributingRiskDomains,
            'highest_risk_findings' => $this->highestRiskFindings($vendorScores, $reconciliationResult, $entityResult),
            'triggered_rules_summary' => $triggeredRulesSummary,
            'recommended_next_actions' => $this->recommendedNextActions(
                $overallScore,
                $vendorScores,
                $reconciliationRiskScore,
                $entityRiskScore,
            ),
            'supporting_evidence_summary' => $supportingEvidenceSummary,
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
            $vendorScores
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $vendorScores
     */
    private function uniqueVendorRuleCount(array $vendorScores): int
    {
        $uniqueVendorRules = [];

        foreach ($vendorScores as $vendorScore) {
            foreach ($vendorScore['triggered_rules'] ?? [] as $rule) {
                $uniqueVendorRules[(string) ($rule['rule_key'] ?? 'unknown_rule')] = true;
            }
        }

        return count($uniqueVendorRules);
    }

    /**
     * @param  array<int, array<string, mixed>>  $vendorScores
     * @param  array<string, mixed>  $reconciliationResult
     * @param  array<string, mixed>  $entityResult
     * @return array<int, array<string, mixed>>
     */
    private function highestRiskFindings(
        array $vendorScores,
        array $reconciliationResult,
        array $entityResult,
    ): array {
        $findings = [];

        foreach ($vendorScores as $vendorScore) {
            foreach ($vendorScore['triggered_rules'] ?? [] as $rule) {
                $findings[] = $this->riskFinding(
                    self::RISK_DOMAIN_VENDOR,
                    (string) ($vendorScore['vendor_name'] ?? 'Unknown Vendor'),
                    $rule,
                );
            }
        }

        foreach ($reconciliationResult['triggered_rules'] ?? [] as $rule) {
            $findings[] = $this->riskFinding(
                self::RISK_DOMAIN_RECONCILIATION,
                'General Ledger / Bank Statement',
                $rule,
            );
        }

        foreach ($entityResult['triggered_rules'] ?? [] as $rule) {
            $findings[] = $this->riskFinding(
                self::RISK_DOMAIN_ENTITY_RELATIONSHIP,
                'Entity Graph / Metadata',
                $rule,
            );
        }

        usort($findings, function (array $a, array $b): int {
            return [-(int) $a['weight'], $a['domain'], $a['source'], $a['rule_key']]
                <=> [-(int) $b['weight'], $b['domain'], $b['source'], $b['rule_key']];
        });

        return array_slice($findings, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function riskFinding(string $domain, string $source, array $rule): array
    {
        return [
            'domain' => $domain,
            'source' => $source,
            'rule_key' => (string) ($rule['rule_key'] ?? 'unknown_rule'),
            'name' => (string) ($rule['name'] ?? 'Unknown Rule'),
            'weight' => (int) ($rule['weight'] ?? 0),
            'explanation' => (string) ($rule['explanation'] ?? ''),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $vendorScores
     * @return array<int, string>
     */
    private function recommendedNextActions(
        int $overallScore,
        array $vendorScores,
        int $reconciliationRiskScore,
        int $entityRiskScore,
    ): array {
        $actions = [];

        if ($overallScore >= 90) {
            $actions[] = 'Require human review before any pending disbursements tied to the highest-risk findings are approved.';
        }

        $highRiskVendors = array_filter(
            $vendorScores,
            fn (array $vendor): bool => (int) ($vendor['vendor_risk_score'] ?? 0) >= 70
        );

        if ($highRiskVendors !== []) {
            $names = array_slice(array_filter(array_column($highRiskVendors, 'vendor_name')), 0, 3);
            $actions[] = $names === []
                ? 'Audit credentials and onboarding files for high-risk vendors identified by deterministic vendor risk scoring.'
                : 'Audit credentials and onboarding files for high-risk vendors: '.implode(', ', $names).'.';
        }

        if ($reconciliationRiskScore >= 40) {
            $actions[] = 'Review manual general ledger adjustments and reconcile stale unmatched balances.';
        }

        if ($entityRiskScore >= 40) {
            $actions[] = 'Review duplicate vendor profiles and employee-vendor relationship conflicts.';
        }

        if ($actions === []) {
            $actions[] = 'Continue routine automated continuous monitoring and ledger hygiene audits.';
        }

        return $actions;
    }

    private function mapRiskLevel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'critical',
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }
}
