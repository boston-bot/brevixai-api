<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\DB;

class BehavioralBaselineService
{
    private const BASELINE_DAYS = 90;

    private const DEVIATION_WEIGHTS = [
        'weekly_spend'   => 0.40,
        'vendor_count'   => 0.25,
        'payment_freq'   => 0.20,
        'category_shift' => 0.15,
    ];

    /**
     * Compute the baseline spending behavior for a company over the last 90 days.
     *
     * @return array{
     *     avg_weekly_spend: float,
     *     avg_vendor_count: float,
     *     top_categories: list<string>,
     *     payment_frequency_distribution: array<string, float>,
     *     baseline_period_days: int,
     *     transaction_count: int
     * }
     */
    public function computeBaseline(string $companyId, string $period): array
    {
        $cutoff = now()->subDays(self::BASELINE_DAYS)->toDateString();

        $rows = DB::table('all_transactions')
            ->where('company_id', $companyId)
            ->whereDate('date', '>=', $cutoff)
            ->select(['date', 'amount', 'vendor_customer', 'category', 'payment_method'])
            ->get();

        if ($rows->isEmpty()) {
            return $this->emptyBaseline();
        }

        // Weekly spend average
        $weeklySpend = $rows->groupBy(fn ($r) => date('W-Y', strtotime($r->date)))
            ->map(fn ($week) => $week->sum(fn ($r) => abs((float) $r->amount)))
            ->average() ?? 0.0;

        // Unique vendor count per week
        $avgVendorCount = $rows->groupBy(fn ($r) => date('W-Y', strtotime($r->date)))
            ->map(fn ($week) => $week->pluck('vendor_customer')->unique()->count())
            ->average() ?? 0.0;

        // Top spending categories
        $topCategories = $rows->groupBy('category')
            ->map(fn ($group) => $group->sum(fn ($r) => abs((float) $r->amount)))
            ->sortDesc()
            ->keys()
            ->take(5)
            ->values()
            ->all();

        // Payment method distribution
        $total = max(1, $rows->count());
        $paymentDist = $rows->groupBy('payment_method')
            ->map(fn ($group) => round($group->count() / $total, 4))
            ->all();

        return [
            'avg_weekly_spend'              => round((float) $weeklySpend, 2),
            'avg_vendor_count'              => round((float) $avgVendorCount, 1),
            'top_categories'                => $topCategories,
            'payment_frequency_distribution' => $paymentDist,
            'baseline_period_days'          => self::BASELINE_DAYS,
            'transaction_count'             => $rows->count(),
        ];
    }

