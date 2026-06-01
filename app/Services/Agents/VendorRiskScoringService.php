<?php

namespace App\Services\Agents;

use App\Models\Alert;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class VendorRiskScoringService
{
    /**
     * Map of rule keys to their designated deterministic weights.
     */
    public const RULE_WEIGHTS = [
        'new_vendor' => 15,
        'vendor_concentration' => 20,
        'rapid_payment' => 15,
        'similar_vendor_name' => 15,
        'round_dollar' => 15,
        'threshold_splitting' => 20,
        'unusual_timing' => 10,
        'shared_payment_indicators' => 15,
    ];

    /**
     * Compute a vendor risk score from 0-100 using weighted deterministic rules.
     */
    public function scoreVendor(string $companyId, string $vendorName, ?string $businessProfileId = null): array
    {
        // 1. Fetch transactions for this specific vendor
        $vendorTxns = $this->transactionQuery($companyId, $businessProfileId)
            ->where('vendor_customer', $vendorName)
            ->get();

        // 2. Fetch all transactions for the company (needed for relative calculations)
        $companyTxns = $this->transactionQuery($companyId, $businessProfileId)->get();

        if ($vendorTxns->isEmpty()) {
            return $this->emptyVendorResponse($vendorName, $businessProfileId);
        }

        $triggeredRules = [];
        $supportingEvidence = [];
        $totalScore = 0;

        // --- RULE 1: New Vendor Risk (Weight: 15) ---
        $latestCompanyDateStr = $companyTxns->max('date');
        $earliestVendorDateStr = $vendorTxns->min('date');

        if ($latestCompanyDateStr && $earliestVendorDateStr) {
            $latestCompanyDate = Carbon::parse($latestCompanyDateStr);
            $earliestVendorDate = Carbon::parse($earliestVendorDateStr);
            $daysDiff = $earliestVendorDate->diffInDays($latestCompanyDate);

            if ($daysDiff <= 30) {
                $triggeredRules[] = [
                    'rule_key' => 'new_vendor',
                    'name' => 'New Vendor Risk',
                    'weight' => self::RULE_WEIGHTS['new_vendor'],
                    'explanation' => "Vendor's first transaction was seen on {$earliestVendorDate->format('Y-m-d')}, which is within 30 days of the company's latest transaction date ({$latestCompanyDate->format('Y-m-d')}).",
                ];
                $totalScore += self::RULE_WEIGHTS['new_vendor'];
                $supportingEvidence['new_vendor'] = [
                    'earliest_vendor_transaction' => $earliestVendorDate->format('Y-m-d'),
                    'latest_company_transaction' => $latestCompanyDate->format('Y-m-d'),
                    'days_since_first_transaction' => $daysDiff,
                ];
            }
        }

        // --- RULE 2: Vendor Concentration Risk (Weight: 20) ---
        $vendorSpend = $vendorTxns->sum('amount');
        $totalCompanySpend = $companyTxns->sum('amount');
        $concentrationRatio = $totalCompanySpend > 0 ? ($vendorSpend / $totalCompanySpend) : 0;

        if ($concentrationRatio >= 0.25) {
            $percentage = round($concentrationRatio * 100, 2);
            $triggeredRules[] = [
                'rule_key' => 'vendor_concentration',
                'name' => 'Vendor Concentration Risk',
                'weight' => self::RULE_WEIGHTS['vendor_concentration'],
                'explanation' => 'Vendor spend of $'.number_format($vendorSpend, 2)." represents {$percentage}% of total company spend ($".number_format($totalCompanySpend, 2).').',
            ];
            $totalScore += self::RULE_WEIGHTS['vendor_concentration'];
            $supportingEvidence['vendor_concentration'] = [
                'vendor_spend' => $vendorSpend,
                'total_company_spend' => $totalCompanySpend,
                'concentration_percentage' => $percentage,
            ];
        }

        // --- RULE 3: Rapid Payment after Onboarding (Weight: 15) ---
        if ($earliestVendorDateStr) {
            $earliestDate = Carbon::parse($earliestVendorDateStr);
            $rapidTxns = [];
            foreach ($vendorTxns as $txn) {
                $txnDate = Carbon::parse($txn->date);
                $diffDays = $earliestDate->diffInDays($txnDate);
                if ($diffDays <= 7 && $txn->amount >= 2500.00) {
                    $rapidTxns[] = [
                        'id' => $txn->id,
                        'amount' => (float) $txn->amount,
                        'date' => $txnDate->format('Y-m-d'),
                    ];
                }
            }

            if (! empty($rapidTxns)) {
                $triggeredRules[] = [
                    'rule_key' => 'rapid_payment',
                    'name' => 'Rapid Payment after Onboarding',
                    'weight' => self::RULE_WEIGHTS['rapid_payment'],
                    'explanation' => "High-value payment(s) >= $2,500.00 were processed within 7 days of the vendor's first appearance.",
                ];
                $totalScore += self::RULE_WEIGHTS['rapid_payment'];
                $supportingEvidence['rapid_payment'] = [
                    'first_transaction_date' => $earliestDate->format('Y-m-d'),
                    'rapid_high_value_transactions' => $rapidTxns,
                ];
            }
        }

        // --- RULE 4: Duplicate/Similar Vendor Names (Weight: 15) ---
        $uniqueVendors = $companyTxns->pluck('vendor_customer')->filter()->unique();
        $similarVendors = [];
        foreach ($uniqueVendors as $otherName) {
            if (strcasecmp($vendorName, $otherName) === 0) {
                continue;
            }
            $dist = levenshtein(strtolower($vendorName), strtolower($otherName));
            if ($dist > 0 && $dist <= 3) {
                $similarVendors[] = [
                    'name' => $otherName,
                    'levenshtein_distance' => $dist,
                ];
            }
        }

        if (! empty($similarVendors)) {
            $names = implode(', ', array_column($similarVendors, 'name'));
            $triggeredRules[] = [
                'rule_key' => 'similar_vendor_name',
                'name' => 'Duplicate/Similar Vendor Names',
                'weight' => self::RULE_WEIGHTS['similar_vendor_name'],
                'explanation' => "Vendor name '{$vendorName}' is highly similar to other existing vendor(s): {$names}.",
            ];
            $totalScore += self::RULE_WEIGHTS['similar_vendor_name'];
            $supportingEvidence['similar_vendor_name'] = [
                'similar_vendors' => $similarVendors,
            ];
        }

        // --- RULE 5: Round-Dollar Payment Patterns (Weight: 15) ---
        $totalCount = $vendorTxns->count();
        $roundCount = 0;
        $roundTxns = [];
        foreach ($vendorTxns as $txn) {
            $amount = (float) $txn->amount;
            if ($amount > 0 && fmod($amount, 100.0) == 0.0) {
                $roundCount++;
                $roundTxns[] = [
                    'id' => $txn->id,
                    'amount' => $amount,
                    'date' => Carbon::parse($txn->date)->format('Y-m-d'),
                ];
            }
        }
        $roundPercentage = $totalCount > 0 ? ($roundCount / $totalCount) * 100 : 0;

        if ($totalCount >= 2 && $roundPercentage >= 50.0) {
            $triggeredRules[] = [
                'rule_key' => 'round_dollar',
                'name' => 'Round-Dollar Payment Patterns',
                'weight' => self::RULE_WEIGHTS['round_dollar'],
                'explanation' => "{$roundCount} out of {$totalCount} payments (".round($roundPercentage, 2).'%) are round-dollar amounts (multiples of $100.00).',
            ];
            $totalScore += self::RULE_WEIGHTS['round_dollar'];
            $supportingEvidence['round_dollar'] = [
                'total_transactions' => $totalCount,
                'round_dollar_transactions_count' => $roundCount,
                'round_dollar_percentage' => round($roundPercentage, 2),
                'round_dollar_transactions' => $roundTxns,
            ];
        }

        // --- RULE 6: Threshold Splitting Behavior (Weight: 20) ---
        // Find transactions just under the $5000 approval limit ($4000 to $4999.99)
        $thresholdTxns = $vendorTxns->filter(function ($txn) {
            return $txn->amount >= 4000.00 && $txn->amount < 5000.00;
        })->sortBy('date')->values();

        $splitGroups = [];
        $triggeredSplitting = false;
        $n = $thresholdTxns->count();
        for ($i = 0; $i < $n; $i++) {
            $group = [$thresholdTxns[$i]];
            $dateI = Carbon::parse($thresholdTxns[$i]->date);
            for ($j = $i + 1; $j < $n; $j++) {
                $dateJ = Carbon::parse($thresholdTxns[$j]->date);
                $diffDays = $dateI->diffInDays($dateJ);
                if ($diffDays <= 5) {
                    $group[] = $thresholdTxns[$j];
                }
            }
            if (count($group) >= 2) {
                $splitGroups[] = array_map(fn ($t) => [
                    'id' => $t->id,
                    'amount' => (float) $t->amount,
                    'date' => Carbon::parse($t->date)->format('Y-m-d'),
                ], $group);
                $triggeredSplitting = true;
            }
        }

        if ($triggeredSplitting) {
            $triggeredRules[] = [
                'rule_key' => 'threshold_splitting',
                'name' => 'Threshold Splitting Behavior',
                'weight' => self::RULE_WEIGHTS['threshold_splitting'],
                'explanation' => 'Detected multiple payments just under the $5,000.00 approval threshold within a 5-day window.',
            ];
            $totalScore += self::RULE_WEIGHTS['threshold_splitting'];
            $supportingEvidence['threshold_splitting'] = [
                'split_transaction_groups' => $splitGroups,
            ];
        }

        // --- RULE 7: Unusual Payment Timing (Weight: 10) ---
        $weekendTxns = [];
        foreach ($vendorTxns as $txn) {
            $dt = Carbon::parse($txn->date);
            $dayOfWeek = $dt->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
            if ($dayOfWeek >= 6) {
                $weekendTxns[] = [
                    'id' => $txn->id,
                    'amount' => (float) $txn->amount,
                    'date' => $dt->format('Y-m-d'),
                    'day_of_week' => $dayOfWeek == 6 ? 'Saturday' : 'Sunday',
                ];
            }
        }

        if (! empty($weekendTxns)) {
            $triggeredRules[] = [
                'rule_key' => 'unusual_timing',
                'name' => 'Unusual Payment Timing',
                'weight' => self::RULE_WEIGHTS['unusual_timing'],
                'explanation' => 'Detected transaction(s) processed on a weekend (Saturday or Sunday).',
            ];
            $totalScore += self::RULE_WEIGHTS['unusual_timing'];
            $supportingEvidence['unusual_timing'] = [
                'weekend_transactions' => $weekendTxns,
            ];
        }

        // --- RULE 8: Shared Payment/Account Indicators (Weight: 15) ---
        $sharedAlerts = $this->alertQuery($companyId, $businessProfileId)
            ->where('status', 'open')
            ->where(function ($query) use ($vendorName) {
                $query->where('title', 'like', "%{$vendorName}%")
                    ->orWhere('detail', 'like', "%{$vendorName}%");
            })
            ->get();

        $sharedIndicators = [];
        foreach ($sharedAlerts as $alert) {
            $sharedIndicators[] = [
                'alert_id' => $alert->id,
                'title' => $alert->title,
                'rule_key' => $alert->rule_key,
            ];
        }

        if (! empty($sharedIndicators)) {
            $triggeredRules[] = [
                'rule_key' => 'shared_payment_indicators',
                'name' => 'Shared Payment/Account Indicators',
                'weight' => self::RULE_WEIGHTS['shared_payment_indicators'],
                'explanation' => 'System alerts indicate this vendor has shared payment credentials or other shared entity indicators.',
            ];
            $totalScore += self::RULE_WEIGHTS['shared_payment_indicators'];
            $supportingEvidence['shared_payment_indicators'] = [
                'matching_alerts' => $sharedIndicators,
            ];
        }

        $finalScore = min(100, $totalScore);

        return [
            'vendor_name' => $vendorName,
            'business_profile_id' => $businessProfileId,
            'vendor_risk_score' => $finalScore,
            'risk_level' => $this->riskLevel($finalScore),
            'triggered_rules' => $triggeredRules,
            'rule_weights' => self::RULE_WEIGHTS,
            'supporting_evidence' => $supportingEvidence,
            'recommended_next_action' => $this->recommendAction($finalScore),
        ];
    }

    /**
     * Group score into qualitative levels.
     */
    private function riskLevel(int $score): string
    {
        if ($score >= 90) {
            return 'critical';
        }
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 40) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Return deterministic action guidance based on score.
     */
    private function recommendAction(int $score): string
    {
        if ($score >= 90) {
            return 'Immediate hold on all pending disbursements to this vendor. Initiate secondary audit review of vendor onboarding paperwork and bank verification documents.';
        }
        if ($score >= 70) {
            return 'Escalate to senior finance supervisor for manual review prior to future invoice approvals. Verify vendor credentials and perform standard entity verification.';
        }
        if ($score >= 40) {
            return 'Flag vendor for routine periodic audit. Standard validation of the next two scheduled payments.';
        }

        return 'No immediate actions required. Continue routine automated continuous monitoring.';
    }

    /**
     * Safe empty response when no transactions are found.
     */
    private function emptyVendorResponse(string $vendorName, ?string $businessProfileId = null): array
    {
        return [
            'vendor_name' => $vendorName,
            'business_profile_id' => $businessProfileId,
            'vendor_risk_score' => 0,
            'risk_level' => 'low',
            'triggered_rules' => [],
            'rule_weights' => self::RULE_WEIGHTS,
            'supporting_evidence' => [],
            'recommended_next_action' => 'No actions required. Vendor not found in ledger.',
        ];
    }

    /**
     * Compute scores for all unique vendors of a company.
     */
    public function scoreAllVendors(string $companyId, ?string $businessProfileId = null): array
    {
        $cacheKey = $businessProfileId
            ? "risk_score:vendor:{$companyId}:profile:{$businessProfileId}"
            : "risk_score:vendor:{$companyId}";

        return Cache::remember($cacheKey, 300, function () use ($companyId, $businessProfileId): array {
            return $this->computeAllVendors($companyId, $businessProfileId);
        });
    }

    private function computeAllVendors(string $companyId, ?string $businessProfileId = null): array
    {
        $vendors = $this->transactionQuery($companyId, $businessProfileId)
            ->whereNotNull('vendor_customer')
            ->where('vendor_customer', '<>', '')
            ->distinct()
            ->pluck('vendor_customer');

        $scores = [];
        foreach ($vendors as $vendor) {
            $scores[] = $this->scoreVendor($companyId, $vendor, $businessProfileId);
        }

        // Sort by score descending, then alphabetically by vendor name
        usort($scores, function ($a, $b) {
            $scoreDiff = $b['vendor_risk_score'] <=> $a['vendor_risk_score'];
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }

            return strcmp($a['vendor_name'], $b['vendor_name']);
        });

        return $scores;
    }

    private function transactionQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return Transaction::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('transactions', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }

    private function alertQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return Alert::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('alerts', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }
}
