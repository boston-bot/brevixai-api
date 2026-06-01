<?php

namespace App\Services\Agents;

use App\Models\ReconciliationDiscrepancy;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ReconciliationRiskScoringService
{
    /**
     * Calculate reconciliation risk score and return structured explainable details.
     */
    public function scoreReconciliation(string $companyId, ?string $businessProfileId = null): array
    {
        $cacheKey = $businessProfileId
            ? "risk_score:reconciliation:{$companyId}:profile:{$businessProfileId}"
            : "risk_score:reconciliation:{$companyId}";

        return Cache::remember($cacheKey, 300, function () use ($companyId, $businessProfileId): array {
            return $this->computeReconciliation($companyId, $businessProfileId);
        });
    }

    private function computeReconciliation(string $companyId, ?string $businessProfileId = null): array
    {
        $ruleWeights = [
            'bank_ledger_mismatch' => 15,
            'unmatched_deposits' => 20,
            'unmatched_withdrawals' => 20,
            'duplicate_ledger' => 15,
            'stale_unreconciled' => 15,
            'amount_date_variance' => 10,
            'suspicious_manual_adjustment' => 15,
        ];

        // Fetch unresolved discrepancies
        $discrepancies = $this->discrepancyQuery($companyId, $businessProfileId)
            ->whereNotIn('status', ['resolved', 'ignored'])
            ->with(['bankTransaction', 'ledgerTransaction'])
            ->get();

        // Get latest transaction date for staleness checking
        $latestTxnDateStr = $this->transactionQuery($companyId, $businessProfileId)
            ->max('date');
        $latestTxnDate = $latestTxnDateStr ? Carbon::parse($latestTxnDateStr) : Carbon::now();

        // Detect suspicious manual adjustments in transactions
        $suspiciousTxns = $this->transactionQuery($companyId, $businessProfileId)
            ->where(function ($query) {
                $query->where('memo', 'like', '%adj%')
                    ->orWhere('memo', 'like', '%adjustment%')
                    ->orWhere('memo', 'like', '%force%')
                    ->orWhere('memo', 'like', '%write-off%')
                    ->orWhere('category', 'like', '%adjustment%');
            })
            ->get();

        $triggeredRules = [];
        $supportingEvidence = [];

        // 1. Bank-to-Ledger Mismatches
        $mismatchItems = [];
        foreach ($discrepancies as $d) {
            if ($d->category === 'missing_from_books' ||
                $d->reason_code === 'bank_transaction_without_ledger_match' ||
                $d->reason_code === 'reconciliation_mismatch') {
                $mismatchItems[] = $this->formatDiscrepancy($d);
            }
        }
        if (! empty($mismatchItems)) {
            $triggeredRules[] = [
                'rule_key' => 'bank_ledger_mismatch',
                'name' => 'Bank-to-Ledger Mismatches',
                'weight' => $ruleWeights['bank_ledger_mismatch'],
                'explanation' => 'Unmatched transactions present on bank statements but missing from the internal ledger.',
            ];
            $supportingEvidence['bank_ledger_mismatch'] = [
                'discrepancies' => $mismatchItems,
            ];
        }

        // 2. Unmatched Deposits
        $depositMismatches = [];
        foreach ($discrepancies as $d) {
            $isDeposit = false;
            if ($d->bankTransaction) {
                $isDeposit = $d->bankTransaction->amount > 0 || stripos($d->bankTransaction->type ?? '', 'deposit') !== false;
            } elseif ($d->ledgerTransaction) {
                $isDeposit = $d->ledgerTransaction->amount > 0 || stripos($d->ledgerTransaction->type ?? '', 'deposit') !== false;
            } else {
                $isDeposit = $d->amount > 0;
            }

            if ($isDeposit && ($d->category === 'missing_from_books' || $d->reason_code === 'bank_transaction_without_ledger_match')) {
                $depositMismatches[] = $this->formatDiscrepancy($d);
            }
        }
        if (! empty($depositMismatches)) {
            $triggeredRules[] = [
                'rule_key' => 'unmatched_deposits',
                'name' => 'Unmatched Deposits',
                'weight' => $ruleWeights['unmatched_deposits'],
                'explanation' => 'Deposits received in the bank account that could not be matched to internal records.',
            ];
            $supportingEvidence['unmatched_deposits'] = [
                'discrepancies' => $depositMismatches,
            ];
        }

        // 3. Unmatched Withdrawals
        $withdrawalMismatches = [];
        foreach ($discrepancies as $d) {
            $isWithdrawal = false;
            if ($d->bankTransaction) {
                $isWithdrawal = $d->bankTransaction->amount <= 0 || stripos($d->bankTransaction->type ?? '', 'deposit') === false;
            } elseif ($d->ledgerTransaction) {
                $isWithdrawal = $d->ledgerTransaction->amount <= 0 || stripos($d->ledgerTransaction->type ?? '', 'deposit') === false;
            } else {
                $isWithdrawal = $d->amount <= 0;
            }

            if ($isWithdrawal && ($d->category === 'missing_from_books' || $d->reason_code === 'bank_transaction_without_ledger_match')) {
                $withdrawalMismatches[] = $this->formatDiscrepancy($d);
            }
        }
        if (! empty($withdrawalMismatches)) {
            $triggeredRules[] = [
                'rule_key' => 'unmatched_withdrawals',
                'name' => 'Unmatched Withdrawals',
                'weight' => $ruleWeights['unmatched_withdrawals'],
                'explanation' => 'Cash withdrawals or expenses paid from the bank account that could not be matched to ledger entries.',
            ];
            $supportingEvidence['unmatched_withdrawals'] = [
                'discrepancies' => $withdrawalMismatches,
            ];
        }

        // 4. Duplicate Ledger Entries
        $duplicateEntries = [];
        foreach ($discrepancies as $d) {
            if ($d->category === 'duplicate_ledger' || $d->reason_code === 'duplicate_ledger_entry') {
                $duplicateEntries[] = $this->formatDiscrepancy($d);
            }
        }
        if (! empty($duplicateEntries)) {
            $triggeredRules[] = [
                'rule_key' => 'duplicate_ledger',
                'name' => 'Duplicate Ledger Entries',
                'weight' => $ruleWeights['duplicate_ledger'],
                'explanation' => 'Identified multiple duplicate internal ledger transactions matching a single bank record.',
            ];
            $supportingEvidence['duplicate_ledger'] = [
                'discrepancies' => $duplicateEntries,
            ];
        }

        // 5. Stale Unreconciled Items
        $staleItems = [];
        foreach ($discrepancies as $d) {
            $itemDateStr = null;
            if ($d->bankTransaction && $d->bankTransaction->date) {
                $itemDateStr = $d->bankTransaction->date;
            } elseif ($d->ledgerTransaction && $d->ledgerTransaction->date) {
                $itemDateStr = $d->ledgerTransaction->date;
            }

            $isStale = false;
            if ($d->category === 'stale_unreconciled' || $d->reason_code === 'stale_unreconciled_item') {
                $isStale = true;
            } elseif ($itemDateStr) {
                $itemDate = Carbon::parse($itemDateStr);
                if ($itemDate->diffInDays($latestTxnDate) > 30) {
                    $isStale = true;
                }
            }

            if ($isStale) {
                $staleItems[] = $this->formatDiscrepancy($d);
            }
        }
        if (! empty($staleItems)) {
            $triggeredRules[] = [
                'rule_key' => 'stale_unreconciled',
                'name' => 'Stale Unreconciled Items',
                'weight' => $ruleWeights['stale_unreconciled'],
                'explanation' => 'Unresolved discrepancies older than 30 days, representing aged audit items.',
            ];
            $supportingEvidence['stale_unreconciled'] = [
                'discrepancies' => $staleItems,
            ];
        }

        // 6. Amount/Date Variance
        $varianceItems = [];
        foreach ($discrepancies as $d) {
            if ($d->category === 'amount_mismatch' ||
                $d->category === 'date_mismatch' ||
                $d->reason_code === 'amount_variance' ||
                $d->reason_code === 'date_variance') {
                $varianceItems[] = $this->formatDiscrepancy($d);
            }
        }
        if (! empty($varianceItems)) {
            $triggeredRules[] = [
                'rule_key' => 'amount_date_variance',
                'name' => 'Amount/Date Variance',
                'weight' => $ruleWeights['amount_date_variance'],
                'explanation' => 'Ledger transactions matched to bank records but exhibiting conflicting amounts or post dates.',
            ];
            $supportingEvidence['amount_date_variance'] = [
                'discrepancies' => $varianceItems,
            ];
        }

        // 7. Suspicious Manual Adjustments
        $manualAdjustments = [];
        foreach ($discrepancies as $d) {
            if ($d->category === 'manual_adjustment' || $d->reason_code === 'suspicious_manual_adjustment') {
                $manualAdjustments[] = $this->formatDiscrepancy($d);
            }
        }
        // Incorporate suspicious manual transactions found
        foreach ($suspiciousTxns as $t) {
            $manualAdjustments[] = [
                'id' => $t->id,
                'date' => $t->date?->format('Y-m-d') ?: $t->date,
                'vendor' => $t->vendor_customer ?: 'Unknown',
                'amount' => (float) $t->amount,
                'memo' => $t->memo,
                'category' => $t->category,
                'is_direct_transaction' => true,
            ];
        }
        if (! empty($manualAdjustments)) {
            $triggeredRules[] = [
                'rule_key' => 'suspicious_manual_adjustment',
                'name' => 'Suspicious Manual Adjustments',
                'weight' => $ruleWeights['suspicious_manual_adjustment'],
                'explanation' => 'Bookkeeping adjustment entries with memos or descriptions indicating forced reconciliations or manual write-offs.',
            ];
            $supportingEvidence['suspicious_manual_adjustment'] = [
                'items' => $manualAdjustments,
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
        $recommendedAction = 'Reconciliation is clean. Continue continuous monitoring.';
        if ($score >= 90) {
            $recommendedAction = 'Review suspicious manual adjustment logs and pause manual journal entries until verified.';
        } elseif ($score >= 70) {
            $recommendedAction = 'Reconcile stale unmatched items immediately and review the bank-to-ledger matching algorithms.';
        } elseif ($score >= 40) {
            $recommendedAction = 'Investigate duplicate ledger entries and match the identified deposits/withdrawals.';
        }

        return [
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'reconciliation_risk_score' => $score,
            'risk_level' => $riskLevel,
            'triggered_rules' => $triggeredRules,
            'rule_weights' => $ruleWeights,
            'supporting_evidence' => $supportingEvidence,
            'recommended_next_action' => $recommendedAction,
        ];
    }

    /**
     * Format a discrepancy row for clean serialization.
     */
    private function formatDiscrepancy(ReconciliationDiscrepancy $d): array
    {
        return [
            'id' => $d->id,
            'run_id' => $d->run_id,
            'amount' => (float) $d->amount,
            'category' => $d->category,
            'reason_code' => $d->reason_code,
            'risk_level' => $d->risk_level,
            'recommended_action' => $d->recommended_action,
            'recommendation_explanation' => $d->recommendation_explanation,
            'status' => $d->status,
            'bank_txn_id' => $d->bank_txn_id,
            'ledger_txn_id' => $d->ledger_txn_id,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    private function discrepancyQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return ReconciliationDiscrepancy::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('reconciliation_discrepancies', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }

    private function transactionQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return Transaction::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('transactions', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }
}
