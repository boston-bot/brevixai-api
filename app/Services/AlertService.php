<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertGroup;
use Illuminate\Database\Eloquent\Builder;
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
            $this->updateAllScores($companyId, $businessProfileId);
            $this->groupRelatedAlerts($companyId, $businessProfileId);
        }

        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = $this->alertQuery($companyId, $businessProfileId);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['rule_key'])) {
            $query->where('rule_key', $filters['rule_key']);
        }

        if (($filters['sort'] ?? 'priority') === 'priority') {
            $query->orderBy('priority_score', 'desc')->orderBy('created_at', 'desc');
        } else {
            $query->orderByRaw(
                "CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END"
            )->orderBy('created_at', 'desc');
        }

        $alerts = $query->offset($offset)->limit($limit)->get();

        $countRows = $this->alertQuery($companyId, $businessProfileId)
            ->select('status', DB::raw('COUNT(*) AS count'))
            ->groupBy('status')
            ->get();

        $counts = [];
        $total = 0;
        foreach ($countRows as $row) {
            $counts[$row->status] = (int) $row->count;
            $total += (int) $row->count;
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
    public function detail(string $companyId, string $alertId, ?string $businessProfileId = null): ?array
    {
        $alert = $this->alertQuery($companyId, $businessProfileId)
            ->where('id', $alertId)
            ->first();

        if (! $alert) {
            return null;
        }

        $transactions = [];
        $evidence = is_array($alert->evidence) ? $alert->evidence : [];
        $transactionIds = array_values(array_filter($evidence['transactionIds'] ?? [], 'is_string'));
        if ($transactionIds !== [] && Schema::hasTable('all_transactions')) {
            $transactionQuery = DB::table('all_transactions')
                ->where('company_id', $companyId)
                ->whereIn('id', $transactionIds);

            if ($businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id')) {
                $transactionQuery->where('business_profile_id', $businessProfileId);
            }

            $transactions = $transactionQuery->get();
        }

        return [
            'alert' => $alert,
            'transactions' => $transactions,
        ];
    }

    /**
     * Update an alert's status.
     */
    public function updateStatus(string $companyId, string $userId, string $alertId, string $status, ?string $businessProfileId = null): ?Alert
    {
        $alert = $this->alertQuery($companyId, $businessProfileId)
            ->where('id', $alertId)
            ->first();

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
    public function getGroups(string $companyId, ?string $businessProfileId = null): array
    {
        $groups = AlertGroup::where('company_id', $companyId)
            ->when($businessProfileId && Schema::hasColumn('alert_groups', 'business_profile_id'), fn ($query) => $query->where('business_profile_id', $businessProfileId))
            ->orderBy('total_impact', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return ['groups' => $groups];
    }

    // -------------------------------------------------------------------------
    // Prioritization & Grouping Logic
    // -------------------------------------------------------------------------

    private function calculateAlertPriority(string $alertId, string $companyId, ?string $businessProfileId = null): int
    {
        $alert = $this->alertQuery($companyId, $businessProfileId)
            ->where('id', $alertId)
            ->first();
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
            $vendorSet = array_flip(array_map('strval', $vendors));
            $hasRepeatVendor = $this->alertQuery($companyId, $businessProfileId)
                ->where('rule_key', $alert->rule_key)
                ->where('id', '!=', $alertId)
                ->where('created_at', '>', now()->subDays(90))
                ->get(['evidence'])
                ->contains(function (Alert $existingAlert) use ($vendorSet): bool {
                    $existingEvidence = is_array($existingAlert->evidence) ? $existingAlert->evidence : [];

                    foreach ($existingEvidence['vendors'] ?? [] as $vendor) {
                        if (isset($vendorSet[(string) $vendor])) {
                            return true;
                        }
                    }

                    return false;
                });

            if ($hasRepeatVendor) {
                $score += 10;
            }
        }

        return (int) round($score);
    }

    private function updateAllScores(string $companyId, ?string $businessProfileId = null): void
    {
        $openAlerts = $this->alertQuery($companyId, $businessProfileId)
            ->where('status', 'open')
            ->select('id')
            ->get();

        foreach ($openAlerts as $alert) {
            $priority = $this->calculateAlertPriority($alert->id, $companyId, $businessProfileId);
            $this->alertQuery($companyId, $businessProfileId)
                ->where('id', $alert->id)
                ->update(['priority_score' => $priority]);
        }
    }

    private function groupRelatedAlerts(string $companyId, ?string $businessProfileId = null): void
    {
        $alerts = $this->alertQuery($companyId, $businessProfileId)
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

            $groupAlerts = $this->alertQuery($companyId, $businessProfileId)
                ->whereIn('id', $alertIds)
                ->get(['id', 'severity', 'evidence']);

            $totalImpact = 0;
            $hasCritical = false;
            $hasWarning = false;

            foreach ($groupAlerts as $ga) {
                $evidence = is_array($ga->evidence) ? $ga->evidence : [];
                $totalImpact += array_sum(array_map('floatval', $evidence['amounts'] ?? []));
                if ($ga->severity === 'critical') {
                    $hasCritical = true;
                }
                if ($ga->severity === 'warning') {
                    $hasWarning = true;
                }
            }

            $maxSeverity = $hasCritical ? 'critical' : ($hasWarning ? 'warning' : 'info');
            $title = 'Security Cluster: '.count($alertIds)." anomalies for {$vendor}";

            $groupPayload = [
                'company_id' => $companyId,
                'title' => $title,
                'alert_count' => count($alertIds),
                'max_severity' => $maxSeverity,
                'total_impact' => $totalImpact,
            ];

            if ($businessProfileId && Schema::hasColumn('alert_groups', 'business_profile_id')) {
                $groupPayload['business_profile_id'] = $businessProfileId;
            }

            $group = AlertGroup::create($groupPayload);

            $this->alertQuery($companyId, $businessProfileId)
                ->whereIn('id', $alertIds)
                ->update(['group_id' => $group->id]);
        }
    }

    private function alertQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return Alert::where('company_id', $companyId)
            ->when($businessProfileId && Schema::hasColumn('alerts', 'business_profile_id'), fn ($query) => $query->where('business_profile_id', $businessProfileId));
    }
}
