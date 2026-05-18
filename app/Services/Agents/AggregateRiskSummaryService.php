<?php

namespace App\Services\Agents;

class AggregateRiskSummaryService
{
    /**
     * Compute a comprehensive, explainable aggregate risk summary for a company.
     */
    public function getAggregateRiskSummary(string $companyId): array
    {
        $vendorRiskService = app(VendorRiskScoringService::class);
        $reconciliationRiskService = app(ReconciliationRiskScoringService::class);
        $entityRelationshipRiskService = app(EntityRelationshipRiskScoringService::class);

        // Fetch each domain score
        $vendorScores = $vendorRiskService->scoreAllVendors($companyId);
        $vendorRiskScore = !empty($vendorScores) ? (int)max(array_column($vendorScores, 'vendor_risk_score')) : 0;

        $reconciliationResult = $reconciliationRiskService->scoreReconciliation($companyId);
        $reconciliationRiskScore = (int)$reconciliationResult['reconciliation_risk_score'];

        $entityResult = $entityRelationshipRiskService->scoreEntityRelationships($companyId);
        $entityRiskScore = (int)$entityResult['entity_relationship_risk_score'];

        // Compute overall risk score as the maximum of all domains
        $overallScore = max($vendorRiskScore, $reconciliationRiskScore, $entityRiskScore);

        $overallRiskLevel = 'low';
        if ($overallScore >= 90) {
            $overallRiskLevel = 'critical';
        } elseif ($overallScore >= 70) {
            $overallRiskLevel = 'high';
        } elseif ($overallScore >= 40) {
            $overallRiskLevel = 'medium';
        }

        // Map domains to structures
        $contributingRiskDomains = [
            'vendor_risk' => [
                'score' => $vendorRiskScore,
                'risk_level' => $this->mapRiskLevel($vendorRiskScore),
            ],
            'reconciliation_risk' => [
                'score' => $reconciliationRiskScore,
                'risk_level' => $this->mapRiskLevel($reconciliationRiskScore),
            ],
            'entity_relationship_risk' => [
                'score' => $entityRiskScore,
                'risk_level' => $this->mapRiskLevel($entityRiskScore),
            ],
        ];

        // Gather highest risk findings across all domains
        $highestRiskFindings = [];
        
        foreach ($vendorScores as $vs) {
            foreach ($vs['triggered_rules'] as $rule) {
                $highestRiskFindings[] = [
                    'domain' => 'vendor_risk',
                    'source' => $vs['vendor_name'],
                    'rule_key' => $rule['rule_key'],
                    'name' => $rule['name'],
                    'weight' => $rule['weight'],
                    'explanation' => $rule['explanation'],
                ];
            }
        }
        
        foreach ($reconciliationResult['triggered_rules'] as $rule) {
            $highestRiskFindings[] = [
                'domain' => 'reconciliation_risk',
                'source' => 'General Ledger / Bank Statement',
                'rule_key' => $rule['rule_key'],
                'name' => $rule['name'],
                'weight' => $rule['weight'],
                'explanation' => $rule['explanation'],
            ];
        }

        foreach ($entityResult['triggered_rules'] as $rule) {
            $highestRiskFindings[] = [
                'domain' => 'entity_relationship_risk',
                'source' => 'Entity Graph / Metadata',
                'rule_key' => $rule['rule_key'],
                'name' => $rule['name'],
                'weight' => $rule['weight'],
                'explanation' => $rule['explanation'],
            ];
        }

        // Sort findings by weight descending
        usort($highestRiskFindings, fn($a, $b) => $b['weight'] <=> $a['weight']);
        // Keep top 10 highest risk findings
        $highestRiskFindings = array_slice($highestRiskFindings, 0, 10);

        // Gather triggered rules summary (unique rules triggered count per domain)
        $uniqueVendorRules = [];
        foreach ($vendorScores as $vs) {
            foreach ($vs['triggered_rules'] as $r) {
                $uniqueVendorRules[$r['rule_key']] = true;
            }
        }
        $triggeredRulesSummary = [
            'vendor_risk' => count($uniqueVendorRules),
            'reconciliation_risk' => count($reconciliationResult['triggered_rules']),
            'entity_relationship_risk' => count($entityResult['triggered_rules']),
        ];

        // Prioritize actions based on domains
        $recommendedNextActions = [];
        if ($overallScore >= 90) {
            $recommendedNextActions[] = 'Halt all pending automated disbursements immediately and schedule forensic investigation.';
        }
        
        $highRiskVendors = array_filter($vendorScores, fn($v) => $v['vendor_risk_score'] >= 70);
        if (!empty($highRiskVendors)) {
            $names = array_slice(array_column($highRiskVendors, 'vendor_name'), 0, 3);
            $recommendedNextActions[] = 'Audit credentials and onboarding files for high-risk vendors: ' . implode(', ', $names) . '.';
        }
        if ($reconciliationRiskScore >= 40) {
            $recommendedNextActions[] = 'Enforce dual-authorization on all manual general ledger adjustments and reconcile stale unmatched balances.';
        }
        if ($entityRiskScore >= 40) {
            $recommendedNextActions[] = 'Merge duplicate vendor spelling profiles and perform employee-vendor relationship conflict review.';
        }
        if (empty($recommendedNextActions)) {
            $recommendedNextActions[] = 'Continue routine automated continuous monitoring and ledger hygiene audits.';
        }

        // Supporting evidence summaries
        $supportingEvidenceSummary = [
            'vendor_risk' => [
                'total_vendors_analyzed' => count($vendorScores),
                'flagged_vendors' => count(array_filter($vendorScores, fn($v) => $v['vendor_risk_score'] >= 40)),
            ],
            'reconciliation_risk' => [
                'triggered_anomalies' => count($reconciliationResult['triggered_rules']),
                'stale_unreconciled_items_count' => count($reconciliationResult['supporting_evidence']['stale_unreconciled'] ?? []),
            ],
            'entity_relationship_risk' => [
                'overlapping_employees_count' => count($entityResult['supporting_evidence']['employee_vendor_overlap']['overlaps'] ?? []),
                'duplicate_vendor_identity_clusters_count' => count($entityResult['supporting_evidence']['duplicate_vendor_cluster']['clusters'] ?? []),
            ],
        ];

        return [
            'company_id' => $companyId,
            'overall_risk_score' => $overallScore,
            'overall_risk_level' => $overallRiskLevel,
            'contributing_risk_domains' => $contributingRiskDomains,
            'highest_risk_findings' => $highestRiskFindings,
            'triggered_rules_summary' => $triggeredRulesSummary,
            'recommended_next_actions' => $recommendedNextActions,
            'supporting_evidence_summary' => $supportingEvidenceSummary,
        ];
    }

    private function mapRiskLevel(int $score): string
    {
        if ($score >= 90) return 'critical';
        if ($score >= 70) return 'high';
        if ($score >= 40) return 'medium';
        return 'low';
    }
}
