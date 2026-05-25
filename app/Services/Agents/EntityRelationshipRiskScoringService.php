<?php

namespace App\Services\Agents;

use App\Models\Alert;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class EntityRelationshipRiskScoringService
{
    /**
     * Calculate entity relationship risk score and return structured explainable details.
     */
    public function scoreEntityRelationships(string $companyId): array
    {
        return Cache::remember("risk_score:entity_relationship:{$companyId}", 300, function () use ($companyId): array {
            return $this->computeEntityRelationships($companyId);
        });
    }

    private function computeEntityRelationships(string $companyId): array
    {
        $ruleWeights = [
            'employee_vendor_overlap' => 20,
            'shared_bank_account' => 20,
            'shared_address' => 15,
            'shared_phone_email' => 10,
            'duplicate_vendor_cluster' => 15,
            'vendor_vendor_payment' => 10,
            'unusual_concentration' => 10,
        ];

        $triggeredRules = [];
        $supportingEvidence = [];
        $relatedEntities = [];

        // 1. Employee/Vendor Overlap
        $users = User::where('company_id', $companyId)->get();
        $overlaps = [];
        foreach ($users as $u) {
            if (empty($u->first_name) || empty($u->last_name)) {
                continue;
            }
            $fullName = trim($u->first_name . ' ' . $u->last_name);
            
            $matches = Transaction::where('company_id', $companyId)
                ->where(function ($q) use ($fullName, $u) {
                    $q->where('vendor_customer', 'like', '%' . $fullName . '%')
                      ->orWhere('vendor_customer', 'like', '%' . $u->email . '%');
                })
                ->get();
                
            if ($matches->isNotEmpty()) {
                $uniqueVendors = $matches->pluck('vendor_customer')->unique()->values()->all();
                $overlaps[] = [
                    'employee_id' => $u->id,
                    'employee_name' => $fullName,
                    'employee_email' => $u->email,
                    'matched_vendors' => $uniqueVendors,
                    'transaction_count' => $matches->count(),
                ];
                foreach ($uniqueVendors as $v) {
                    $relatedEntities[] = [
                        'type' => 'employee_vendor_relationship',
                        'entities' => [$fullName, $v],
                        'description' => "Employee name or email matched vendor name '{$v}'.",
                    ];
                }
            }
        }
        if (!empty($overlaps)) {
            $triggeredRules[] = [
                'rule_key' => 'employee_vendor_overlap',
                'name' => 'Employee/Vendor Overlap',
                'weight' => $ruleWeights['employee_vendor_overlap'],
                'explanation' => 'Internal employee name or email address directly matches a vendor name in the ledger.',
            ];
            $supportingEvidence['employee_vendor_overlap'] = [
                'overlaps' => $overlaps,
            ];
        }

        // 2. Shared Bank Account Indicators
        $bankingAlerts = Alert::where('company_id', $companyId)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->where(function ($q) {
                $q->where('rule_key', '=', 'shared_bank_account')
                  ->orWhere('title', 'like', '%shared bank account%')
                  ->orWhere('detail', 'like', '%shared bank account%');
            })
            ->get();
        if ($bankingAlerts->isNotEmpty()) {
            $triggeredRules[] = [
                'rule_key' => 'shared_bank_account',
                'name' => 'Shared Bank Account Indicators',
                'weight' => $ruleWeights['shared_bank_account'],
                'explanation' => 'Active system alerts indicating multiple unique vendors sharing identical banking routes or accounts.',
            ];
            $supportingEvidence['shared_bank_account'] = [
                'alerts' => $bankingAlerts->map(fn($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'detail' => $a->detail,
                ])->all(),
            ];
            foreach ($bankingAlerts as $a) {
                $related = $a->evidence['metadata']['related_vendors'] ?? $a->evidence['related_vendors'] ?? ['Multiple Vendors'];
                $relatedEntities[] = [
                    'type' => 'shared_banking',
                    'entities' => $related,
                    'description' => $a->detail ?? $a->title,
                ];
            }
        }

        // 3. Shared Address Indicators
        $addressAlerts = Alert::where('company_id', $companyId)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->where(function ($q) {
                $q->where('rule_key', '=', 'shared_address')
                  ->orWhere('title', 'like', '%shared address%')
                  ->orWhere('detail', 'like', '%shared address%');
            })
            ->get();
        if ($addressAlerts->isNotEmpty()) {
            $triggeredRules[] = [
                'rule_key' => 'shared_address',
                'name' => 'Shared Address Indicators',
                'weight' => $ruleWeights['shared_address'],
                'explanation' => 'Active system alerts indicating multiple unique vendors or employees sharing identical billing/physical addresses.',
            ];
            $supportingEvidence['shared_address'] = [
                'alerts' => $addressAlerts->map(fn($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'detail' => $a->detail,
                ])->all(),
            ];
            foreach ($addressAlerts as $a) {
                $related = $a->evidence['metadata']['related_entities'] ?? $a->evidence['related_entities'] ?? ['Multiple Entities'];
                $relatedEntities[] = [
                    'type' => 'shared_address',
                    'entities' => $related,
                    'description' => $a->detail ?? $a->title,
                ];
            }
        }

        // 4. Shared Phone/Email Indicators
        $contactAlerts = Alert::where('company_id', $companyId)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->where(function ($q) {
                $q->where('rule_key', '=', 'shared_phone_email')
                  ->orWhere('title', 'like', '%shared phone%')
                  ->orWhere('title', 'like', '%shared email%')
                  ->orWhere('detail', 'like', '%shared phone%')
                  ->orWhere('detail', 'like', '%shared email%');
            })
            ->get();
        if ($contactAlerts->isNotEmpty()) {
            $triggeredRules[] = [
                'rule_key' => 'shared_phone_email',
                'name' => 'Shared Phone/Email Indicators',
                'weight' => $ruleWeights['shared_phone_email'],
                'explanation' => 'Active system alerts indicating multiple unique vendors sharing identical contact numbers or email domains.',
            ];
            $supportingEvidence['shared_phone_email'] = [
                'alerts' => $contactAlerts->map(fn($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'detail' => $a->detail,
                ])->all(),
            ];
            foreach ($contactAlerts as $a) {
                $related = $a->evidence['metadata']['related_entities'] ?? $a->evidence['related_entities'] ?? ['Multiple Entities'];
                $relatedEntities[] = [
                    'type' => 'shared_contact',
                    'entities' => $related,
                    'description' => $a->detail ?? $a->title,
                ];
            }
        }

        // 5. Duplicate Vendor Identity Clusters
        $vendors = Transaction::where('company_id', $companyId)
            ->whereNotNull('vendor_customer')
            ->pluck('vendor_customer')
            ->unique()
            ->values()
            ->all();
        $clusters = [];
        $seen = [];
        for ($i = 0; $i < count($vendors); $i++) {
            $v1 = $vendors[$i];
            if (in_array($v1, $seen)) {
                continue;
            }
            $cluster = [$v1];
            for ($j = $i + 1; $j < count($vendors); $j++) {
                $v2 = $vendors[$j];
                // Exclude common false positives
                if ($this->isCommonPublicData($v1, $v2)) {
                    continue;
                }
                $dist = levenshtein(strtolower($v1), strtolower($v2));
                if ($dist >= 1 && $dist <= 3) {
                    $cluster[] = $v2;
                    $seen[] = $v2;
                }
            }
            if (count($cluster) > 1) {
                $clusters[] = $cluster;
                $relatedEntities[] = [
                    'type' => 'duplicate_vendor_identity',
                    'entities' => $cluster,
                    'description' => 'Vendors identified as part of a single identity cluster due to close spelling similarity.',
                ];
            }
        }
        if (!empty($clusters)) {
            $triggeredRules[] = [
                'rule_key' => 'duplicate_vendor_cluster',
                'name' => 'Duplicate Vendor Identity Clusters',
                'weight' => $ruleWeights['duplicate_vendor_cluster'],
                'explanation' => 'Identified closely misspelled or duplicate vendor accounts that may represent split profiles.',
            ];
            $supportingEvidence['duplicate_vendor_cluster'] = [
                'clusters' => $clusters,
            ];
        }

        // 6. Vendor-to-Vendor Payment Relationships
        $vendorVendorAlerts = Alert::where('company_id', $companyId)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->where(function ($q) {
                $q->where('rule_key', '=', 'vendor_vendor_payment')
                  ->orWhere('title', 'like', '%vendor-to-vendor%')
                  ->orWhere('detail', 'like', '%vendor-to-vendor%');
            })
            ->get();
        if ($vendorVendorAlerts->isNotEmpty()) {
            $triggeredRules[] = [
                'rule_key' => 'vendor_vendor_payment',
                'name' => 'Vendor-to-Vendor Payments',
                'weight' => $ruleWeights['vendor_vendor_payment'],
                'explanation' => 'Active system alerts indicating transactions routed between vendor accounts, bypassing standard ledger paths.',
            ];
            $supportingEvidence['vendor_vendor_payment'] = [
                'alerts' => $vendorVendorAlerts->map(fn($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'detail' => $a->detail,
                ])->all(),
            ];
            foreach ($vendorVendorAlerts as $a) {
                $related = $a->evidence['metadata']['related_vendors'] ?? $a->evidence['related_vendors'] ?? ['Multiple Vendors'];
                $relatedEntities[] = [
                    'type' => 'vendor_vendor_relationship',
                    'entities' => $related,
                    'description' => $a->detail ?? $a->title,
                ];
            }
        }

        // 7. Unusual Concentration Within Related Entities
        $totalCompanySpend = Transaction::where('company_id', $companyId)->sum('amount');
        $concentrationAlerts = [];
        if ($totalCompanySpend > 0 && !empty($clusters)) {
            foreach ($clusters as $cluster) {
                $clusterSpend = Transaction::where('company_id', $companyId)
                    ->whereIn('vendor_customer', $cluster)
                    ->sum('amount');
                $pct = ($clusterSpend / $totalCompanySpend) * 100;
                if ($pct >= 15.0) {
                    $concentrationAlerts[] = [
                        'cluster' => $cluster,
                        'spend' => (float)$clusterSpend,
                        'percentage' => round($pct, 2),
                    ];
                }
            }
        }
        if (!empty($concentrationAlerts)) {
            $triggeredRules[] = [
                'rule_key' => 'unusual_concentration',
                'name' => 'Concentration in Related Entities',
                'weight' => $ruleWeights['unusual_concentration'],
                'explanation' => 'Concentration of overall company spend in closely spelling-similar vendor clusters exceeds the 15% threshold.',
            ];
            $supportingEvidence['unusual_concentration'] = [
                'concentration_alerts' => $concentrationAlerts,
            ];
        }

        // Calculate score
        $score = 0;
        foreach ($triggeredRules as $rule) {
            $score += $rule['weight'];
        }
        $score = min(100, $score);

        // Map risk level
        $riskLevel = 'low';
        if ($score >= 90) {
            $riskLevel = 'critical';
        } elseif ($score >= 70) {
            $riskLevel = 'high';
        } elseif ($score >= 40) {
            $riskLevel = 'medium';
        }

        // Define recommended next action
        $recommendedAction = 'Entity relationships are within safe parameters. Continue continuous monitoring.';
        if ($score >= 90) {
            $recommendedAction = 'Review all transactions associated with the overlapping employee and check conflict of interest declarations immediately.';
        } elseif ($score >= 70) {
            $recommendedAction = 'Conduct a relationship risk review of the shared banking or address clusters and preserve supporting evidence for human investigation.';
        } elseif ($score >= 40) {
            $recommendedAction = 'Merge duplicate vendor records and trace the ultimate beneficial ownership of the related entity clusters.';
        }

        return [
            'company_id' => $companyId,
            'entity_relationship_risk_score' => $score,
            'risk_level' => $riskLevel,
            'triggered_rules' => $triggeredRules,
            'rule_weights' => $ruleWeights,
            'supporting_evidence' => $supportingEvidence,
            'related_entities' => $relatedEntities,
            'recommended_next_action' => $recommendedAction,
        ];
    }

    /**
     * Check for common public names or data to prevent false positives.
     */
    private function isCommonPublicData(string $v1, string $v2): bool
    {
        $v1Lower = strtolower(trim($v1));
        $v2Lower = strtolower(trim($v2));

        // Ignore similarity check on common SaaS or utilities
        $commonTerms = ['google', 'microsoft', 'aws', 'amazon', 'adobe', 'slack', 'zoom', 'payroll', 'tax', 'utility'];
        foreach ($commonTerms as $term) {
            if (str_contains($v1Lower, $term) && str_contains($v2Lower, $term)) {
                return true;
            }
        }
        return false;
    }
}
