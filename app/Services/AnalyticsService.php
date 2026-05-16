<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsService
{
    public function getPerformanceSummary(string $companyId): array
    {
        $sql = "
            WITH month_stats AS (
                SELECT
                    date_trunc('month', date) as month,
                    COUNT(*) as count,
                    SUM(amount) as total_spend,
                    SUM(CASE WHEN anomaly_flag = TRUE THEN amount ELSE 0 END) as flagged_amount
                FROM all_transactions
                WHERE company_id = ?
                AND date >= date_trunc('month', CURRENT_DATE - INTERVAL '2 months')
                GROUP BY 1
            )
            SELECT 
                month,
                count,
                CAST(total_spend AS DOUBLE PRECISION) as total_spend,
                CAST(flagged_amount AS DOUBLE PRECISION) as flagged_amount
            FROM month_stats
            ORDER BY month DESC
        ";

        $rows = DB::select($sql, [$companyId]);

        $cmStart = Carbon::now()->startOfMonth()->toDateString();
        $pmStart = Carbon::now()->subMonth()->startOfMonth()->toDateString();

        $currentMonth = null;
        $prevMonth = null;

        foreach ($rows as $row) {
            $rowMonth = Carbon::parse($row->month)->toDateString();
            if ($rowMonth === $cmStart) {
                $currentMonth = $row;
            } elseif ($rowMonth === $pmStart) {
                $prevMonth = $row;
            }
        }

        return [
            'totalSpend' => [
                'value' => (float)($currentMonth->total_spend ?? 0),
                'prevValue' => (float)($prevMonth->total_spend ?? 0),
                'mom' => $this->calculateMoM((float)($currentMonth->total_spend ?? 0), (float)($prevMonth->total_spend ?? 0))
            ],
            'transactionCount' => [
                'value' => (int)($currentMonth->count ?? 0),
                'prevValue' => (int)($prevMonth->count ?? 0),
                'mom' => $this->calculateMoM((int)($currentMonth->count ?? 0), (int)($prevMonth->count ?? 0))
            ],
            'flaggedAmount' => [
                'value' => (float)($currentMonth->flagged_amount ?? 0),
                'prevValue' => (float)($prevMonth->flagged_amount ?? 0),
                'mom' => $this->calculateMoM((float)($currentMonth->flagged_amount ?? 0), (float)($prevMonth->flagged_amount ?? 0))
            ]
        ];
    }

    public function getTopVendors(string $companyId, int $limit = 5): array
    {
        $sql = "
            SELECT 
                vendor_customer as name,
                CAST(SUM(amount) AS DOUBLE PRECISION) as amount,
                COUNT(*)::int as count
            FROM all_transactions
            WHERE company_id = ?
            GROUP BY 1
            ORDER BY amount DESC
            LIMIT ?
        ";

        return DB::select($sql, [$companyId, $limit]);
    }

    public function getCashFlowAnalytics(string $companyId): array
    {
        $sql = "
            SELECT 
                date_trunc('month', date) as month,
                CAST(SUM(amount) AS DOUBLE PRECISION) as monthly_spend
            FROM all_transactions
            WHERE company_id = ?
            AND date >= date_trunc('month', CURRENT_DATE - INTERVAL '3 months')
            GROUP BY 1
            ORDER BY month DESC
        ";

        $rows = DB::select($sql, [$companyId]);

        $totalSpend = 0;
        $trailingMonths = [];

        foreach ($rows as $row) {
            $spend = (float)($row->monthly_spend ?? 0);
            $totalSpend += $spend;
            $trailingMonths[] = [
                'month' => $row->month,
                'spend' => $spend
            ];
        }

        $avgBurn = count($rows) > 0 ? $totalSpend / count($rows) : 0;

        return [
            'monthlyBurn' => $avgBurn,
            'trailingMonths' => $trailingMonths
        ];
    }

    private function calculateMoM(float $current, float $prev): float
    {
        if ($prev == 0) return 0;
        return (($current - $prev) / $prev) * 100;
    }
}
