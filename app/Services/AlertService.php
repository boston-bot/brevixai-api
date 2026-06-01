<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AlertService
{
    private const DEFAULT_WEIGHTS = [
        'severity' => ['critical' => 40, 'warning' => 20, 'info' => 5],
        'recencyDecayDays' => 30,
        'impactScale' => 5,
    ];

    /**
     * List alerts with optional filters.
     */
    public function list(string $companyId, array $filters = [], bool $skipCompute = false, ?string $businessProfileId = null): array
    {
        if (! $skipCompute) {
            $this->updateAllScores($companyId);
            $this->groupRelatedAlerts($companyId);
        }

        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = Alert::where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('alerts', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['rule_key'])) {
            $query->where('rule_key', $filters['rule_key']);
        }

        if (($filters['sort'] ?? 'priority') === 'priority' && Schema::hasColumn('alerts', 'priority_score')) {
            $query->orderBy('priority_score', 'desc')->orderBy('created_at', 'desc');
        } else {
            $query->orderByRaw(
                "CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END"
            )->orderBy('created_at', 'desc');
        }

        $alerts = $query->offset($offset)->limit($limit)->get();

        $countRows = DB::table('alerts')
            ->where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('alerts', 'business_profile_id'),
                fn ($query) => $query->where('business_profile_id', $businessProfileId),
            )
            ->select('status', DB::raw('COUNT(*) AS count'))
            ->groupBy('status')
            ->get();

        $counts = [];
        $total = 0;
        foreach ($countRows as $row) {
            $counts[$row->status] = $row->count;
            $total += $row->count;
        }

        return [
            'alerts' => $alerts,
            'counts' => $counts,
            'total' => $total,
        ];
    }

    /**
     * Get a single alert and its associated transactions.
     */
    public function detail(string $companyId, string $alertId): ?array
    {
        $alert = Alert::where('id', $alertId)->where('company_id', $companyId)->first();

        if (! $alert) {
            return null;
        }

        $transactions = [];
        $evidence = is_array($alert->evidence) ? $alert->evidence : [];
        if (! empty($evidence['transactionIds'])) {
            $transactions = DB::select(
                'SELECT * FROM all_transactions WHERE company_id = ? AND id = ANY(?::uuid[])',
                [$companyId, '{'.implode(',', $evidence['transactionIds']).'}']
            );
        }

        return [
            'alert' => $alert,
            'transactions' => $transactions,
        ];
    }

    /**
     * Update an alert's status.
     */
    public function updateStatus(string $companyId, string $userId, string $alertId, string $status): ?Alert
    {
        $alert = Alert::where('id', $alertId)->where('company_id', $companyId)->first();

        if (! $alert) {
            return null;
        }

        $alert->update([
            'status' => $status,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);

        return $alert;
    }

    /**
     * Get grouped alerts.
     */
    public function getGroups(string $companyId): array
    {
        $groups = AlertGroup::where('company_id', $companyId)
            ->orderBy('total_impact', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return ['groups' => $groups];
    }

    // -------------------------------------------------------------------------
    // Prioritization & Grouping Logic
    // -------------------------------------------------------------------------

    private function calculateAlertPriority(string $alertId, string $companyId): int
    {
        $alert = Alert::where('id', $alertId)->where('company_id', $companyId)->first();
        if (! $alert) {
            return 50;
        }

        // 1. Base Severity Score
        $score = self::DEFAULT_WEIGHTS['severity'][$alert->severity] ?? 10;

        // 2. Financial Impact Score
        $evidence = is_array($alert->evidence) ? $alert->evidence : [];
        $amounts = $evidence['amounts'] ?? [];
        $totalImpact = array_sum($amounts);
        $score += min(40, ($totalImpact / 1000) * self::DEFAULT_WEIGHTS['impactScale']);

        // 3. Recency Boost
        $createdDate = $alert->created_at->timestamp;
        $now = now()->timestamp;
        $ageInDays = ($now - $createdDate) / (60 * 60 * 24);
        $recencyBoost = max(0, 10 * (1 - $ageInDays / self::DEFAULT_WEIGHTS['recencyDecayDays']));
        $score += $recencyBoost;

        // 4. Pattern Repeat Boost
        $vendors = $evidence['vendors'] ?? [];
        if (! empty($vendors)) {
            $repeatCount = DB::selectOne(
                "SELECT COUNT(*)::int as count FROM alerts 
                 WHERE company_id = ? AND rule_key = ? AND id != ?
                 AND evidence->'vendors' ?| array[".implode(',', array_map(fn ($v) => "'$v'", $vendors))."]
                 AND created_at > NOW() - INTERVAL '90 days'",
                [$companyId, $alert->rule_key, $alertId]
            );

            if (($repeatCount->count ?? 0) > 0) {
                $score += 10;
            }
        }

        return (int) round($score);
    }

    private function updateAllScores(string $companyId): void
    {
        $openAlerts = Alert::where('company_id', $companyId)
            ->where('status', 'open')
            ->select('id')
            ->get();

        foreach ($openAlerts as $alert) {
            $priority = $this->calculateAlertPriority($alert->id, $companyId);
            Alert::where('id', $alert->id)->update(['priority_score' => $priority]);
        }
    }

    private function groupRelatedAlerts(string $companyId): void
    {
        $alerts = Alert::where('company_id', $companyId)
            ->where('status', 'open')
            ->whereNull('group_id')
            ->get();

        if ($alerts->count() < 2) {
            return;
        }

        $vendorGroups = [];
        foreach ($alerts as $alert) {
            $evidence = is_array($alert->evidence) ? $alert->evidence : [];
            $vendors = $evidence['vendors'] ?? [];
            foreach ($vendors as $vendor) {
                if (! isset($vendorGroups[$vendor])) {
                    $vendorGroups[$vendor] = [];
                }
                $vendorGroups[$vendor][] = $alert->id;
            }
        }

        foreach ($vendorGroups as $vendor => $alertIds) {
            if (count($alertIds) < 2) {
                continue;
            }

            $groupAlerts = DB::select(
                "SELECT severity, (SELECT COALESCE(SUM(v::numeric), 0) FROM jsonb_array_elements(evidence->'amounts') v) as amount
                 FROM alerts WHERE id = ANY(?::uuid[])",
                ['{'.implode(',', $alertIds).'}']
            );

            $totalImpact = 0;
            $hasCritical = false;
            $hasWarning = false;

            foreach ($groupAlerts as $ga) {
                $totalImpact += (float) $ga->amount;
                if ($ga->severity === 'critical') {
                    $hasCritical = true;
                }
                if ($ga->severity === 'warning') {
                    $hasWarning = true;
                }
            }

            $maxSeverity = $hasCritical ? 'critical' : ($hasWarning ? 'warning' : 'info');
            $title = 'Security Cluster: '.count($alertIds)." anomalies for {$vendor}";

            $group = AlertGroup::create([
                'company_id' => $companyId,
                'title' => $title,
                'alert_count' => count($alertIds),
                'max_severity' => $maxSeverity,
                'total_impact' => $totalImpact,
            ]);

            Alert::whereIn('id', $alertIds)->update(['group_id' => $group->id]);
        }
    }
}
