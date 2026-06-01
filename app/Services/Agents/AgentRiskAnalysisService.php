<?php

namespace App\Services\Agents;

use App\Models\Alert;
use App\Models\ReconciliationDiscrepancy;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class AgentRiskAnalysisService
{
    public function riskSummary(string $companyId, ?string $period = null, ?string $businessProfileId = null): array
    {
        $alertQuery = $this->alertQuery($companyId, $businessProfileId)
            ->where('status', 'open');

        if (Schema::hasColumn('alerts', 'priority_score')) {
            $alertQuery->orderBy('priority_score', 'desc');
        }

        $alerts = $alertQuery
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $criticalCount = $alerts->where('severity', 'critical')->count();
        $warningCount = $alerts->where('severity', 'warning')->count();
        $infoCount = $alerts->where('severity', 'info')->count();
        $riskScore = min(100, ($criticalCount * 20) + ($warningCount * 10) + ($infoCount * 4));

        $aggregateService = app(AggregateRiskSummaryService::class);
        $aggregateResult = $aggregateService->getAggregateRiskSummary($companyId, $businessProfileId);

        $alertRecommendationService = app(AlertRecommendationService::class);
        $alertRecommendations = $alertRecommendationService->getAlertRecommendations($companyId, $businessProfileId);

        return [
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'risk_score' => $riskScore,
            'risk_level' => $this->riskLevel($riskScore),
            'period' => $period ?: now()->format('Y-m'),
            'top_drivers' => $alerts->map(fn (Alert $alert): array => $this->alertDriver($alert))->values()->all(),
            'stats' => [
                'totalTransactions' => $this->transactionQuery($companyId, $businessProfileId)->count(),
                'flaggedAlerts' => $alerts->count(),
                'reconciliationMismatches' => $this->reconciliationDiscrepancyQuery($companyId, $businessProfileId)
                    ->whereNotIn('status', ['resolved', 'ignored'])
                    ->count(),
            ],
            'alert_breakdown' => $alerts->countBy('rule_key')->all(),
            'aggregate_summary' => $aggregateResult,
            'alert_recommendations' => $alertRecommendations,
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

    private function alertQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return Alert::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('alerts', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }

    private function transactionQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return Transaction::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('transactions', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }

    private function reconciliationDiscrepancyQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return ReconciliationDiscrepancy::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('reconciliation_discrepancies', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }
}
