<?php

namespace App\Services\Agents;

use App\Models\Alert;
use App\Models\ReconciliationDiscrepancy;
use App\Models\Transaction;

class AgentRiskAnalysisService
{
    public function riskSummary(string $companyId, ?string $period = null): array
    {
        $alerts = Alert::where('company_id', $companyId)
            ->where('status', 'open')
            ->orderBy('priority_score', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $criticalCount = $alerts->where('severity', 'critical')->count();
        $warningCount = $alerts->where('severity', 'warning')->count();
        $infoCount = $alerts->where('severity', 'info')->count();
        $riskScore = min(100, ($criticalCount * 20) + ($warningCount * 10) + ($infoCount * 4));

        return [
            'company_id' => $companyId,
            'risk_score' => $riskScore,
            'risk_level' => $this->riskLevel($riskScore),
            'period' => $period ?: now()->format('Y-m'),
            'top_drivers' => $alerts->map(fn (Alert $alert): array => $this->alertDriver($alert))->values()->all(),
            'stats' => [
                'totalTransactions' => Transaction::where('company_id', $companyId)->count(),
                'flaggedAlerts' => $alerts->count(),
                'reconciliationMismatches' => ReconciliationDiscrepancy::where('company_id', $companyId)
                    ->whereNotIn('status', ['resolved', 'ignored'])
                    ->count(),
            ],
            'alert_breakdown' => $alerts->countBy('rule_key')->all(),
        ];
    }

    private function alertDriver(Alert $alert): array
    {
        $evidence = is_array($alert->evidence) ? $alert->evidence : [];

        return [
            'driver' => $alert->title,
            'description' => $alert->detail ?? '',
            'severity' => $this->normalizedSeverity($alert->severity),
            'evidence' => $this->evidenceItems($alert->id, $evidence),
            'rule_key' => $alert->rule_key,
        ];
    }

    private function evidenceItems(string $alertId, array $evidence): array
    {
        $items = [[
            'type' => 'alert',
            'id' => $alertId,
        ]];

        foreach (($evidence['transactionIds'] ?? []) as $transactionId) {
            $items[] = [
                'type' => 'transaction',
                'id' => $transactionId,
            ];
        }

        foreach (($evidence['reconciliationDiscrepancyIds'] ?? []) as $discrepancyId) {
            $items[] = [
                'type' => 'reconciliation_discrepancy',
                'id' => $discrepancyId,
            ];
        }

        return $items;
    }

    private function riskLevel(int $riskScore): string
    {
        return match (true) {
            $riskScore >= 90 => 'critical',
            $riskScore >= 70 => 'high',
            $riskScore >= 40 => 'medium',
            $riskScore > 0 => 'low',
            default => 'low',
        };
    }

    private function normalizedSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'warning' => 'medium',
            default => 'info',
        };
    }
}
