<?php

namespace App\Services;

use App\Models\ReconciliationDiscrepancy;
use App\Models\ReconciliationDiscrepancyEvent;
use App\Models\ReconciliationResult;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    // -------------------------------------------------------------------------
    // Summary
    // -------------------------------------------------------------------------

    public function getSummary(string $companyId): array
    {
        $latestRun = ReconciliationResult::where('company_id', $companyId)
            ->where('status', 'completed')
            ->latest('run_at')
            ->first();

        if (!$latestRun) {
            return [
                'hasRun' => false,
                'totalMismatches' => 0,
                'totalImpact' => 0,
                'resolvedCount' => 0,
                'resolvedPct' => 0,
                'byType' => [],
                'byCategory' => [],
                'lastRunAt' => null,
                'totalBankTransactions' => 0,
                'totalLedgerTransactions' => 0,
                'matchedPercentage' => 0,
                'unresolvedCount' => 0,
                'unresolvedAmount' => 0,
                'highRiskCount' => 0,
            ];
        }

        $results = is_array($latestRun->results) ? $latestRun->results : [];

        $stats = DB::selectOne(
            "SELECT
                COUNT(*)::int AS total_count,
                COUNT(*) FILTER (WHERE status = 'resolved')::int AS resolved_count,
                COUNT(*) FILTER (WHERE status NOT IN ('resolved', 'ignored'))::int AS unresolved_count,
                COALESCE(SUM(amount) FILTER (WHERE status NOT IN ('resolved', 'ignored')), 0)::numeric AS unresolved_amount,
                COUNT(*) FILTER (WHERE risk_level = 'high' AND status NOT IN ('resolved', 'ignored'))::int AS high_risk_count
             FROM reconciliation_discrepancies
             WHERE company_id = ? AND run_id = ?",
            [$companyId, $latestRun->id]
        );

        if ((int)($stats->total_count ?? 0) > 0) {
            $byCategoryRows = DB::select(
                "SELECT category, COUNT(*)::int AS count
                 FROM reconciliation_discrepancies
                 WHERE company_id = ? AND run_id = ?
                 GROUP BY category",
                [$companyId, $latestRun->id]
            );
            $byCategory = collect($byCategoryRows)->pluck('count', 'category')->toArray();

            $totalCount = (int)($stats->total_count ?? 0);
            $resolvedCount = (int)($stats->resolved_count ?? 0);

            return [
                'hasRun' => true,
                'runId' => $latestRun->id,
                'periodStart' => $latestRun->period_start,
                'periodEnd' => $latestRun->period_end,
                'totalMismatches' => (int)$latestRun->total_mismatches,
                'totalImpact' => (float)$latestRun->total_impact,
                'resolvedCount' => $resolvedCount,
                'resolvedPct' => $totalCount > 0 ? round(($resolvedCount / $totalCount) * 100) : 100,
                'byType' => $results['byType'] ?? [],
                'byCategory' => $byCategory,
                'lastRunAt' => $latestRun->run_at,
                'totalBankTransactions' => $results['bankTransactionCount'] ?? 0,
                'totalLedgerTransactions' => $results['ledgerTransactionCount'] ?? 0,
                'matchedPercentage' => $results['matchedPercentage'] ?? 0,
                'unresolvedCount' => (int)($stats->unresolved_count ?? 0),
                'unresolvedAmount' => (float)($stats->unresolved_amount ?? 0),
                'highRiskCount' => (int)($stats->high_risk_count ?? 0),
            ];
        }

        // Fall back to legacy mismatch table
        $legacyStats = DB::selectOne(
            "SELECT
                COUNT(*) FILTER (WHERE resolved = TRUE)::int AS resolved_count,
                COUNT(*)::int AS total_count
             FROM reconciliation_mismatches
             WHERE run_id = ?",
            [$latestRun->id]
        );

        $totalCount = (int)($legacyStats->total_count ?? 0);
        $resolvedCount = (int)($legacyStats->resolved_count ?? 0);

        return [
            'hasRun' => true,
            'runId' => $latestRun->id,
            'totalMismatches' => (int)$latestRun->total_mismatches,
            'totalImpact' => (float)$latestRun->total_impact,
            'resolvedCount' => $resolvedCount,
            'resolvedPct' => $totalCount > 0 ? round(($resolvedCount / $totalCount) * 100) : 100,
            'byType' => $results['byType'] ?? [],
            'byCategory' => $results['byCategory'] ?? [],
            'lastRunAt' => $latestRun->run_at,
            'totalBankTransactions' => $results['bankTransactionCount'] ?? 0,
            'totalLedgerTransactions' => $results['ledgerTransactionCount'] ?? 0,
            'matchedPercentage' => $results['matchedPercentage'] ?? 0,
            'unresolvedCount' => $results['unresolvedCount'] ?? (int)$latestRun->total_mismatches,
            'unresolvedAmount' => $results['unresolvedImpact'] ?? (float)$latestRun->total_impact,
            'highRiskCount' => $results['highRiskCount'] ?? 0,
        ];
    }

    // -------------------------------------------------------------------------
    // Discrepancy List
    // -------------------------------------------------------------------------

    public function getDiscrepancies(string $companyId, array $filters = []): array
    {
        $runId = $filters['run_id'] ?? $this->getLatestCompletedRunId($companyId);
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min((int)($filters['limit'] ?? 50), 100);
        $offset = ($page - 1) * $limit;

        if (!$runId) {
            return [
                'discrepancies' => [],
                'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'totalPages' => 0],
            ];
        }

        $conditions = ['d.company_id = ?', 'd.run_id = ?'];
        $params = [$companyId, $runId];

        if (!empty($filters['category'])) {
            $conditions[] = 'd.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['risk_level'])) {
            $conditions[] = 'd.risk_level = ?';
            $params[] = $filters['risk_level'];
        }
        if (!empty($filters['status'])) {
            $conditions[] = 'd.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['unresolved_only'])) {
            $conditions[] = "d.status NOT IN ('resolved', 'ignored')";
        }
        if (!empty($filters['probable_matches_only'])) {
            $conditions[] = "d.category = 'probable_match'";
        }

        $where = implode(' AND ', $conditions);

        $rows = DB::select(
            "SELECT
                d.id, d.run_id,
                COALESCE(bank.date, ledger.date) AS date,
                COALESCE(bank.vendor_customer, ledger.vendor_customer, 'Unknown') AS vendor,
                d.amount::float AS amount,
                d.category, d.reason_code,
                CASE WHEN d.confidence_score IS NULL THEN NULL ELSE d.confidence_score::float END AS confidence_score,
                d.risk_level, d.recommended_action, d.recommendation_explanation,
                d.status, d.resolution_notes, d.bank_txn_id, d.ledger_txn_id,
                bank.source AS bank_source_type,
                ledger.source AS ledger_source_type,
                d.created_at, d.updated_at
             FROM reconciliation_discrepancies d
             LEFT JOIN transactions bank ON bank.id = d.bank_txn_id AND bank.company_id = d.company_id
             LEFT JOIN transactions ledger ON ledger.id = d.ledger_txn_id AND ledger.company_id = d.company_id
             WHERE {$where}
             ORDER BY
                CASE d.risk_level WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                d.amount DESC, d.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );

        $countRow = DB::selectOne(
            "SELECT COUNT(*)::int AS total
             FROM reconciliation_discrepancies d
             LEFT JOIN transactions bank ON bank.id = d.bank_txn_id AND bank.company_id = d.company_id
             LEFT JOIN transactions ledger ON ledger.id = d.ledger_txn_id AND ledger.company_id = d.company_id
             WHERE {$where}",
            $params
        );

        $total = (int)($countRow->total ?? 0);

        return [
            'discrepancies' => array_map(fn($r) => (array)$r, $rows),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int)ceil($total / $limit),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Discrepancy Detail
    // -------------------------------------------------------------------------

    public function getDiscrepancyDetail(string $companyId, string $discrepancyId): ?array
    {
        $row = DB::selectOne(
            "SELECT
                d.id, d.run_id,
                COALESCE(bank.date, ledger.date) AS date,
                COALESCE(bank.vendor_customer, ledger.vendor_customer, 'Unknown') AS vendor,
                d.amount::float AS amount,
                d.category, d.reason_code,
                CASE WHEN d.confidence_score IS NULL THEN NULL ELSE d.confidence_score::float END AS confidence_score,
                d.risk_level, d.recommended_action, d.recommendation_explanation,
                d.status, d.resolution_notes, d.bank_txn_id, d.ledger_txn_id,
                bank.source AS bank_source_type,
                ledger.source AS ledger_source_type,
                d.metadata, d.created_at, d.updated_at
             FROM reconciliation_discrepancies d
             LEFT JOIN transactions bank ON bank.id = d.bank_txn_id AND bank.company_id = d.company_id
             LEFT JOIN transactions ledger ON ledger.id = d.ledger_txn_id AND ledger.company_id = d.company_id
             WHERE d.company_id = ? AND d.id = ?
             LIMIT 1",
            [$companyId, $discrepancyId]
        );

        if (!$row) return null;

        $base = (array)$row;
        $base['metadata'] = is_string($base['metadata']) ? json_decode($base['metadata'], true) : ($base['metadata'] ?? []);

        $bankEntry = $base['bank_txn_id'] ? $this->getTransactionSummary($companyId, $base['bank_txn_id']) : null;
        $ledgerEntry = $base['ledger_txn_id'] ? $this->getTransactionSummary($companyId, $base['ledger_txn_id']) : null;
        $events = $this->getDiscrepancyEvents($companyId, $discrepancyId);

        return array_merge($base, [
            'bank_entry' => $bankEntry,
            'ledger_entry' => $ledgerEntry,
            'events' => $events,
        ]);
    }

    // -------------------------------------------------------------------------
    // Workflow Actions
    // -------------------------------------------------------------------------

    public function updateStatus(string $companyId, string $userId, string $discrepancyId, string $status, ?string $note = null): ?array
    {
        $existing = ReconciliationDiscrepancy::where('id', $discrepancyId)
            ->where('company_id', $companyId)
            ->first();

        if (!$existing) return null;

        $existing->update([
            'status' => $status,
            'resolution_notes' => $note ?? $existing->resolution_notes,
        ]);

        ReconciliationDiscrepancyEvent::create([
            'company_id' => $companyId,
            'discrepancy_id' => $discrepancyId,
            'user_id' => $userId,
            'event_type' => 'status_changed',
            'previous_status' => $existing->getOriginal('status'),
            'next_status' => $status,
            'note' => $note,
            'metadata' => ['source' => 'status_update'],
        ]);

        return $this->getDiscrepancyDetail($companyId, $discrepancyId);
    }

    public function confirmAction(string $companyId, string $userId, string $discrepancyId, string $action, ?string $note = null): ?array
    {
        $existing = ReconciliationDiscrepancy::where('id', $discrepancyId)
            ->where('company_id', $companyId)
            ->first();

        if (!$existing) return null;

        $previousStatus = $existing->status;
        $nextStatus = $action === 'escalate_for_review' ? 'escalated' : 'confirmed_action';

        $existing->update([
            'status' => $nextStatus,
            'resolution_notes' => $note ?? $existing->resolution_notes,
        ]);

        ReconciliationDiscrepancyEvent::create([
            'company_id' => $companyId,
            'discrepancy_id' => $discrepancyId,
            'user_id' => $userId,
            'event_type' => 'action_confirmed',
            'previous_status' => $previousStatus,
            'next_status' => $nextStatus,
            'selected_action' => $action,
            'note' => $note,
            'metadata' => [
                'recommended_action' => $existing->recommended_action,
                'confirmed_action' => $action,
            ],
        ]);

        return $this->getDiscrepancyDetail($companyId, $discrepancyId);
    }

    public function addNote(string $companyId, string $userId, string $discrepancyId, string $note): ?array
    {
        $exists = ReconciliationDiscrepancy::where('id', $discrepancyId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) return null;

        ReconciliationDiscrepancyEvent::create([
            'company_id' => $companyId,
            'discrepancy_id' => $discrepancyId,
            'user_id' => $userId,
            'event_type' => 'note_added',
            'note' => $note,
            'metadata' => ['source' => 'note'],
        ]);

        ReconciliationDiscrepancy::where('id', $discrepancyId)
            ->where('company_id', $companyId)
            ->touch();

        return $this->getDiscrepancyDetail($companyId, $discrepancyId);
    }

    // -------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------

    private function getLatestCompletedRunId(string $companyId): ?string
    {
        return ReconciliationResult::where('company_id', $companyId)
            ->where('status', 'completed')
            ->latest('run_at')
            ->value('id');
    }

    private function getTransactionSummary(string $companyId, string $transactionId): ?array
    {
        $row = DB::selectOne(
            "SELECT id, date, vendor_customer, amount::float, source AS source_type,
                    invoice_ref, memo, category, type, payment_method
             FROM transactions
             WHERE company_id = ? AND id = ?",
            [$companyId, $transactionId]
        );

        return $row ? (array)$row : null;
    }

    private function getDiscrepancyEvents(string $companyId, string $discrepancyId): array
    {
        $rows = DB::select(
            "SELECT e.id, e.event_type, e.previous_status, e.next_status,
                    e.selected_action, e.note, e.metadata, e.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS user_name
             FROM reconciliation_discrepancy_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.company_id = ? AND e.discrepancy_id = ?
             ORDER BY e.created_at DESC",
            [$companyId, $discrepancyId]
        );

        return array_map(fn($r) => (array)$r, $rows);
    }
}