    /**
     * Score how much the current period deviates from the baseline (0–100).
     *
     * @return array{
     *     deviation_score: int,
     *     risk_level: string,
     *     anomalies: list<array{dimension: string, description: string, severity: string}>,
     *     baseline: array<string, mixed>,
     *     current: array<string, mixed>
     * }
     */
    public function scoreDeviation(string $companyId): array
    {
        $period = now()->format('Y-m');
        $baseline = $this->computeBaseline($companyId, $period);

        if ($baseline['transaction_count'] === 0) {
            return [
                'deviation_score' => 0,
                'risk_level'      => 'info',
                'anomalies'       => [],
                'baseline'        => $baseline,
                'current'         => [],
            ];
        }

        // Current 30-day window
        $current = $this->currentPeriodStats($companyId);
        $anomalies = [];
        $scores = [];

        // Weekly spend deviation
        if ($baseline['avg_weekly_spend'] > 0) {
            $spendRatio = abs($current['avg_weekly_spend'] - $baseline['avg_weekly_spend']) / $baseline['avg_weekly_spend'];
            $scores['weekly_spend'] = min(100, (int) round($spendRatio * 100));
            if ($spendRatio > 0.30) {
                $direction = $current['avg_weekly_spend'] > $baseline['avg_weekly_spend'] ? 'higher' : 'lower';
                $anomalies[] = [
                    'dimension'   => 'weekly_spend',
                    'description' => sprintf('Weekly spend is %.0f%% %s than the 90-day baseline.', $spendRatio * 100, $direction),
                    'severity'    => $spendRatio > 0.60 ? 'high' : 'medium',
                ];
            }
        } else {
            $scores['weekly_spend'] = 0;
        }

        // Vendor count deviation
        if ($baseline['avg_vendor_count'] > 0) {
            $vendorRatio = abs($current['vendor_count'] - $baseline['avg_vendor_count']) / $baseline['avg_vendor_count'];
            $scores['vendor_count'] = min(100, (int) round($vendorRatio * 100));
            if ($vendorRatio > 0.40) {
                $direction = $current['vendor_count'] > $baseline['avg_vendor_count'] ? 'more' : 'fewer';
                $anomalies[] = [
                    'dimension'   => 'vendor_count',
                    'description' => sprintf('Activity involves %.0f%% %s vendors than usual.', $vendorRatio * 100, $direction),
                    'severity'    => 'medium',
                ];
            }
        } else {
            $scores['vendor_count'] = 0;
        }

        // Payment frequency deviation
        $currentDist = $current['payment_frequency_distribution'] ?? [];
        $baselineDist = $baseline['payment_frequency_distribution'] ?? [];
        $distDrift = $this->distributionDrift($baselineDist, $currentDist);
        $scores['payment_freq'] = min(100, (int) round($distDrift * 100));
        if ($distDrift > 0.25) {
            $anomalies[] = [
                'dimension'   => 'payment_frequency',
                'description' => 'Payment method mix has shifted significantly from the established pattern.',
                'severity'    => 'low',
            ];
        }

        // Category shift — new top categories not in baseline top 5
        $scores['category_shift'] = 0;
        $baselineTop = array_map('strtolower', $baseline['top_categories']);
        foreach ($current['top_categories'] as $cat) {
            if (! in_array(strtolower((string) $cat), $baselineTop, true)) {
                $scores['category_shift'] = min(100, $scores['category_shift'] + 20);
                $anomalies[] = [
                    'dimension'   => 'category_shift',
                    'description' => "Category '{$cat}' is a top spend area but was not in the 90-day baseline.",
                    'severity'    => 'low',
                ];
            }
        }

        // Weighted final score
        $deviationScore = 0;
        foreach (self::DEVIATION_WEIGHTS as $key => $weight) {
            $deviationScore += ($scores[$key] ?? 0) * $weight;
        }
        $deviationScore = min(100, (int) round($deviationScore));

        return [
            'deviation_score' => $deviationScore,
            'risk_level'      => $this->riskLevel($deviationScore),
            'anomalies'       => $anomalies,
            'baseline'        => $baseline,
            'current'         => $current,
        ];
    }

    private function currentPeriodStats(string $companyId): array
    {
        $cutoff = now()->subDays(30)->toDateString();

        $rows = DB::table('all_transactions')
            ->where('company_id', $companyId)
            ->whereDate('date', '>=', $cutoff)
            ->select(['date', 'amount', 'vendor_customer', 'category', 'payment_method'])
            ->get();

        if ($rows->isEmpty()) {
            return ['avg_weekly_spend' => 0.0, 'vendor_count' => 0, 'top_categories' => [], 'payment_frequency_distribution' => []];
        }

        $weeklySpend = $rows->groupBy(fn ($r) => date('W-Y', strtotime($r->date)))
            ->map(fn ($week) => $week->sum(fn ($r) => abs((float) $r->amount)))
            ->average() ?? 0.0;

        $total = max(1, $rows->count());
        $paymentDist = $rows->groupBy('payment_method')
            ->map(fn ($group) => round($group->count() / $total, 4))
            ->all();

        $topCategories = $rows->groupBy('category')
            ->map(fn ($group) => $group->sum(fn ($r) => abs((float) $r->amount)))
            ->sortDesc()
            ->keys()
            ->take(5)
            ->values()
            ->all();

        return [
            'avg_weekly_spend'              => round((float) $weeklySpend, 2),
            'vendor_count'                  => $rows->pluck('vendor_customer')->unique()->count(),
            'top_categories'                => $topCategories,
            'payment_frequency_distribution' => $paymentDist,
        ];
    }

    private function distributionDrift(array $baseline, array $current): float
    {
        $allKeys = array_unique(array_merge(array_keys($baseline), array_keys($current)));
        $drift = 0.0;
        foreach ($allKeys as $key) {
            $drift += abs(($baseline[$key] ?? 0.0) - ($current[$key] ?? 0.0));
        }

        return min(1.0, $drift / 2.0);
    }

    private function riskLevel(int $score): string
    {
        if ($score >= 75) return 'high';
        if ($score >= 50) return 'medium';
        if ($score >= 25) return 'low';
        return 'info';
    }

    private function emptyBaseline(): array
    {
        return [
            'avg_weekly_spend'              => 0.0,
            'avg_vendor_count'              => 0.0,
            'top_categories'                => [],
            'payment_frequency_distribution' => [],
            'baseline_period_days'          => self::BASELINE_DAYS,
            'transaction_count'             => 0,
        ];
    }
}
