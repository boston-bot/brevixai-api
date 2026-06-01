<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsService
{
    public function getPerformanceSummary(string $companyId, ?string $businessProfileId = null): array
    {
        $currentStart = CarbonImmutable::now()->startOfMonth();
        $previousStart = $currentStart->subMonth();

        $currentMonth = $this->monthStats($companyId, $businessProfileId, $currentStart, $currentStart->addMonth());
        $prevMonth = $this->monthStats($companyId, $businessProfileId, $previousStart, $currentStart);

        return [
            'totalSpend' => [
                'value' => $currentMonth['totalSpend'],
                'prevValue' => $prevMonth['totalSpend'],
                'mom' => $this->calculateMoM($currentMonth['totalSpend'], $prevMonth['totalSpend']),
            ],
            'transactionCount' => [
                'value' => $currentMonth['count'],
                'prevValue' => $prevMonth['count'],
                'mom' => $this->calculateMoM((float) $currentMonth['count'], (float) $prevMonth['count']),
            ],
            'flaggedAmount' => [
                'value' => $currentMonth['flaggedAmount'],
                'prevValue' => $prevMonth['flaggedAmount'],
                'mom' => $this->calculateMoM($currentMonth['flaggedAmount'], $prevMonth['flaggedAmount']),
            ],
        ];
    }

    public function getTopVendors(string $companyId, ?string $businessProfileId = null, int $limit = 5): array
    {
        $limit = max(1, min($limit, 50));

        return $this->transactionsQuery($companyId, $businessProfileId)
            ->selectRaw('vendor_customer AS name, SUM(amount) AS amount, COUNT(*) AS count')
            ->groupBy('vendor_customer')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): object => (object) [
                'name' => (string) ($row->name ?? ''),
                'amount' => (float) ($row->amount ?? 0),
                'count' => (int) ($row->count ?? 0),
            ])
            ->all();
    }

    public function getCashFlowAnalytics(string $companyId, ?string $businessProfileId = null): array
    {
        $start = CarbonImmutable::now()->subMonths(3)->startOfMonth();
        $rows = $this->transactionsQuery($companyId, $businessProfileId)
            ->select(['date', 'amount'])
            ->where('date', '>=', $start->toDateString())
            ->get();

        $totalSpend = 0;
        $months = [];

        foreach ($rows as $row) {
            if (! $row->date) {
                continue;
            }

            $month = Carbon::parse($row->date)->startOfMonth()->toDateString();
            $months[$month] = ($months[$month] ?? 0) + (float) ($row->amount ?? 0);
        }

        krsort($months);

        $trailingMonths = [];
        foreach ($months as $month => $spend) {
            $totalSpend += $spend;
            $trailingMonths[] = [
                'month' => $month,
                'spend' => $spend,
            ];
        }

        $avgBurn = count($trailingMonths) > 0 ? $totalSpend / count($trailingMonths) : 0;

        return [
            'monthlyBurn' => $avgBurn,
            'trailingMonths' => $trailingMonths,
        ];
    }

    private function calculateMoM(float $current, float $prev): float
    {
        if ($prev == 0) {
            return 0;
        }

        return (($current - $prev) / $prev) * 100;
    }

    /** @return array{count: int, totalSpend: float, flaggedAmount: float} */
    private function monthStats(string $companyId, ?string $businessProfileId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = $this->transactionsQuery($companyId, $businessProfileId)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $end->toDateString());

        return [
            'count' => (int) (clone $query)->count(),
            'totalSpend' => (float) (clone $query)->sum('amount'),
            'flaggedAmount' => (float) (clone $query)->where('anomaly_flag', true)->sum('amount'),
        ];
    }

    private function transactionsQuery(string $companyId, ?string $businessProfileId): Builder
    {
        $query = DB::table('all_transactions')->where('company_id', $companyId);

        if ($businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        return $query;
    }
}
