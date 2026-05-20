<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalFinanceBudgetProfile;
use App\Models\PersonalFinanceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PersonalFinanceAnalyticsService
{
    private const EXCLUDED_CATEGORIES = ['transfer'];

    public function __construct(
        private readonly PersonalFinanceCategorizationService $categorizationService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(string $companyId, array $filters = []): array
    {
        $transactions = $this->transactionQuery($companyId, $filters)
            ->orderBy('posted_date')
            ->get();

        $included = $transactions->filter(fn (PersonalFinanceTransaction $transaction): bool => $this->isIncluded($transaction));
        $inflows = $included->filter(fn (PersonalFinanceTransaction $transaction): bool => $transaction->direction === PersonalFinanceTransaction::DIRECTION_INFLOW);
        $outflows = $included->filter(fn (PersonalFinanceTransaction $transaction): bool => $transaction->direction === PersonalFinanceTransaction::DIRECTION_OUTFLOW);

        $income = round($inflows->sum(fn (PersonalFinanceTransaction $transaction): float => (float) $transaction->amount), 2);
        $outflow = round($outflows->sum(fn (PersonalFinanceTransaction $transaction): float => abs((float) $transaction->amount)), 2);
        $netCashFlow = round($income - $outflow, 2);
        $monthlyTrend = $this->monthlyTrend($included);
        $monthCount = max(1, count($monthlyTrend));
        $averageMonthlyDeficit = round(collect($monthlyTrend)->avg(fn (array $month): float => $month['outflow'] - $month['income']) ?? 0, 2);
        $averageMonthlyOutflow = round($outflow / $monthCount, 2);
        $cumulativeDeficit = round(max(0, $outflow - $income), 2);
        $requiredMonthlyCatchUp = round(max(0, $averageMonthlyDeficit) + ($cumulativeDeficit / 12), 2);
        $budgetProfile = $this->budgetProfile($companyId);
        $warnings = $this->warnings($outflows, $outflow);

        return [
            'generatedAt' => now()->toIso8601String(),
            'scope' => [
                'from' => $filters['from'] ?? $transactions->min('posted_date')?->toDateString(),
                'to' => $filters['to'] ?? $transactions->max('posted_date')?->toDateString(),
                'transactionCount' => $transactions->count(),
                'includedTransactionCount' => $included->count(),
            ],
            'totals' => [
                'income' => $income,
                'outflow' => $outflow,
                'netCashFlow' => $netCashFlow,
                'averageMonthlyOutflow' => $averageMonthlyOutflow,
                'averageMonthlyDeficit' => $averageMonthlyDeficit,
                'cumulativeDeficit' => $cumulativeDeficit,
                'requiredMonthlyCatchUp' => $requiredMonthlyCatchUp,
            ],
            'monthlyTrend' => $monthlyTrend,
            'topCategories' => $this->topCategories($companyId, $outflows),
            'topMerchants' => $this->topMerchants($outflows),
            'recurringSpend' => $this->recurringSpend($outflows),
            'spendingByPerson' => $this->spendingByPerson($outflows, $budgetProfile, $monthCount),
            'budgetProfile' => $this->serializeBudgetProfile($budgetProfile),
            'warnings' => $warnings,
            'recommendations' => $this->recommendations($companyId, $outflows, $averageMonthlyDeficit, $cumulativeDeficit),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function catchUpScenario(string $companyId, ?float $targetAmount, int $months, array $filters = []): array
    {
        $summary = $this->summary($companyId, $filters);
        $averageMonthlyDeficit = (float) $summary['totals']['averageMonthlyDeficit'];
        $deficitToRecover = $targetAmount ?? (float) $summary['totals']['cumulativeDeficit'];
        $requiredMonthlySwing = round(max(0, $averageMonthlyDeficit) + ($deficitToRecover / max(1, $months)), 2);

        return [
            'months' => $months,
            'targetAmount' => round((float) $deficitToRecover, 2),
            'averageMonthlyDeficit' => round($averageMonthlyDeficit, 2),
            'requiredMonthlySwing' => $requiredMonthlySwing,
            'monthlyRequired' => $requiredMonthlySwing,
            'weeklyRequired' => round($requiredMonthlySwing / 4.345, 2),
            'suggestedCuts' => $this->suggestedCuts($summary, $requiredMonthlySwing),
            'note' => 'This is cash-flow analysis for the Chase account only. Credit card payments are opaque unless card statements are imported separately.',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PersonalFinanceTransaction>
     */
    public function transactionQuery(string $companyId, array $filters = []): Builder
    {
        return PersonalFinanceTransaction::query()
            ->where('company_id', $companyId)
            ->when($filters['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('posted_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate('posted_date', '<=', $to))
            ->when($filters['category'] ?? null, function (Builder $query, string $category): Builder {
                return $query->whereRaw('LOWER(category) LIKE ?', ['%'.strtolower($category).'%']);
            })
            ->when($filters['person'] ?? null, function (Builder $query, string $person) use ($companyId): Builder {
                return $query->where('person_scope', $this->normalizePersonScope($companyId, $person));
            })
            ->when($filters['direction'] ?? null, fn (Builder $query, string $direction): Builder => $query->where('direction', $direction))
            ->when($filters['merchant'] ?? null, function (Builder $query, string $merchant): Builder {
                return $query->whereRaw('LOWER(normalized_merchant) LIKE ?', ['%'.strtolower($merchant).'%']);
            });
    }

    private function normalizePersonScope(string $companyId, string $person): string
    {
        $normalized = strtolower(trim($person));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        if (in_array($normalized, [
            PersonalFinanceTransaction::PERSON_A,
            PersonalFinanceTransaction::PERSON_B,
            PersonalFinanceTransaction::PERSON_SHARED,
            PersonalFinanceTransaction::PERSON_EXCLUDED,
            PersonalFinanceTransaction::PERSON_UNKNOWN,
        ], true)) {
            return $normalized;
        }

        $profile = $this->budgetProfile($companyId);
        $labels = [
            strtolower(str_replace([' ', '-'], '_', $profile->person_a_label)) => PersonalFinanceTransaction::PERSON_A,
            strtolower(str_replace([' ', '-'], '_', $profile->person_b_label)) => PersonalFinanceTransaction::PERSON_B,
            'shared' => PersonalFinanceTransaction::PERSON_SHARED,
            'excluded' => PersonalFinanceTransaction::PERSON_EXCLUDED,
            'unknown' => PersonalFinanceTransaction::PERSON_UNKNOWN,
            'unassigned' => PersonalFinanceTransaction::PERSON_UNKNOWN,
        ];

        return $labels[$normalized] ?? $normalized;
    }

    private function budgetProfile(string $companyId): PersonalFinanceBudgetProfile
    {
        return PersonalFinanceBudgetProfile::firstOrCreate(
            ['company_id' => $companyId],
            [
                'name' => 'Default',
                'person_a_label' => 'Person A',
                'person_b_label' => 'Person B',
                'person_a_monthly_allowance' => 0,
                'person_b_monthly_allowance' => 0,
                'category_caps' => [],
                'metadata' => [],
            ],
        );
    }

    private function isIncluded(PersonalFinanceTransaction $transaction): bool
    {
        return $transaction->person_scope !== PersonalFinanceTransaction::PERSON_EXCLUDED
            && ! in_array($transaction->category, self::EXCLUDED_CATEGORIES, true);
    }

    /**
     * @param  Collection<int, PersonalFinanceTransaction>  $transactions
     * @return array<int, array<string, mixed>>
     */
    private function monthlyTrend(Collection $transactions): array
    {
        return $transactions
            ->groupBy(fn (PersonalFinanceTransaction $transaction): string => CarbonImmutable::parse($transaction->posted_date)->format('Y-m'))
            ->map(function (Collection $monthTransactions, string $month): array {
                $income = round($monthTransactions
                    ->filter(fn (PersonalFinanceTransaction $transaction): bool => $transaction->direction === PersonalFinanceTransaction::DIRECTION_INFLOW)
                    ->sum(fn (PersonalFinanceTransaction $transaction): float => (float) $transaction->amount), 2);
                $outflow = round($monthTransactions
                    ->filter(fn (PersonalFinanceTransaction $transaction): bool => $transaction->direction === PersonalFinanceTransaction::DIRECTION_OUTFLOW)
                    ->sum(fn (PersonalFinanceTransaction $transaction): float => abs((float) $transaction->amount)), 2);

                return [
                    'month' => $month,
                    'income' => $income,
                    'outflow' => $outflow,
                    'netCashFlow' => round($income - $outflow, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PersonalFinanceTransaction>  $outflows
     * @return array<int, array<string, mixed>>
     */
    private function topCategories(string $companyId, Collection $outflows): array
    {
        return $outflows
            ->groupBy('category')
            ->map(function (Collection $transactions, string $category) use ($companyId): array {
                $amount = round($transactions->sum(fn (PersonalFinanceTransaction $transaction): float => abs((float) $transaction->amount)), 2);
                $metadata = $this->categorizationService->categoryMetadata($companyId, $category);

                return [
                    'category' => $category,
                    'amount' => $amount,
                    'count' => $transactions->count(),
                    'adjustable' => (bool) ($metadata['adjustable'] ?? ! in_array($category, ['housing', 'utilities', 'healthcare'], true)),
                ];
            })
            ->sortByDesc('amount')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PersonalFinanceTransaction>  $outflows
     * @return array<int, array<string, mixed>>
     */
    private function topMerchants(Collection $outflows): array
    {
        return $outflows
            ->groupBy(fn (PersonalFinanceTransaction $transaction): string => $transaction->normalized_merchant ?: 'Unknown')
            ->map(fn (Collection $transactions, string $merchant): array => [
                'merchant' => $merchant,
                'amount' => round($transactions->sum(fn (PersonalFinanceTransaction $transaction): float => abs((float) $transaction->amount)), 2),
                'count' => $transactions->count(),
            ])
            ->sortByDesc('amount')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PersonalFinanceTransaction>  $outflows
     * @return array<int, array<string, mixed>>
     */
    private function recurringSpend(Collection $outflows): array
    {
        return $outflows
            ->filter(fn (PersonalFinanceTransaction $transaction): bool => $transaction->recurring_key !== null)
            ->groupBy('recurring_key')
            ->map(function (Collection $transactions): ?array {
                $months = $transactions
                    ->map(fn (PersonalFinanceTransaction $transaction): string => CarbonImmutable::parse($transaction->posted_date)->format('Y-m'))
                    ->unique()
                    ->values();

                if ($transactions->count() < 3 || $months->count() < 3) {
                    return null;
                }

                $total = round($transactions->sum(fn (PersonalFinanceTransaction $transaction): float => abs((float) $transaction->amount)), 2);
                $first = $transactions->sortBy('posted_date')->first();

                return [
                    'merchant' => $first?->normalized_merchant ?? 'Unknown',
                    'category' => $first?->category ?? 'uncategorized',
                    'count' => $transactions->count(),
                    'months' => $months->all(),
                    'total' => $total,
                    'monthlyAverage' => round($total / max(1, $months->count()), 2),
                ];
            })
            ->filter()
            ->sortByDesc('monthlyAverage')
            ->take(15)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PersonalFinanceTransaction>  $outflows
     * @return array<string, array<string, mixed>>
     */
    private function spendingByPerson(Collection $outflows, PersonalFinanceBudgetProfile $profile, int $monthCount): array
    {
        $allowances = [
            PersonalFinanceTransaction::PERSON_A => (float) $profile->person_a_monthly_allowance,
            PersonalFinanceTransaction::PERSON_B => (float) $profile->person_b_monthly_allowance,
            PersonalFinanceTransaction::PERSON_SHARED => (float) ($profile->shared_monthly_cap ?? 0),
            PersonalFinanceTransaction::PERSON_UNKNOWN => 0.0,
        ];

        $result = [];
        foreach ($allowances as $person => $monthlyAllowance) {
            $amount = round($outflows
                ->where('person_scope', $person)
                ->sum(fn (PersonalFinanceTransaction $transaction): float => abs((float) $transaction->amount)), 2);
            $monthlyAverage = round($amount / max(1, $monthCount), 2);

            $result[$person] = [
                'amount' => $amount,
                'monthlyAverage' => $monthlyAverage,
                'monthlyAllowance' => $monthlyAllowance,
                'monthlyRemaining' => $monthlyAllowance > 0 ? round($monthlyAllowance - $monthlyAverage, 2) : null,
                'monthlyOverage' => $monthlyAllowance > 0 ? round(max(0, $monthlyAverage - $monthlyAllowance), 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBudgetProfile(PersonalFinanceBudgetProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'personALabel' => $profile->person_a_label,
            'personBLabel' => $profile->person_b_label,
            'personAMonthlyAllowance' => (float) $profile->person_a_monthly_allowance,
            'personBMonthlyAllowance' => (float) $profile->person_b_monthly_allowance,
            'sharedMonthlyCap' => $profile->shared_monthly_cap !== null ? (float) $profile->shared_monthly_cap : null,
            'opaqueCardPaymentCap' => $profile->opaque_card_payment_cap !== null ? (float) $profile->opaque_card_payment_cap : null,
            'catchUpTargetAmount' => $profile->catch_up_target_amount !== null ? (float) $profile->catch_up_target_amount : null,
            'categoryCaps' => $profile->category_caps ?? [],
        ];
    }

    /**
     * @param  Collection<int, PersonalFinanceTransaction>  $outflows
     * @return array<int, string>
     */
    private function warnings(Collection $outflows, float $totalOutflow): array
    {
        $warnings = [];
        $creditCardPayments = $outflows
            ->where('category', 'credit_card_payment')
            ->sum(fn (PersonalFinanceTransaction $transaction): float => abs((float) $transaction->amount));

        if ($totalOutflow > 0 && ($creditCardPayments / $totalOutflow) >= 0.25) {
            $warnings[] = 'Credit card payments are a major outflow but are opaque in checking-account statements. Import card statements later for better category detail.';
        }

        if ($outflows->where('category', 'uncategorized')->count() > 0) {
            $warnings[] = 'Some transactions are uncategorized. Add rules to improve person-level and category-level accuracy.';
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, PersonalFinanceTransaction>  $outflows
     * @return array<int, string>
     */
    private function recommendations(string $companyId, Collection $outflows, float $averageMonthlyDeficit, float $cumulativeDeficit): array
    {
        $recommendations = [];
        $topAdjustable = collect($this->topCategories($companyId, $outflows))
            ->where('adjustable', true)
            ->take(3)
            ->pluck('category')
            ->all();

        if ($averageMonthlyDeficit > 0) {
            $recommendations[] = 'Reduce monthly spending by at least $'.number_format($averageMonthlyDeficit, 2).' to stop the current account deficit.';
        }

        if ($cumulativeDeficit > 0) {
            $recommendations[] = 'Add a catch-up target to recover the $'.number_format($cumulativeDeficit, 2).' cumulative gap over a fixed number of months.';
        }

        if ($topAdjustable !== []) {
            $recommendations[] = 'Start reductions in adjustable categories first: '.implode(', ', $topAdjustable).'.';
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, array<string, mixed>>
     */
    private function suggestedCuts(array $summary, float $requiredMonthlySwing): array
    {
        $remaining = $requiredMonthlySwing;
        $suggestions = [];

        foreach ($summary['topCategories'] as $category) {
            if (! $category['adjustable'] || $remaining <= 0) {
                continue;
            }

            $monthlyCategorySpend = $category['amount'] / max(1, count($summary['monthlyTrend']));
            $cut = round(min($remaining, max(0, $monthlyCategorySpend * 0.25)), 2);
            if ($cut <= 0) {
                continue;
            }

            $suggestions[] = [
                'type' => 'category_cut',
                'label' => $category['category'],
                'monthlyReduction' => $cut,
                'reason' => 'Adjustable spending category with high total spend.',
            ];
            $remaining -= $cut;
        }

        foreach ($summary['recurringSpend'] as $recurring) {
            if ($remaining <= 0) {
                break;
            }

            $cut = round(min($remaining, $recurring['monthlyAverage']), 2);
            $suggestions[] = [
                'type' => 'recurring_review',
                'label' => $recurring['merchant'],
                'monthlyReduction' => $cut,
                'reason' => 'Recurring payment or subscription candidate.',
            ];
            $remaining -= $cut;
        }

        if ($remaining > 0) {
            $suggestions[] = [
                'type' => 'unallocated_gap',
                'label' => 'Remaining monthly swing needed',
                'monthlyReduction' => round($remaining, 2),
                'reason' => 'The current adjustable categories do not fully cover the target.',
            ];
        }

        return $suggestions;
    }
}
