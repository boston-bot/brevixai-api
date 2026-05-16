<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AccountingService
{
    /**
     * Estimate tax reserve using the transaction ledger currently available.
     *
     * This is an operational estimate for dashboard hygiene, not tax advice.
     */
    public function taxEstimate(string $companyId): array
    {
        $row = DB::selectOne(
            "SELECT
                CAST(COALESCE(SUM(CASE WHEN LOWER(COALESCE(type, '')) = 'revenue' THEN amount ELSE 0 END), 0) AS DOUBLE PRECISION) AS revenue,
                CAST(COALESCE(SUM(CASE WHEN LOWER(COALESCE(type, '')) = 'expense' THEN ABS(amount) ELSE 0 END), 0) AS DOUBLE PRECISION) AS expense,
                CAST(COALESCE(SUM(CASE
                    WHEN LOWER(COALESCE(type, '')) = 'expense'
                     AND LOWER(COALESCE(category, '')) LIKE ANY (ARRAY['%cost of goods%', '%cogs%', '%inventory%', '%materials%'])
                    THEN ABS(amount) ELSE 0 END), 0) AS DOUBLE PRECISION) AS cogs
             FROM all_transactions
             WHERE company_id = ?",
            [$companyId]
        );

        $revenue = round((float)($row->revenue ?? 0), 2);
        $expense = round((float)($row->expense ?? 0), 2);
        $cogs = round((float)($row->cogs ?? 0), 2);
        $opex = round(max(0, $expense - $cogs), 2);
        $netProfit = round($revenue - $expense, 2);

        $taxableBase = max(0, $netProfit);
        $estimatedTaxMin = round($taxableBase * 0.25, 2);
        $estimatedTaxMax = round($taxableBase * 0.30, 2);

        return [
            'revenue' => $revenue,
            'expense' => $expense,
            'cogs' => $cogs,
            'opex' => $opex,
            'netProfit' => $netProfit,
            'estimatedTaxMin' => $estimatedTaxMin,
            'estimatedTaxMax' => $estimatedTaxMax,
            'recommendation' => $this->recommendation($taxableBase, $estimatedTaxMin, $estimatedTaxMax),
        ];
    }

    private function recommendation(float $taxableBase, float $estimatedTaxMin, float $estimatedTaxMax): string
    {
        if ($taxableBase <= 0) {
            return 'No reserve is suggested while the current ledger shows no taxable profit.';
        }

        return sprintf(
            'Set aside $%s to $%s based on current net profit and a 25%%-30%% reserve range.',
            number_format($estimatedTaxMin, 0),
            number_format($estimatedTaxMax, 0)
        );
    }
}
