<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ControlsService
{
    private const DEFAULT_CONTROLS = [
        [
            'control_key' => 'approval_threshold',
            'display_name' => 'Approval Threshold Monitoring',
            'description' => 'Flags large transactions that should have explicit approval evidence.',
            'category' => 'approval',
            'config' => ['threshold' => 1000],
        ],
        [
            'control_key' => 'documentation_completeness',
            'display_name' => 'Documentation Completeness',
            'description' => 'Flags transactions missing memo, invoice reference, or category context.',
            'category' => 'documentation',
            'config' => ['minimum_amount' => 75],
        ],
        [
            'control_key' => 'duplicate_invoice',
            'display_name' => 'Duplicate Invoice Detection',
            'description' => 'Flags repeated invoice references for the same vendor and amount.',
            'category' => 'financial',
            'config' => [],
        ],
        [
            'control_key' => 'uncategorized_expenses',
            'display_name' => 'Uncategorized Expense Review',
            'description' => 'Flags expenses without a category assignment.',
            'category' => 'financial',
            'config' => ['minimum_amount' => 25],
        ],
        [
            'control_key' => 'ar_collection_followup',
            'display_name' => 'AR Collection Follow-Up',
            'description' => 'Flags overdue receivables that need collection attention.',
            'category' => 'financial',
            'config' => ['overdue_days' => 60],
        ],
    ];

    public function controls(string $companyId): array
    {
        $this->ensureDefaultControls($companyId);

        return [
            'controls' => DB::table('control_definitions')
                ->where('company_id', $companyId)
                ->orderBy('category')
                ->orderBy('display_name')
                ->get()
                ->map(fn ($row) => [
                    'id' => (string)$row->id,
                    'control_key' => (string)$row->control_key,
                    'display_name' => (string)$row->display_name,
                    'description' => $row->description,
                    'category' => (string)$row->category,
                    'enabled' => (bool)$row->enabled,
                    'config' => is_string($row->config) ? json_decode($row->config, true) : $row->config,
                ])
                ->all(),
        ];
    }

    public function health(string $companyId): array
    {
        $this->ensureDefaultControls($companyId);

        $rows = DB::select(
            "SELECT
                cd.id,
                cd.control_key,
                cd.display_name,
                cd.category,
                cd.enabled,
                COALESCE(latest.score, CASE WHEN cd.enabled THEN 100 ELSE 0 END)::int AS score,
                COALESCE(latest.status, CASE WHEN cd.enabled THEN 'passing' ELSE 'not_evaluated' END) AS status,
                COALESCE(v.violation_count, 0)::int AS violation_count
             FROM control_definitions cd
             LEFT JOIN LATERAL (
                SELECT score, status
                FROM control_evaluations ce
                WHERE ce.company_id = cd.company_id AND ce.control_id = cd.id
                ORDER BY evaluated_at DESC
                LIMIT 1
             ) latest ON TRUE
             LEFT JOIN LATERAL (
                SELECT COUNT(*)::int AS violation_count
                FROM control_violations cv
                WHERE cv.company_id = cd.company_id
                  AND cv.control_id = cd.id
                  AND cv.resolved = FALSE
             ) v ON TRUE
             WHERE cd.company_id = ?
             ORDER BY cd.category, cd.display_name",
            [$companyId]
        );

        $breakdown = array_map(fn ($row) => [
            'controlKey' => (string)$row->control_key,
            'displayName' => (string)$row->display_name,
            'category' => (string)$row->category,
            'score' => (int)$row->score,
            'status' => (string)$row->status,
            'violationCount' => (int)$row->violation_count,
            'enabled' => (bool)$row->enabled,
        ], $rows);

        $enabled = array_values(array_filter($breakdown, fn ($row) => $row['enabled']));
        $overallScore = count($enabled) > 0
            ? (int)round(array_sum(array_column($enabled, 'score')) / count($enabled))
            : 0;

        $violationStats = DB::selectOne(
            "SELECT
                COUNT(*)::int AS total_violations,
                COUNT(*) FILTER (WHERE resolved = FALSE)::int AS unresolved_violations
             FROM control_violations
             WHERE company_id = ?",
            [$companyId]
        );

        return [
            'overallScore' => $overallScore,
            'letterGrade' => $this->letterGrade($overallScore),
            'controlBreakdown' => $breakdown,
            'summary' => [
                'totalControls' => count($breakdown),
                'enabledControls' => count($enabled),
                'passingCount' => count(array_filter($enabled, fn ($row) => $row['status'] === 'passing')),
                'warningCount' => count(array_filter($enabled, fn ($row) => $row['status'] === 'warning')),
                'failingCount' => count(array_filter($enabled, fn ($row) => $row['status'] === 'failing')),
                'totalViolations' => (int)($violationStats->total_violations ?? 0),
                'unresolvedViolations' => (int)($violationStats->unresolved_violations ?? 0),
            ],
        ];
    }

    public function violations(string $companyId, array $filters): array
    {
        $limit = min((int)($filters['limit'] ?? 100), 500);
        $resolved = $filters['resolved'] ?? null;

        $query = DB::table('control_violations as cv')
            ->join('control_definitions as cd', 'cd.id', '=', 'cv.control_id')
            ->where('cv.company_id', $companyId)
            ->select([
                'cv.id',
                'cv.control_id',
                'cv.violation_type',
                'cv.description',
                'cv.severity',
                'cv.resolved',
                'cv.resolved_by',
                'cv.resolved_at',
                'cv.created_at',
                'cd.control_key',
                'cd.display_name',
            ])
            ->orderByRaw("CASE cv.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('cv.created_at')
            ->limit($limit);

        if ($resolved !== null && $resolved !== 'all') {
            $query->where('cv.resolved', filter_var($resolved, FILTER_VALIDATE_BOOLEAN));
        }

        return ['violations' => $query->get()->map(fn ($row) => (array)$row)->all()];
    }

    public function evaluate(string $companyId): array
    {
        $this->ensureDefaultControls($companyId);

        DB::transaction(function () use ($companyId): void {
            DB::table('control_violations')->where('company_id', $companyId)->where('resolved', false)->delete();

            $controls = DB::table('control_definitions')->where('company_id', $companyId)->get()->keyBy('control_key');

            foreach ($controls as $control) {
                if (!$control->enabled) {
                    $this->recordEvaluation($companyId, (string)$control->id, 'not_evaluated', 0, []);
                    continue;
                }

                $violations = $this->evaluateControl($companyId, (string)$control->control_key, (string)$control->id);
                $score = max(0, 100 - (count($violations) * 15));
                $status = count($violations) === 0 ? 'passing' : ($score >= 50 ? 'warning' : 'failing');

                $this->recordEvaluation($companyId, (string)$control->id, $status, $score, [
                    'violation_count' => count($violations),
                ]);
            }
        });

        return $this->health($companyId);
    }

    public function updateControl(string $companyId, string $controlId, array $data): ?array
    {
        $allowed = array_intersect_key($data, array_flip(['enabled', 'config']));
        if ($allowed === []) {
            return null;
        }

        $allowed['updated_at'] = now();

        $updated = DB::table('control_definitions')
            ->where('company_id', $companyId)
            ->where('id', $controlId)
            ->update($allowed);

        if (!$updated) {
            return null;
        }

        return DB::table('control_definitions')
            ->where('company_id', $companyId)
            ->where('id', $controlId)
            ->first()
            ? (array)DB::table('control_definitions')->where('company_id', $companyId)->where('id', $controlId)->first()
            : null;
    }

    public function updateViolation(string $companyId, string $violationId, string $userId, array $data): ?array
    {
        $resolved = (bool)($data['resolved'] ?? false);

        $updated = DB::table('control_violations')
            ->where('company_id', $companyId)
            ->where('id', $violationId)
            ->update([
                'resolved' => $resolved,
                'resolved_by' => $resolved ? $userId : null,
                'resolved_at' => $resolved ? now() : null,
            ]);

        if (!$updated) {
            return null;
        }

        $row = DB::table('control_violations')->where('company_id', $companyId)->where('id', $violationId)->first();
        return $row ? (array)$row : null;
    }

    private function ensureDefaultControls(string $companyId): void
    {
        foreach (self::DEFAULT_CONTROLS as $control) {
            DB::table('control_definitions')->updateOrInsert(
                ['company_id' => $companyId, 'control_key' => $control['control_key']],
                [
                    'display_name' => $control['display_name'],
                    'description' => $control['description'],
                    'category' => $control['category'],
                    'config' => json_encode($control['config']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function evaluateControl(string $companyId, string $controlKey, string $controlId): array
    {
        return match ($controlKey) {
            'approval_threshold' => $this->approvalThreshold($companyId, $controlId),
            'documentation_completeness' => $this->documentationCompleteness($companyId, $controlId),
            'duplicate_invoice' => $this->duplicateInvoices($companyId, $controlId),
            'uncategorized_expenses' => $this->uncategorizedExpenses($companyId, $controlId),
            'ar_collection_followup' => $this->arFollowup($companyId, $controlId),
            default => [],
        };
    }

    private function approvalThreshold(string $companyId, string $controlId): array
    {
        $rows = DB::select(
            "SELECT id, vendor_customer, amount
             FROM all_transactions
             WHERE company_id = ? AND ABS(amount) >= 1000
             ORDER BY ABS(amount) DESC
             LIMIT 25",
            [$companyId]
        );

        return $this->insertTransactionViolations($companyId, $controlId, $rows, 'approval_threshold', 'warning', fn ($row) =>
            sprintf('Large transaction of $%s for %s should have approval evidence.', number_format(abs((float)$row->amount), 2), $row->vendor_customer ?: 'unknown counterparty')
        );
    }

    private function documentationCompleteness(string $companyId, string $controlId): array
    {
        $rows = DB::select(
            "SELECT id, vendor_customer, amount
             FROM all_transactions
             WHERE company_id = ?
               AND ABS(amount) >= 75
               AND (memo IS NULL OR BTRIM(memo) = '')
               AND (invoice_ref IS NULL OR BTRIM(invoice_ref) = '')
             ORDER BY ABS(amount) DESC
             LIMIT 25",
            [$companyId]
        );

        return $this->insertTransactionViolations($companyId, $controlId, $rows, 'missing_documentation', 'warning', fn ($row) =>
            sprintf('Transaction for %s lacks memo or invoice reference documentation.', $row->vendor_customer ?: 'unknown counterparty')
        );
    }

    private function duplicateInvoices(string $companyId, string $controlId): array
    {
        $rows = DB::select(
            "SELECT MIN(id::text) AS id, vendor_customer, invoice_ref, amount, COUNT(*)::int AS duplicate_count
             FROM all_transactions
             WHERE company_id = ?
               AND invoice_ref IS NOT NULL
               AND BTRIM(invoice_ref) != ''
             GROUP BY vendor_customer, invoice_ref, amount
             HAVING COUNT(*) > 1
             LIMIT 25",
            [$companyId]
        );

        return $this->insertTransactionViolations($companyId, $controlId, $rows, 'duplicate_invoice', 'critical', fn ($row) =>
            sprintf('Potential duplicate invoice %s for %s appears %d times.', $row->invoice_ref, $row->vendor_customer ?: 'unknown counterparty', (int)$row->duplicate_count)
        );
    }

    private function uncategorizedExpenses(string $companyId, string $controlId): array
    {
        $rows = DB::select(
            "SELECT id, vendor_customer, amount
             FROM all_transactions
             WHERE company_id = ?
               AND LOWER(COALESCE(type, '')) = 'expense'
               AND ABS(amount) >= 25
               AND (category IS NULL OR BTRIM(category) = '')
             ORDER BY ABS(amount) DESC
             LIMIT 25",
            [$companyId]
        );

        return $this->insertTransactionViolations($companyId, $controlId, $rows, 'uncategorized_expense', 'info', fn ($row) =>
            sprintf('Expense for %s is uncategorized.', $row->vendor_customer ?: 'unknown counterparty')
        );
    }

    private function arFollowup(string $companyId, string $controlId): array
    {
        $rows = DB::select(
            "SELECT id, customer_name, balance_due, due_date
             FROM invoices
             WHERE company_id = ?
               AND status IN ('open', 'partial')
               AND balance_due > 0
               AND due_date < CURRENT_DATE - INTERVAL '60 days'
             ORDER BY due_date ASC
             LIMIT 25",
            [$companyId]
        );

        $created = [];
        foreach ($rows as $row) {
            DB::table('control_violations')->insert([
                'company_id' => $companyId,
                'control_id' => $controlId,
                'violation_type' => 'ar_collection_followup',
                'description' => sprintf('Invoice for %s is overdue with $%s outstanding.', $row->customer_name, number_format((float)$row->balance_due, 2)),
                'severity' => 'warning',
                'resolved' => false,
                'created_at' => now(),
            ]);
            $created[] = $row;
        }

        return $created;
    }

    private function insertTransactionViolations(string $companyId, string $controlId, array $rows, string $type, string $severity, callable $description): array
    {
        foreach ($rows as $row) {
            DB::table('control_violations')->insert([
                'company_id' => $companyId,
                'control_id' => $controlId,
                'violation_type' => $type,
                'description' => $description($row),
                'severity' => $severity,
                'resolved' => false,
                'created_at' => now(),
            ]);
        }

        return $rows;
    }

    private function recordEvaluation(string $companyId, string $controlId, string $status, int $score, array $evidence): void
    {
        DB::table('control_evaluations')->insert([
            'company_id' => $companyId,
            'control_id' => $controlId,
            'status' => $status,
            'score' => $score,
            'evidence' => json_encode($evidence),
            'evaluated_at' => now(),
        ]);
    }

    private function letterGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }
}
