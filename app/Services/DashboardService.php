<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    /**
     * Build the overview payload consumed by the dashboard home screen.
     */
    public function summary(string $companyId, ?string $businessProfileId = null): array
    {
        $transactions = $this->transactionsQuery($companyId, $businessProfileId);
        $amountRow = (clone $transactions)
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) AS amount_reviewed')
            ->first();
        $vendorRow = (clone $transactions)
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(vendor_customer), '')) AS vendor_count")
            ->first();

        $openAlerts = $this->alertsQuery($companyId, $businessProfileId)->where('status', 'open');
        $flaggedAlerts = (int) (clone $openAlerts)->count();
        $criticalAlerts = (int) (clone $openAlerts)->where('severity', 'critical')->count();
        $warningAlerts = (int) (clone $openAlerts)->where('severity', 'warning')->count();

        $trends = $this->trends($companyId, $businessProfileId);
        $recentAlerts = $this->recentAlerts($companyId, $businessProfileId);
        $alertBreakdown = $this->alertBreakdown($companyId, $businessProfileId);

        return [
            'stats' => [
                'totalTransactions' => (int) (clone $transactions)->count(),
                'flaggedAlerts' => $flaggedAlerts,
                'vendorsMonitored' => (int) ($vendorRow->vendor_count ?? 0),
                'amountReviewed' => (float) ($amountRow->amount_reviewed ?? 0),
            ],
            'trends' => $trends,
            'riskScore' => $this->riskScore($flaggedAlerts, $criticalAlerts, $warningAlerts),
            'recentAlerts' => $recentAlerts,
            'alertBreakdown' => $alertBreakdown,
        ];
    }

    private function trends(string $companyId, ?string $businessProfileId): array
    {
        $currentStart = CarbonImmutable::now()->startOfMonth();
        $previousStart = $currentStart->subMonth();
        $nextStart = $currentStart->addMonth();

        $current = $this->periodTransactionStats($companyId, $businessProfileId, $currentStart, $nextStart);
        $previous = $this->periodTransactionStats($companyId, $businessProfileId, $previousStart, $currentStart);
        $currentAlerts = $this->periodAlertCount($companyId, $businessProfileId, $currentStart, $nextStart);
        $previousAlerts = $this->periodAlertCount($companyId, $businessProfileId, $previousStart, $currentStart);

        return [
            'transactions' => $this->trendPayload((float) $current['transactions'], (float) $previous['transactions']),
            'alerts' => $this->trendPayload((float) $currentAlerts, (float) $previousAlerts, false),
            'vendors' => $this->trendPayload((float) $current['vendors'], (float) $previous['vendors']),
            'amount' => $this->trendPayload((float) $current['amount'], (float) $previous['amount']),
        ];
    }

    private function recentAlerts(string $companyId, ?string $businessProfileId): array
    {
        return $this->alertsQuery($companyId, $businessProfileId)
            ->select(['id', 'title', 'detail', 'severity', 'created_at'])
            ->where('status', 'open')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'detail' => (string) ($row->detail ?? ''),
                'severity' => (string) $row->severity,
                'createdAt' => (string) $row->created_at,
            ])
            ->all();
    }

    private function alertBreakdown(string $companyId, ?string $businessProfileId): array
    {
        $rows = $this->alertsQuery($companyId, $businessProfileId)
            ->selectRaw("COALESCE(NULLIF(TRIM(rule_key), ''), 'Alerts') AS label, COUNT(*) AS count")
            ->where('status', 'open')
            ->groupByRaw("COALESCE(NULLIF(TRIM(rule_key), ''), 'Alerts')")
            ->orderByDesc('count')
            ->get();

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$this->formatRuleLabel((string) $row->label)] = (int) $row->count;
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
            'positive' => $higherIsPositive ? $increased : ! $increased,
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

    private function transactionsQuery(string $companyId, ?string $businessProfileId): Builder
    {
        $query = DB::table('all_transactions')->where('company_id', $companyId);

        if ($businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        return $query;
    }

    private function alertsQuery(string $companyId, ?string $businessProfileId): Builder
    {
        $query = DB::table('alerts')->where('company_id', $companyId);

        if ($businessProfileId && Schema::hasColumn('alerts', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        return $query;
    }

    /** @return array{transactions: int, vendors: int, amount: float} */
    private function periodTransactionStats(
        string $companyId,
        ?string $businessProfileId,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $query = $this->transactionsQuery($companyId, $businessProfileId)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $end->toDateString());

        $vendorRow = (clone $query)
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(vendor_customer), '')) AS vendor_count")
            ->first();
        $amountRow = (clone $query)
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) AS amount_reviewed')
            ->first();

        return [
            'transactions' => (int) (clone $query)->count(),
            'vendors' => (int) ($vendorRow->vendor_count ?? 0),
            'amount' => (float) ($amountRow->amount_reviewed ?? 0),
        ];
    }

    private function periodAlertCount(
        string $companyId,
        ?string $businessProfileId,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): int {
        return (int) $this->alertsQuery($companyId, $businessProfileId)
            ->where('status', 'open')
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $end->toDateTimeString())
            ->count();
    }
}
