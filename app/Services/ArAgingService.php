<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ArAgingService
{
    private const BUCKETS = [
        ['label' => 'Current', 'days_min' => 0, 'days_max' => 0],
        ['label' => '1-30', 'days_min' => 1, 'days_max' => 30],
        ['label' => '31-60', 'days_min' => 31, 'days_max' => 60],
        ['label' => '61-90', 'days_min' => 61, 'days_max' => 90],
        ['label' => '91-120', 'days_min' => 91, 'days_max' => 120],
        ['label' => '120+', 'days_min' => 121, 'days_max' => null],
    ];

    public function summary(string $companyId): array
    {
        $buckets = [];
        foreach (self::BUCKETS as $bucket) {
            $row = DB::selectOne(
                "SELECT
                    COUNT(*)::int AS invoice_count,
                    CAST(COALESCE(SUM(balance_due), 0) AS DOUBLE PRECISION) AS total_balance
                 FROM invoices
                 WHERE company_id = ?
                   AND status IN ('open', 'partial')
                   AND balance_due > 0
                   AND {$this->bucketCondition($bucket['days_min'], $bucket['days_max'])}",
                [$companyId]
            );

            $buckets[] = [
                'label' => $bucket['label'],
                'days_min' => $bucket['days_min'],
                'days_max' => $bucket['days_max'],
                'invoice_count' => (int)($row->invoice_count ?? 0),
                'total_balance' => (float)($row->total_balance ?? 0),
            ];
        }

        $totals = DB::selectOne(
            "SELECT
                CAST(COALESCE(SUM(balance_due), 0) AS DOUBLE PRECISION) AS total_outstanding,
                CAST(COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE THEN balance_due ELSE 0 END), 0) AS DOUBLE PRECISION) AS total_overdue,
                COUNT(*) FILTER (WHERE due_date < CURRENT_DATE - INTERVAL '90 days')::int AS write_off_candidates
             FROM invoices
             WHERE company_id = ?
               AND status IN ('open', 'partial')
               AND balance_due > 0",
            [$companyId]
        );

        return [
            'buckets' => $buckets,
            'total_outstanding' => (float)($totals->total_outstanding ?? 0),
            'total_overdue' => (float)($totals->total_overdue ?? 0),
            'write_off_candidates' => (int)($totals->write_off_candidates ?? 0),
        ];
    }

    public function customers(string $companyId): array
    {
        $rows = DB::select(
            "SELECT
                customer_name,
                COUNT(*)::int AS invoice_count,
                CAST(COALESCE(SUM(balance_due), 0) AS DOUBLE PRECISION) AS total_balance,
                CAST(COALESCE(SUM(CASE WHEN due_date >= CURRENT_DATE THEN balance_due ELSE 0 END), 0) AS DOUBLE PRECISION) AS current,
                CAST(COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE AND due_date >= CURRENT_DATE - INTERVAL '30 days' THEN balance_due ELSE 0 END), 0) AS DOUBLE PRECISION) AS days_1_30,
                CAST(COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE - INTERVAL '30 days' AND due_date >= CURRENT_DATE - INTERVAL '60 days' THEN balance_due ELSE 0 END), 0) AS DOUBLE PRECISION) AS days_31_60,
                CAST(COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE - INTERVAL '60 days' AND due_date >= CURRENT_DATE - INTERVAL '90 days' THEN balance_due ELSE 0 END), 0) AS DOUBLE PRECISION) AS days_61_90,
                CAST(COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE - INTERVAL '90 days' AND due_date >= CURRENT_DATE - INTERVAL '120 days' THEN balance_due ELSE 0 END), 0) AS DOUBLE PRECISION) AS days_91_120,
                CAST(COALESCE(SUM(CASE WHEN due_date < CURRENT_DATE - INTERVAL '120 days' THEN balance_due ELSE 0 END), 0) AS DOUBLE PRECISION) AS days_120_plus,
                MIN(due_date)::text AS oldest_due_date
             FROM invoices
             WHERE company_id = ?
               AND status IN ('open', 'partial')
               AND balance_due > 0
             GROUP BY customer_name
             ORDER BY total_balance DESC, customer_name ASC",
            [$companyId]
        );

        return array_map(fn ($row) => (array)$row, $rows);
    }

    public function invoices(string $companyId, int $limit = 100, int $offset = 0): array
    {
        $rows = DB::select(
            $this->invoiceSelectSql() .
            " WHERE company_id = ?
              ORDER BY due_date ASC, invoice_date DESC
              LIMIT ? OFFSET ?",
            [$companyId, min(max($limit, 1), 500), max($offset, 0)]
        );

        $count = DB::selectOne(
            "SELECT COUNT(*)::int AS total FROM invoices WHERE company_id = ?",
            [$companyId]
        );

        return [
            'rows' => $this->castInvoices($rows),
            'total' => (int)($count->total ?? 0),
        ];
    }

    public function writeOffCandidates(string $companyId): array
    {
        $rows = DB::select(
            $this->invoiceSelectSql() .
            " WHERE company_id = ?
                AND status IN ('open', 'partial')
                AND balance_due > 0
                AND due_date < CURRENT_DATE - INTERVAL '90 days'
              ORDER BY due_date ASC",
            [$companyId]
        );

        return $this->castInvoices($rows);
    }

    public function updateInvoice(string $companyId, string $invoiceId, array $data): ?array
    {
        $allowed = array_intersect_key($data, array_flip(['collection_notes', 'last_contact_date']));
        if ($allowed === []) {
            return $this->invoice($companyId, $invoiceId);
        }

        $allowed['updated_at'] = now();

        $updated = DB::table('invoices')
            ->where('company_id', $companyId)
            ->where('id', $invoiceId)
            ->update($allowed);

        return $updated ? $this->invoice($companyId, $invoiceId) : null;
    }

    public function writeOff(string $companyId, string $invoiceId, string $reason): ?array
    {
        $updated = DB::table('invoices')
            ->where('company_id', $companyId)
            ->where('id', $invoiceId)
            ->whereIn('status', ['open', 'partial'])
            ->update([
                'status' => 'written_off',
                'write_off_date' => now()->toDateString(),
                'write_off_reason' => $reason,
                'updated_at' => now(),
            ]);

        return $updated ? $this->invoice($companyId, $invoiceId) : null;
    }

    private function invoice(string $companyId, string $invoiceId): ?array
    {
        $row = DB::selectOne(
            $this->invoiceSelectSql() . " WHERE company_id = ? AND id = ? LIMIT 1",
            [$companyId, $invoiceId]
        );

        if (!$row) {
            return null;
        }

        return $this->castInvoices([$row])[0];
    }

    private function invoiceSelectSql(): string
    {
        return "SELECT
            id,
            customer_name,
            invoice_number,
            invoice_date::text AS invoice_date,
            due_date::text AS due_date,
            CAST(amount AS DOUBLE PRECISION) AS amount,
            CAST(paid_amount AS DOUBLE PRECISION) AS paid_amount,
            CAST(balance_due AS DOUBLE PRECISION) AS balance_due,
            status,
            collection_notes,
            GREATEST((CURRENT_DATE - due_date), 0)::int AS days_overdue,
            write_off_date::text AS write_off_date,
            write_off_reason
            FROM invoices";
    }

    private function castInvoices(array $rows): array
    {
        return array_map(fn ($row) => [
            'id' => (string)$row->id,
            'customer_name' => (string)$row->customer_name,
            'invoice_number' => $row->invoice_number,
            'invoice_date' => (string)$row->invoice_date,
            'due_date' => (string)$row->due_date,
            'amount' => (float)$row->amount,
            'paid_amount' => (float)$row->paid_amount,
            'balance_due' => (float)$row->balance_due,
            'status' => (string)$row->status,
            'collection_notes' => $row->collection_notes,
            'days_overdue' => (int)$row->days_overdue,
            'write_off_date' => $row->write_off_date,
            'write_off_reason' => $row->write_off_reason,
        ], $rows);
    }

    private function bucketCondition(int $daysMin, ?int $daysMax): string
    {
        if ($daysMin === 0 && $daysMax === 0) {
            return 'due_date >= CURRENT_DATE';
        }

        if ($daysMax === null) {
            return "due_date < CURRENT_DATE - INTERVAL '{$daysMin} days'";
        }

        $previousDay = $daysMin - 1;

        return "due_date < CURRENT_DATE - INTERVAL '{$previousDay} days'
            AND due_date >= CURRENT_DATE - INTERVAL '{$daysMax} days'";
    }
}
