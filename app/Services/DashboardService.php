<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Build the overview payload consumed by the dashboard home screen.
     */
    public function summary(string $companyId): array
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(*)::int AS total_transactions,
                COUNT(DISTINCT NULLIF(TRIM(vendor_customer), ''))::int AS vendors_monitored,
                CAST(COALESCE(SUM(ABS(amount)), 0) AS DOUBLE PRECISION) AS amount_reviewed
             FROM all_transactions
             WHERE company_id = ?",
            [$companyId]
        );

        $alertStats = DB::selectOne(
            "SELECT
                COUNT(*) FILTER (WHERE status = 'open')::int AS flagged_alerts,
                COUNT(*) FILTER (WHERE status = 'open' AND severity = 'critical')::int AS critical_alerts,
                COUNT(*) FILTER (WHERE status = 'open' AND severity = 'warning')::int AS warning_alerts
             FROM alerts
             WHERE company_id = ?",
            [$companyId]
        );

        $trends = $this->trends($companyId);
        $recentAlerts = $this->recentAlerts($companyId);
        $alertBreakdown = $this->alertBreakdown($companyId);

        $flaggedAlerts = (int)($alertStats->flagged_alerts ?? 0);
        $criticalAlerts = (int)($alertStats->critical_alerts ?? 0);
        $warningAlerts = (int)($alertStats->warning_alerts ?? 0);

        return [
            'stats' => [
                'totalTransactions' => (int)($stats->total_transactions ?? 0),
                'flaggedAlerts' => $flaggedAlerts,
                'vendorsMonitored' => (int)($stats->vendors_monitored ?? 0),
                'amountReviewed' => (float)($stats->amount_reviewed ?? 0),
            ],
            'trends' => $trends,
            'riskScore' => $this->riskScore($flaggedAlerts, $criticalAlerts, $warningAlerts),
            'recentAlerts' => $recentAlerts,
            'alertBreakdown' => $alertBreakdown,
        ];
    }

    private function trends(string $companyId): array
    {
        $row = DB::selectOne(
            "WITH month_stats AS (
                SELECT
                    CASE
                        WHEN date >= date_trunc('month', CURRENT_DATE) THEN 'current'
                        WHEN date >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month')
                            AND date < date_trunc('month', CURRENT_DATE) THEN 'previous'
                    END AS period,
                    COUNT(*)::int AS transaction_count,
                    COUNT(DISTINCT NULLIF(TRIM(vendor_customer), ''))::int AS vendor_count,
                    CAST(COALESCE(SUM(ABS(amount)), 0) AS DOUBLE PRECISION) AS amount_reviewed
                FROM all_transactions
                WHERE company_id = ?
                    AND date >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month')
                GROUP BY 1
             )
             SELECT
                COALESCE(MAX(transaction_count) FILTER (WHERE period = 'current'), 0)::int AS current_transactions,
                COALESCE(MAX(transaction_count) FILTER (WHERE period = 'previous'), 0)::int AS previous_transactions,
                COALESCE(MAX(vendor_count) FILTER (WHERE period = 'current'), 0)::int AS current_vendors,
                COALESCE(MAX(vendor_count) FILTER (WHERE period = 'previous'), 0)::int AS previous_vendors,
                COALESCE(MAX(amount_reviewed) FILTER (WHERE period = 'current'), 0) AS current_amount,
                COALESCE(MAX(amount_reviewed) FILTER (WHERE period = 'previous'), 0) AS previous_amount
             FROM month_stats",
            [$companyId]
        );

        $alertRow = DB::selectOne(
            "WITH month_alerts AS (
                SELECT
                    CASE
                        WHEN created_at >= date_trunc('month', CURRENT_DATE) THEN 'current'
                        WHEN created_at >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month')
                            AND created_at < date_trunc('month', CURRENT_DATE) THEN 'previous'
                    END AS period,
                    COUNT(*)::int AS alert_count
                FROM alerts
                WHERE company_id = ?
                    AND status = 'open'
                    AND created_at >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month')
                GROUP BY 1
             )
             SELECT
                COALESCE(MAX(alert_count) FILTER (WHERE period = 'current'), 0)::int AS current_alerts,
                COALESCE(MAX(alert_count) FILTER (WHERE period = 'previous'), 0)::int AS previous_alerts
             FROM month_alerts",
            [$companyId]
        );

        return [
            'transactions' => $this->trendPayload((float)($row->current_transactions ?? 0), (float)($row->previous_transactions ?? 0)),
            'alerts' => $this->trendPayload((float)($alertRow->current_alerts ?? 0), (float)($alertRow->previous_alerts ?? 0), false),
            'vendors' => $this->trendPayload((float)($row->current_vendors ?? 0), (float)($row->previous_vendors ?? 0)),
            'amount' => $this->trendPayload((float)($row->current_amount ?? 0), (float)($row->previous_amount ?? 0)),
        ];
    }

    private function recentAlerts(string $companyId): array
    {
        $rows = DB::select(
            "SELECT id, title, detail, severity, created_at
             FROM alerts
             WHERE company_id = ? AND status = 'open'
             ORDER BY created_at DESC
             LIMIT 5",
            [$companyId]
        );

        return array_map(fn ($row) => [
            'id' => (string)$row->id,
            'title' => (string)$row->title,
            'detail' => (string)($row->detail ?? ''),
            'severity' => (string)$row->severity,
            'createdAt' => (string)$row->created_at,
        ], $rows);
    }

    private function alertBreakdown(string $companyId): array
    {
        $rows = DB::select(
            "SELECT COALESCE(NULLIF(TRIM(rule_key), ''), 'Alerts') AS label, COUNT(*)::int AS count
             FROM alerts
             WHERE company_id = ? AND status = 'open'
             GROUP BY 1
             ORDER BY count DESC",
            [$companyId]
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$this->formatRuleLabel((string)$row->label)] = (int)$row->count;
        }

        return $breakdown;
    }

    private function trendPayload(float $current, float $previous, bool $higherIsPositive = true): array
    {
        if ($previous == 0.0) {
            $value = $current > 0.0 ? 100.0 : 0.0;
        } else {
            $value = (($current - $previous) / abs($previous)) * 100;
        }

        $rounded = round(abs($value), 1);
        $increased = $current >= $previous;

        return [
            'value' => $rounded,
            'positive' => $higherIsPositive ? $increased : !$increased,
        ];
    }

    private function riskScore(int $flaggedAlerts, int $criticalAlerts, int $warningAlerts): int
    {
        return min(100, ($criticalAlerts * 20) + ($warningAlerts * 10) + max(0, $flaggedAlerts - $criticalAlerts - $warningAlerts) * 4);
    }

    private function formatRuleLabel(string $ruleKey): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $ruleKey));
    }
}
