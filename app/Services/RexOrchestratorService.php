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
            'alert_recommendations',
            'case_recommendations',
            'controls',
            'transactions',
            'transaction_lookup',
            'dashboard_health',
            'controls_review',
            'reconciliation_review',
            'entity_graph_review',
            'case_management',
            'reporting',
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
            'alert_recommendations' => $this->alertRecommendations($companyId),
            'case_recommendations' => $this->caseRecommendations($companyId),
            'controls' => $this->controls($companyId),
            'transactions' => $this->transactions($companyId),
            'transaction_lookup' => $this->transactions($companyId),
            'dashboard_health' => $this->dashboardHealth($companyId),
            'controls_review' => $this->controls($companyId),
            'reconciliation_review' => $this->reconciliation($companyId),
            'entity_graph_review' => $this->entityGraph($companyId),
            'case_management' => $this->cases($companyId),
            'reporting' => $this->reporting($companyId),
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

    private function alertRecommendations(string $companyId): array
    {
        $data = app(\App\Services\Agents\AlertRecommendationService::class)->getAlertRecommendations($companyId);

        return $this->result(
            'alert_recommendations',
            'alert_recommendation_list',
            'Alert Recommendations',
            $data,
            sprintf('I found %d alert recommendations pending review.', count($data['recommended_alerts'] ?? []))
        );
    }

    private function caseRecommendations(string $companyId): array
    {
        $data = app(\App\Services\Agents\CaseRecommendationService::class)->getCaseRecommendations($companyId);

        return $this->result(
            'case_recommendations',
            'case_recommendation_list',
            'Case Recommendations',
            $data,
            sprintf('I found %d case recommendations pending review.', count($data['case_recommendations'] ?? []))
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

    private function dashboardHealth(string $companyId): array
    {
        $dashboard = app(DashboardService::class)->summary($companyId);
        $alertData = app(AlertService::class)->list($companyId, ['status' => 'open', 'limit' => 10], true);
        $controlsData = app(ControlsService::class)->health($companyId);

        $data = [
            'risk_score' => $dashboard['riskScore'] ?? 0,
            'open_alerts' => count($alertData['alerts'] ?? []),
            'controls_grade' => $controlsData['letterGrade'] ?? 'N/A',
            'unresolved_violations' => $controlsData['summary']['unresolvedViolations'] ?? 0,
            'total_transactions' => $dashboard['stats']['totalTransactions'] ?? 0,
            'flagged_alerts' => $dashboard['stats']['flaggedAlerts'] ?? 0,
        ];

        return $this->result(
            'dashboard_health',
            'dashboard_health_snapshot',
            'Dashboard Health Snapshot',
            $data,
            sprintf(
                'Dashboard health: risk score %d/100, %d open alerts, controls grade %s, %d unresolved violations.',
                (int) $data['risk_score'],
                (int) $data['open_alerts'],
                $data['controls_grade'],
                (int) $data['unresolved_violations']
            )
        );
    }

    private function entityGraph(string $companyId): array
    {
        $data = app(EntityGraphService::class)->getGraph($companyId);

        return $this->result(
            'entity_graph_review',
            'entity_graph_summary',
            'Entity Relationship Graph',
            $data,
            sprintf(
                'I checked the entity graph. It includes %d entities, %d relationships, and %d relationship patterns.',
                (int) ($data['summary']['totalNodes'] ?? 0),
                (int) ($data['summary']['totalEdges'] ?? 0),
                (int) ($data['summary']['totalPatterns'] ?? 0)
            )
        );
    }

    private function reporting(string $companyId): array
    {
        $data = [
            'status' => 'preview',
            'available_workflows' => [
                'Generate an investigation report from a selected case.',
                'Generate a package manifest for investigation export materials.',
            ],
            'required_context' => ['investigation_case_id'],
        ];

        return $this->result(
            'reporting',
            'reporting_readiness',
            'Investigation Reporting',
            $data,
            'Investigation reporting is available from a selected case. Choose an investigation before generating report or package materials.'
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
            str_contains($text, 'dashboard health') || str_contains($text, 'health snapshot') => 'dashboard_health',
            str_contains($text, 'entity graph') || str_contains($text, 'relationship graph') => 'entity_graph_review',
            str_contains($text, 'reporting') || str_contains($text, 'investigation report') || str_contains($text, 'package manifest') => 'reporting',
            str_contains($text, 'financial health') || str_contains($text, 'overview') || str_contains($text, 'dashboard') || str_contains($text, 'risk score') => 'dashboard',
            str_contains($text, 'spend summary') || str_contains($text, 'cash flow') || str_contains($text, 'analytics') => 'analytics',
            str_contains($text, 'alert recommendation') || str_contains($text, 'recommended alert') || str_contains($text, 'recommended alerts') => 'alert_recommendations',
            str_contains($text, 'fraud alert') || str_contains($text, 'open alert') || str_contains($text, 'alerts') => 'alerts',
            str_contains($text, 'suspicious') || str_contains($text, 'flagged transaction') => 'suspicious',
            str_contains($text, 'reconciliation') || str_contains($text, 'unmatched') => 'reconciliation_review',
            str_contains($text, 'overdue') || str_contains($text, 'write-off') || str_contains($text, 'invoice') || str_contains($text, 'collection') => 'ar',
            str_contains($text, 'case recommendation') || str_contains($text, 'recommended case') || str_contains($text, 'recommended cases') => 'case_recommendations',
            str_contains($text, 'vendor') => 'vendors',
            str_contains($text, 'case') || str_contains($text, 'investigation') => 'case_management',
            str_contains($text, 'control') => 'controls_review',
            str_contains($text, 'look up transaction') || str_contains($text, 'find transaction') || str_contains($text, 'lookup transaction') => 'transaction_lookup',
            str_contains($text, 'transaction') => 'transactions',
            default => null,
        };
    }
}
