<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransactionService
{
    // -------------------------------------------------------------------------
    // List Transactions (with all signals computed server-side)
    // -------------------------------------------------------------------------

    public function list(string $companyId, array $filters = []): array
    {
        $limit = min((int)($filters['limit'] ?? 200), 500);
        $offset = max((int)($filters['offset'] ?? 0), 0);

        [$where, $params] = $this->buildWhereClause($companyId, $filters);

        $sql = "
            WITH latest_recon AS (
                SELECT id, run_at, period_start, period_end
                FROM reconciliation_results
                WHERE company_id = ?
                  AND status = 'completed'
                ORDER BY run_at DESC
                LIMIT 1
            ),
            filtered AS (
                SELECT
                    t.id,
                    t.date,
                    t.department,
                    t.vendor_customer AS vendor,
                    t.type,
                    t.category,
                    t.payment_method,
                    CAST(t.amount AS FLOAT) AS amount,
                    t.invoice_ref,
                    t.memo,
                    COALESCE(t.anomaly_flag, FALSE) AS anomaly_flag,
                    t.anomaly_reason,
                    CASE WHEN COALESCE(t.anomaly_flag, FALSE) THEN 'flagged' ELSE 'completed' END AS status,
                    t.source_type,
                    t.source_name
                FROM all_transactions t
                WHERE {$where}
            )
            SELECT
                filtered.*,
                COALESCE(alert_meta.linked_alert_count, 0)::int AS linked_alert_count,
                case_meta.linked_case_id,
                case_meta.linked_case_status,
                COALESCE(review_meta.review_state, 'none') AS review_state,
                alert_meta.explainability_summary,
                CASE
                    WHEN latest_recon.id IS NULL
                        OR filtered.date IS NULL
                        OR filtered.date < latest_recon.period_start
                        OR filtered.date > latest_recon.period_end THEN 'not_run'
                    WHEN recon_meta.mismatch_type IS NULL THEN 'matched'
                    ELSE 'unmatched'
                END AS reconciliation_state,
                CASE
                    WHEN latest_recon.id IS NOT NULL
                        AND filtered.date IS NOT NULL
                        AND filtered.date BETWEEN latest_recon.period_start AND latest_recon.period_end
                        AND recon_meta.mismatch_type = 'omission' THEN 'upload_only'
                    WHEN latest_recon.id IS NOT NULL
                        AND filtered.date IS NOT NULL
                        AND filtered.date BETWEEN latest_recon.period_start AND latest_recon.period_end THEN 'multi_source'
                    ELSE 'upload_only'
                END AS source_completeness,
                CASE
                    WHEN COALESCE(alert_meta.duplicate_candidate, FALSE)
                        OR recon_meta.mismatch_type = 'duplication' THEN TRUE
                    ELSE FALSE
                END AS duplicate_candidate
            FROM filtered
            LEFT JOIN latest_recon ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*)::int AS linked_alert_count,
                    BOOL_OR(a.rule_key = 'duplicate_invoice') AS duplicate_candidate,
                    MAX(COALESCE(
                        NULLIF(filtered.anomaly_reason, ''),
                        NULLIF(a.title, ''),
                        NULLIF(a.detail, '')
                    )) AS explainability_summary
                FROM alerts a
                WHERE a.company_id = ?
                  AND EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements_text(COALESCE(a.evidence->'transactionIds', '[]'::jsonb)) txn_id
                    WHERE txn_id = filtered.id::text
                  )
            ) alert_meta ON TRUE
            LEFT JOIN LATERAL (
                SELECT ac.id AS linked_case_id, ac.status AS linked_case_status
                FROM audit_cases ac
                WHERE ac.company_id = ?
                  AND filtered.id = ANY(ac.transaction_ids)
                ORDER BY CASE ac.status
                    WHEN 'open' THEN 0 WHEN 'investigating' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END,
                    ac.updated_at DESC
                LIMIT 1
            ) case_meta ON TRUE
            LEFT JOIN LATERAL (
                SELECT 'marked'::text AS review_state
                FROM transaction_reviews tr
                WHERE tr.company_id = ? AND tr.transaction_id = filtered.id
                LIMIT 1
            ) review_meta ON TRUE
            LEFT JOIN LATERAL (
                SELECT mismatch_type
                FROM reconciliation_mismatches m
                JOIN latest_recon lr ON lr.id = m.run_id
                WHERE m.company_id = ?
                  AND (m.bank_txn_id = filtered.id OR m.ledger_txn_id = filtered.id)
                ORDER BY m.created_at DESC
                LIMIT 1
            ) recon_meta ON TRUE
            ORDER BY filtered.date DESC NULLS LAST, filtered.id DESC
            LIMIT ? OFFSET ?
        ";

        // The CTE uses companyId once, then the lateral joins use it 4 more times
        $queryParams = [$companyId, ...$params, $companyId, $companyId, $companyId, $companyId, $limit, $offset];

        $countSql = "SELECT COUNT(*)::int AS total FROM all_transactions t WHERE {$where}";

        [$rows, $countRow] = [
            DB::select($sql, $queryParams),
            DB::selectOne($countSql, $params),
        ];

        return [
            'transactions' => array_map(fn($r) => $this->castTransactionRow((array)$r), $rows),
            'total' => (int)($countRow->total ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Transaction Detail
    // -------------------------------------------------------------------------

    public function detail(string $companyId, string $transactionId, ?string $businessProfileId = null): ?array
    {
        $profileFilter = '';
        $params = [$companyId, $transactionId];
        if ($businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id')) {
            $profileFilter = ' AND t.business_profile_id = ?';
            $params[] = $businessProfileId;
        }

        $base = DB::selectOne(
            "SELECT
                t.id, t.date, t.department, t.vendor_customer AS vendor,
                t.type, t.category, t.payment_method,
                CAST(t.amount AS FLOAT) AS amount,
                t.invoice_ref, t.memo,
                COALESCE(t.anomaly_flag, FALSE) AS anomaly_flag,
                t.anomaly_reason,
                CASE WHEN COALESCE(t.anomaly_flag, FALSE) THEN 'flagged' ELSE 'completed' END AS status,
                t.raw_row, t.upload_id, t.txn_id, t.source_type, t.source_name,
                u.status AS upload_status, u.created_at AS upload_created_at
             FROM all_transactions t
             LEFT JOIN uploads u ON u.id = t.upload_id
             WHERE t.company_id = ? AND t.id = ?{$profileFilter}",
            $params
        );

        if (!$base) return null;
        $base = (array)$base;

        $linkedAlerts = $this->getLinkedAlerts($companyId, $transactionId);
        $linkedCase = $this->getLinkedCase($companyId, $transactionId);
        $reviewRow = DB::selectOne(
            "SELECT created_at FROM transaction_reviews WHERE company_id = ? AND transaction_id = ? LIMIT 1",
            [$companyId, $transactionId]
        );
        $reconciliation = $this->getReconciliationDetail($companyId, $transactionId, $base['date']);

        $explainability = $this->buildExplainability($base['anomaly_reason'], $linkedAlerts);
        $explainabilitySummary = $this->formatExplainabilitySummary(
            $base['anomaly_reason'],
            $linkedAlerts[0]['title'] ?? null,
            $linkedAlerts[0]['detail'] ?? null
        );

        return [
            'id' => $base['id'],
            'date' => $base['date'],
            'department' => $base['department'],
            'vendor' => $base['vendor'],
            'type' => $base['type'],
            'category' => $base['category'],
            'payment_method' => $base['payment_method'],
            'amount' => (float)($base['amount'] ?? 0),
            'invoice_ref' => $base['invoice_ref'],
            'memo' => $base['memo'],
            'anomaly_flag' => (bool)$base['anomaly_flag'],
            'anomaly_reason' => $base['anomaly_reason'],
            'status' => $base['status'],
            'source_type' => $base['source_type'],
            'source_name' => $base['source_name'],
            'linked_alert_count' => count($linkedAlerts),
            'linked_case_id' => $linkedCase['id'] ?? null,
            'linked_case_status' => $linkedCase['status'] ?? null,
            'explainability_summary' => $explainabilitySummary,
            'reconciliation_state' => $reconciliation['state'],
            'source_completeness' => $reconciliation['sourceCompleteness'],
            'duplicate_candidate' => collect($linkedAlerts)->contains('rule_key', 'duplicate_invoice')
                || ($reconciliation['detail']['mismatch_type'] === 'duplication'),
            'review_state' => $reviewRow ? 'marked' : 'none',
            'record_identifiers' => [
                'transaction_id' => $transactionId,
                'source_record_id' => $base['txn_id'],
                'upload_id' => $base['upload_id'],
            ],
            'timestamps' => [
                'transaction_date' => $base['date'],
                'source_recorded_at' => $base['upload_created_at'],
                'review_marked_at' => $reviewRow?->created_at ?? null,
            ],
            'source_status' => $base['upload_status'],
            'raw_payload' => is_string($base['raw_row']) ? json_decode($base['raw_row'], true) : $base['raw_row'],
            'linked_alerts' => $linkedAlerts,
            'linked_case' => $linkedCase,
            'explainability' => $explainability,
            'reconciliation_detail' => array_merge(['state' => $reconciliation['state']], $reconciliation['detail']),
        ];
    }

    // -------------------------------------------------------------------------
    // Review State Toggle
    // -------------------------------------------------------------------------

    public function setReviewState(string $companyId, string $userId, string $transactionId, bool $marked, ?string $businessProfileId = null): ?array
    {
        $profileFilter = '';
        $params = [$companyId, $transactionId];
        if ($businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id')) {
            $profileFilter = ' AND business_profile_id = ?';
            $params[] = $businessProfileId;
        }

        $exists = DB::selectOne(
            "SELECT id FROM all_transactions WHERE company_id = ? AND id = ?{$profileFilter} LIMIT 1",
            $params
        );

        if (!$exists) return null;

        if ($marked) {
            DB::statement(
                "INSERT INTO transaction_reviews (company_id, transaction_id, marked_by)
                 VALUES (?, ?, ?)
                 ON CONFLICT (company_id, transaction_id)
                 DO UPDATE SET marked_by = EXCLUDED.marked_by, updated_at = NOW()",
                [$companyId, $transactionId, $userId]
            );
        } else {
            DB::delete(
                "DELETE FROM transaction_reviews WHERE company_id = ? AND transaction_id = ?",
                [$companyId, $transactionId]
            );
        }

        return [
            'transaction_id' => $transactionId,
            'review_state' => $marked ? 'marked' : 'none',
        ];
    }

    // -------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------

    private function buildWhereClause(string $companyId, array $filters): array
    {
        $conditions = ['t.company_id = ?'];
        $params = [$companyId];

        if (!empty($filters['business_profile_id']) && Schema::hasColumn('all_transactions', 'business_profile_id')) {
            $conditions[] = 't.business_profile_id = ?';
            $params[] = $filters['business_profile_id'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 't.date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = 't.date <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'flagged') {
                $conditions[] = 't.anomaly_flag = TRUE';
            } elseif ($filters['status'] === 'completed') {
                $conditions[] = 'COALESCE(t.anomaly_flag, FALSE) = FALSE';
            }
        }
        if (!empty($filters['vendor'])) {
            $conditions[] = "LOWER(COALESCE(t.vendor_customer, '')) LIKE ?";
            $params[] = '%' . strtolower($filters['vendor']) . '%';
        }
        if (!empty($filters['category'])) {
            $conditions[] = "LOWER(COALESCE(t.category, '')) LIKE ?";
            $params[] = '%' . strtolower($filters['category']) . '%';
        }
        if (!empty($filters['min_amount'])) {
            $conditions[] = 'ABS(COALESCE(t.amount, 0)) >= ?';
            $params[] = (float)$filters['min_amount'];
        }
        if (!empty($filters['type'])) {
            $conditions[] = "LOWER(COALESCE(t.type, '')) = ?";
            $params[] = strtolower($filters['type']);
        }
        if (!empty($filters['source_type']) && $filters['source_type'] !== 'all') {
            $conditions[] = "LOWER(COALESCE(t.source_type, '')) = ?";
            $params[] = strtolower($filters['source_type']);
        }
        if (!empty($filters['uncategorized'])) {
            $conditions[] = "(t.category IS NULL OR BTRIM(t.category) = '')";
        }
        if (!empty($filters['review_state']) && $filters['review_state'] === 'marked') {
            $conditions[] = "EXISTS (SELECT 1 FROM transaction_reviews tr WHERE tr.company_id = ? AND tr.transaction_id = t.id)";
            $params[] = $companyId;
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function getLinkedAlerts(string $companyId, string $transactionId): array
    {
        $rows = DB::select(
            "SELECT id, title, detail, severity, rule_key, status, created_at, evidence
             FROM alerts
             WHERE company_id = ?
               AND EXISTS (
                 SELECT 1
                 FROM jsonb_array_elements_text(COALESCE(evidence->'transactionIds', '[]'::jsonb)) txn_id
                 WHERE txn_id = ?::text
               )
             ORDER BY created_at DESC",
            [$companyId, $transactionId]
        );

        return array_map(fn($r) => (array)$r, $rows);
    }

    private function getLinkedCase(string $companyId, string $transactionId): ?array
    {
        $row = DB::selectOne(
            "SELECT id, title, status, severity, updated_at
             FROM audit_cases
             WHERE company_id = ?
               AND ?::uuid = ANY(transaction_ids)
             ORDER BY CASE status
                WHEN 'open' THEN 0 WHEN 'investigating' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END,
                updated_at DESC
             LIMIT 1",
            [$companyId, $transactionId]
        );

        return $row ? (array)$row : null;
    }

    private function getReconciliationDetail(string $companyId, string $transactionId, ?string $date): array
    {
        $latestRun = DB::selectOne(
            "SELECT id, run_at, period_start, period_end
             FROM reconciliation_results
             WHERE company_id = ? AND status = 'completed'
             ORDER BY run_at DESC LIMIT 1",
            [$companyId]
        );

        if (!$latestRun || !$date) {
            return [
                'state' => 'not_run',
                'sourceCompleteness' => 'upload_only',
                'detail' => [
                    'mismatch_id' => null, 'mismatch_type' => null,
                    'suggested_cause' => null, 'resolved' => null,
                    'run_id' => $latestRun?->id ?? null,
                    'last_run_at' => $latestRun?->run_at ?? null,
                ],
            ];
        }

        $inScope = $date >= $latestRun->period_start && $date <= $latestRun->period_end;
        if (!$inScope) {
            return [
                'state' => 'not_run',
                'sourceCompleteness' => 'upload_only',
                'detail' => [
                    'mismatch_id' => null, 'mismatch_type' => null,
                    'suggested_cause' => null, 'resolved' => null,
                    'run_id' => $latestRun->id,
                    'last_run_at' => $latestRun->run_at,
                ],
            ];
        }

        $mismatch = DB::selectOne(
            "SELECT id, mismatch_type, suggested_cause, resolved
             FROM reconciliation_mismatches
             WHERE company_id = ? AND run_id = ?
               AND (bank_txn_id = ?::uuid OR ledger_txn_id = ?::uuid)
             ORDER BY created_at DESC LIMIT 1",
            [$companyId, $latestRun->id, $transactionId, $transactionId]
        );

        $state = $mismatch ? 'unmatched' : 'matched';
        $sourceCompleteness = ($mismatch?->mismatch_type === 'omission') ? 'upload_only' : 'multi_source';

        return [
            'state' => $state,
            'sourceCompleteness' => $sourceCompleteness,
            'detail' => [
                'mismatch_id' => $mismatch?->id ?? null,
                'mismatch_type' => $mismatch?->mismatch_type ?? null,
                'suggested_cause' => $mismatch?->suggested_cause ?? null,
                'resolved' => isset($mismatch->resolved) ? (bool)$mismatch->resolved : null,
                'run_id' => $latestRun->id,
                'last_run_at' => $latestRun->run_at,
            ],
        ];
    }

    private function buildExplainability(?string $anomalyReason, array $alerts): array
    {
        $items = [];
        if ($anomalyReason) {
            $items[] = ['kind' => 'transaction', 'label' => 'Transaction anomaly', 'detail' => $anomalyReason];
        }
        $ruleLabels = [
            'duplicate_invoice' => 'Duplicate invoice pattern',
            'spend_spike' => 'Unusual amount for vendor',
            'off_cycle_payment' => 'Weekend or after-hours posting',
            'large_card_expense' => 'Large card expense anomaly',
            'vendor_risk' => 'Vendor concentration concern',
            'reconciliation_mismatch' => 'Reconciliation mismatch',
        ];
        foreach ($alerts as $alert) {
            $items[] = [
                'kind' => 'alert',
                'label' => $ruleLabels[$alert['rule_key']] ?? $alert['title'] ?? 'Linked alert',
                'detail' => $alert['detail'] ?? $alert['title'],
                'severity' => $alert['severity'] ?? null,
                'rule_key' => $alert['rule_key'] ?? null,
            ];
        }
        return $items;
    }

    private function formatExplainabilitySummary(?string $anomalyReason, ?string $alertTitle, ?string $alertDetail): ?string
    {
        $summary = $anomalyReason ?? $alertTitle ?? $alertDetail;
        if (!$summary) return null;
        return strlen($summary) > 110 ? substr($summary, 0, 107) . '...' : $summary;
    }

    private function castTransactionRow(array $row): array
    {
        return array_merge($row, [
            'anomaly_flag' => (bool)($row['anomaly_flag'] ?? false),
            'amount' => (float)($row['amount'] ?? 0),
            'linked_alert_count' => (int)($row['linked_alert_count'] ?? 0),
            'duplicate_candidate' => (bool)($row['duplicate_candidate'] ?? false),
        ]);
    }
}
