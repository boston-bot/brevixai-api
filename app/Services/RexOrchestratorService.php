<?php

namespace App\Services;

class RexOrchestratorService
{
    /** @return array<int, string> */
    public function supportedRoutes(): array
    {
        return [
            'dashboard',
            'analytics',
            'alerts',
            'suspicious',
            'reconciliation',
            'ar',
            'vendors',
            'cases',
            'controls',
            'transactions',
        ];
    }

    public function handle(string $companyId, string $content): ?array
    {
        $intent = $this->inferIntent($content);
        if (! $intent) {
            return null;
        }

        return $this->handleRoute($companyId, $intent);
    }

    public function handleRoute(string $companyId, string $route): ?array
    {
        return match ($route) {
            'dashboard' => $this->dashboard($companyId),
            'analytics' => $this->analytics($companyId),
            'alerts' => $this->alerts($companyId),
            'suspicious' => $this->suspicious($companyId),
            'reconciliation' => $this->reconciliation($companyId),
            'ar' => $this->arAging($companyId),
            'vendors' => $this->vendors($companyId),
            'cases' => $this->cases($companyId),
            'controls' => $this->controls($companyId),
            'transactions' => $this->transactions($companyId),
            default => null,
        };
    }

    private function dashboard(string $companyId): array
    {
        $data = app(DashboardService::class)->summary($companyId);

        return $this->result(
            'financial_overview',
            'dashboard_summary',
            'Financial Overview',
            $data,
            sprintf(
                'I checked the dashboard data. Current risk is %d/100, with %d open alerts across %d transactions.',
                (int) ($data['riskScore'] ?? 0),
                (int) ($data['stats']['flaggedAlerts'] ?? 0),
                (int) ($data['stats']['totalTransactions'] ?? 0)
            )
        );
    }

    private function analytics(string $companyId): array
    {
        $analytics = app(AnalyticsService::class);
        $data = [
            'summary' => $analytics->getPerformanceSummary($companyId),
            'topVendors' => $analytics->getTopVendors($companyId, 5),
            'cashFlow' => $analytics->getCashFlowAnalytics($companyId),
        ];

        return $this->result(
            'analytics',
            'analytics_summary',
            'Spend Summary',
            $data,
            sprintf(
                'I pulled the spend summary. This month shows $%s in spend across %d transactions.',
                number_format((float) ($data['summary']['totalSpend']['value'] ?? 0), 2),
                (int) ($data['summary']['transactionCount']['value'] ?? 0)
            )
        );
    }

    private function alerts(string $companyId): array
    {
        $data = app(AlertService::class)->list($companyId, ['status' => 'open', 'limit' => 20], true);

        return $this->result(
            'alerts',
            'alert_list',
            'Open Alerts',
            $data,
            sprintf('I found %d open alerts.', count($data['alerts'] ?? []))
        );
    }

    private function suspicious(string $companyId): array
    {
        $transactions = app(TransactionService::class)->list($companyId, ['status' => 'flagged', 'limit' => 20]);

        $data = [
            'transactions' => $transactions['transactions'] ?? [],
            'vendorRisk' => [],
        ];

        return $this->result(
            'fraud_triage',
            'suspicious_transactions',
            'Suspicious Transactions',
            $data,
            sprintf('I checked flagged transactions and found %d suspicious rows to review.', count($data['transactions']))
        );
    }

    private function reconciliation(string $companyId): array
    {
        $service = app(ReconciliationService::class);
        $summary = $service->getSummary($companyId);
        $discrepancies = $service->getDiscrepancies($companyId, ['limit' => 10]);

        return $this->result(
            'reconciliation',
            'reconciliation_summary',
            'Latest Reconciliation Status',
            array_merge($summary, ['discrepancies' => $discrepancies['discrepancies'] ?? []]),
            sprintf(
                'I checked reconciliation. There are %d unresolved discrepancies totaling $%s.',
                (int) ($summary['unresolvedCount'] ?? 0),
                number_format((float) ($summary['unresolvedAmount'] ?? 0), 2)
            )
        );
    }

    private function arAging(string $companyId): array
    {
        $data = app(ArAgingService::class)->summary($companyId);

        return $this->result(
            'ar_collections',
            'ar_aging_summary',
            'AR Aging Summary',
            $data,
            sprintf(
                'I checked receivables. Outstanding AR is $%s, with $%s overdue and %d write-off candidates.',
                number_format((float) ($data['total_outstanding'] ?? 0), 2),
                number_format((float) ($data['total_overdue'] ?? 0), 2),
                (int) ($data['write_off_candidates'] ?? 0)
            )
        );
    }

    private function vendors(string $companyId): array
    {
        $vendors = app(AnalyticsService::class)->getTopVendors($companyId, 10);

        return $this->result(
            'vendor_analysis',
            'vendor_risk_list',
            'Top Vendors by Spend',
            ['vendors' => array_map(fn ($v) => [
                'vendor' => $v->name ?? 'Unknown',
                'total_amount' => (float) ($v->amount ?? 0),
                'flagged_count' => 0,
            ], $vendors)],
            sprintf('I ranked vendors by spend and found %d vendors in the current ledger.', count($vendors))
        );
    }

    private function controls(string $companyId): array
    {
        $data = app(ControlsService::class)->health($companyId);

        return $this->result(
            'controls',
            'controls_health',
            'Controls Health',
            $data,
            sprintf(
                'I checked controls health. Current grade is %s with %d unresolved violations.',
                $data['letterGrade'] ?? 'N/A',
                (int) ($data['summary']['unresolvedViolations'] ?? 0)
            )
        );
    }

    private function cases(string $companyId): array
    {
        $data = app(CaseService::class)->list($companyId, ['status' => 'open', 'limit' => 20]);

        return $this->result(
            'cases',
            'case_list',
            'Open Investigation Cases',
            $data,
            sprintf('I found %d open investigation cases.', count($data['cases'] ?? []))
        );
    }

    private function transactions(string $companyId): array
    {
        $data = app(TransactionService::class)->list($companyId, ['limit' => 25]);

        return $this->result(
            'transactions',
            'transaction_list',
            'Recent Transactions',
            $data,
            sprintf('I pulled %d recent transactions from the ledger.', count($data['transactions'] ?? []))
        );
    }

    private function result(string $route, string $artifactType, string $title, array $data, string $message): array
    {
        return [
            'route' => $route,
            'toolName' => $route.'.lookup',
            'message' => $message,
            'artifacts' => [[
                'id' => $artifactType.'-'.uniqid(),
                'type' => $artifactType,
                'title' => $title,
                'data' => $data,
                'sourceRefs' => [],
            ]],
        ];
    }

    public function inferIntent(string $content): ?string
    {
        $text = strtolower($content);

        return match (true) {
            str_contains($text, 'financial health') || str_contains($text, 'overview') || str_contains($text, 'dashboard') => 'dashboard',
            str_contains($text, 'spend summary') || str_contains($text, 'cash flow') || str_contains($text, 'analytics') => 'analytics',
            str_contains($text, 'fraud alert') || str_contains($text, 'open alert') || str_contains($text, 'alerts') => 'alerts',
            str_contains($text, 'suspicious') || str_contains($text, 'flagged transaction') => 'suspicious',
            str_contains($text, 'reconciliation') || str_contains($text, 'unmatched') => 'reconciliation',
            str_contains($text, 'overdue') || str_contains($text, 'write-off') || str_contains($text, 'invoice') || str_contains($text, 'collection') => 'ar',
            str_contains($text, 'vendor') => 'vendors',
            str_contains($text, 'case') || str_contains($text, 'investigation') => 'cases',
            str_contains($text, 'control') => 'controls',
            str_contains($text, 'transaction') => 'transactions',
            default => null,
        };
    }
}
