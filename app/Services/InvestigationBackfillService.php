<?php

namespace App\Services;

use App\Models\Finding;
use App\Models\Investigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class InvestigationBackfillService
{
    /** @return array<string, int> */
    public function run(
        ?string $companyId = null,
        ?string $businessProfileId = null,
        ?int $limit = null,
        bool $dryRun = false,
    ): array {
        $this->assertCanonicalTablesExist();

        $runner = function () use ($companyId, $businessProfileId, $limit, $dryRun): array {
            $result = [
                'investigations' => 0,
                'findings' => 0,
                'evidence_items' => 0,
                'review_events' => 0,
                'case_packages' => 0,
                'skipped' => 0,
            ];

            $caseMap = $this->backfillCases($result, $companyId, $businessProfileId, $limit, $dryRun);
            $this->backfillCaseRecommendations($result, $caseMap, $companyId, $businessProfileId, $limit, $dryRun);
            $this->backfillAlertRecommendations($result, $companyId, $businessProfileId, $limit, $dryRun);
            $this->backfillAlerts($result, $companyId, $businessProfileId, $limit, $dryRun);
            $this->backfillReconciliationDiscrepancies($result, $companyId, $businessProfileId, $limit, $dryRun);
            $this->backfillUploadValidationErrors($result, $companyId, $businessProfileId, $limit, $dryRun);

            return $result;
        };

        return $dryRun ? $runner() : DB::transaction($runner);
    }

    private function assertCanonicalTablesExist(): void
    {
        foreach (['investigations', 'findings', 'evidence_items', 'review_events', 'case_packages'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Canonical investigation table is missing: {$table}");
            }
        }
    }

    /**
     * @param array<string, int> $result
     * @return array<string, string> Legacy audit case id to canonical investigation id.
     */
    private function backfillCases(array &$result, ?string $companyId, ?string $businessProfileId, ?int $limit, bool $dryRun): array
    {
        if (! Schema::hasTable('audit_cases')) {
            return [];
        }

        $query = DB::table('audit_cases')->orderBy('created_at');
        $this->applyCompanyFilter($query, $companyId);
        $this->applyBusinessProfileFilter($query, 'audit_cases', $businessProfileId);
        $this->applyLimit($query, $limit);

        $caseMap = [];
        foreach ($query->get() as $case) {
            $profileId = $this->businessProfileForRow($case, (string) $case->company_id, $businessProfileId);
            $createdBy = $case->created_by ?: $this->firstUserIdForCompany((string) $case->company_id);
            if (! $createdBy) {
                $result['skipped']++;
                continue;
            }

            $result['investigations']++;
            if ($dryRun) {
                continue;
            }

            $metadata = $this->decode($case->investigation_metadata ?? null);
            $existing = DB::table('investigations')->where('legacy_audit_case_id', $case->id)->first();
            $investigationId = $existing->id ?? (string) Str::uuid();
            $assignedTo = $case->investigation_assigned_user_id ?? $case->assigned_to ?? null;

            DB::table('investigations')->updateOrInsert(
                ['legacy_audit_case_id' => $case->id],
                [
                    'id' => $investigationId,
                    'company_id' => $case->company_id,
                    'business_profile_id' => $profileId,
                    'title' => $case->title,
                    'category' => $this->normalizeCategory((string) ($metadata['category'] ?? 'unsure')),
                    'subcategory' => $metadata['subcategory'] ?? null,
                    'status' => $this->normalizeInvestigationStatus((string) ($case->investigation_status ?? $case->status ?? 'open')),
                    'priority' => $this->normalizePriority((string) ($case->investigation_priority ?? $case->severity ?? 'medium')),
                    'review_period_start' => $metadata['review_period']['startDate'] ?? $metadata['review_period']['start'] ?? null,
                    'review_period_end' => $metadata['review_period']['endDate'] ?? $metadata['review_period']['end'] ?? null,
                    'scope_statement' => $case->investigation_summary ?: $case->description,
                    'scope_limitations' => json_encode($this->arrayValue($metadata['scope_limitations'] ?? [])),
                    'assigned_to' => $this->userBelongsToCompany($assignedTo, (string) $case->company_id) ? $assignedTo : null,
                    'created_by' => $createdBy,
                    'opened_at' => $case->created_at ?? now(),
                    'closed_at' => $case->resolved_at ?? null,
                    'last_activity_at' => $case->last_activity_at ?? $case->updated_at ?? $case->created_at ?? now(),
                    'metadata' => json_encode(['legacy_source' => 'audit_cases']),
                    'created_at' => $case->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );

            $caseMap[(string) $case->id] = $investigationId;
            $this->backfillCaseEvidence($result, $case, $investigationId, $profileId, $dryRun);
            $this->backfillCaseActivity($result, $case, $investigationId, $profileId, $dryRun);
            $this->backfillCasePackages($result, $case, $investigationId, $profileId, $dryRun);
        }

        return $caseMap;
    }

    /** @param array<string, int> $result */
    private function backfillCaseEvidence(array &$result, object $case, string $investigationId, ?string $profileId, bool $dryRun): void
    {
        if (! Schema::hasTable('investigation_evidence_items')) {
            return;
        }

        $items = DB::table('investigation_evidence_items')
            ->where('audit_case_id', $case->id)
            ->orderBy('created_at')
            ->get();

        foreach ($items as $item) {
            $result['evidence_items']++;
            if ($dryRun) {
                continue;
            }

            $metadata = $this->decode($item->metadata ?? null);
            DB::table('evidence_items')->updateOrInsert(
                ['legacy_evidence_item_id' => $item->id],
                [
                    'id' => DB::table('evidence_items')->where('legacy_evidence_item_id', $item->id)->value('id') ?: (string) Str::uuid(),
                    'company_id' => $case->company_id,
                    'business_profile_id' => $profileId,
                    'investigation_id' => $investigationId,
                    'finding_id' => null,
                    'evidence_type' => $item->evidence_type,
                    'source_type' => $metadata['source_type'] ?? $item->source,
                    'source_id' => $metadata['source_id'] ?? null,
                    'source_record_id' => $item->evidence_reference_id,
                    'title' => $item->title,
                    'summary' => $item->summary,
                    'citation_label' => $metadata['citation_label'] ?? null,
                    'source_row_range' => $metadata['source_row_range'] ?? null,
                    'file_name' => $metadata['file_name'] ?? null,
                    'storage_key' => $metadata['storage_key'] ?? null,
                    'hash' => $metadata['hash'] ?? null,
                    'added_by_actor_type' => $item->added_by_actor_type,
                    'added_by_actor_id' => $item->added_by_actor_id,
                    'metadata' => json_encode(['legacy_source' => 'investigation_evidence_items']),
                    'created_at' => $item->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @param array<string, int> $result */
    private function backfillCaseActivity(array &$result, object $case, string $investigationId, ?string $profileId, bool $dryRun): void
    {
        if (! Schema::hasTable('investigation_activity_events')) {
            return;
        }

        $events = DB::table('investigation_activity_events')
            ->where('audit_case_id', $case->id)
            ->orderBy('created_at')
            ->get();

        foreach ($events as $event) {
            $result['review_events']++;
            if ($dryRun || $this->reviewEventExists($investigationId, $event)) {
                continue;
            }

            $metadata = $this->decode($event->event_metadata ?? null);
            DB::table('review_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $case->company_id,
                'business_profile_id' => $profileId,
                'investigation_id' => $investigationId,
                'finding_id' => null,
                'event_type' => $event->event_type,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'previous_status' => $metadata['previous_status'] ?? null,
                'next_status' => $metadata['next_status'] ?? null,
                'note' => $event->event_summary,
                'metadata' => json_encode(['legacy_event_id' => $event->id]),
                'created_at' => $event->created_at ?? now(),
            ]);
        }
    }

    /** @param array<string, int> $result */
    private function backfillCasePackages(array &$result, object $case, string $investigationId, ?string $profileId, bool $dryRun): void
    {
        if (! Schema::hasTable('investigation_report_exports')) {
            return;
        }

        $exports = DB::table('investigation_report_exports')
            ->where('audit_case_id', $case->id)
            ->orderBy('generated_at')
            ->get();

        foreach ($exports as $export) {
            $result['case_packages']++;
            if ($dryRun || $this->casePackageExists($investigationId, $export)) {
                continue;
            }

            $metadata = $this->decode($export->metadata ?? null);
            DB::table('case_packages')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $case->company_id,
                'business_profile_id' => $profileId,
                'investigation_id' => $investigationId,
                'format' => $export->format ?? 'json',
                'status' => 'completed',
                'title' => 'Legacy Investigation Export',
                'generated_at' => $export->generated_at ?? $export->created_at ?? now(),
                'generated_by' => $export->generated_by_user_id ?? null,
                'included_sections' => json_encode(['legacy_report_export']),
                'included_counts' => json_encode($metadata),
                'package_hash' => $export->report_hash ?? null,
                'filename' => $export->filename ?? null,
                'storage_key' => $export->storage_key ?? null,
                'manifest' => json_encode(['legacy_report_export_id' => $export->id]),
                'error_message' => null,
                'created_at' => $export->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string, int> $result @param array<string, string> $caseMap */
    private function backfillCaseRecommendations(array &$result, array $caseMap, ?string $companyId, ?string $businessProfileId, ?int $limit, bool $dryRun): void
    {
        if (! Schema::hasTable('case_recommendations')) {
            return;
        }

        $query = DB::table('case_recommendations')->orderBy('created_at');
        $this->applyCompanyFilter($query, $companyId);
        $this->applyBusinessProfileFilter($query, 'case_recommendations', $businessProfileId);
        $this->applyLimit($query, $limit);

        foreach ($query->get() as $row) {
            $profileId = $this->businessProfileForRow($row, (string) $row->company_id, $businessProfileId);
            $auditCaseId = Schema::hasTable('audit_cases')
                ? DB::table('audit_cases')->where('case_recommendation_id', $row->id)->value('id')
                : null;
            $this->upsertFinding($result, $row, [
                'business_profile_id' => $profileId,
                'investigation_id' => $auditCaseId ? ($caseMap[(string) $auditCaseId] ?? null) : null,
                'category' => $this->normalizeCategory((string) ($row->case_type ?? 'unsure')),
                'source_module' => 'case_recommendations',
                'source_record_type' => 'case_recommendation',
                'source_record_id' => $row->id,
                'title' => $row->title,
                'summary' => $row->summary ?? null,
                'detail' => null,
                'severity' => $this->normalizeSeverity((string) ($row->severity ?? 'warning')),
                'confidence' => $this->confidenceFromScore($row->confidence_score ?? null),
                'confidence_score' => $row->confidence_score ?? null,
                'reason_code' => $row->case_type ?? null,
                'status' => $this->findingStatusFromRecommendation($row->status ?? null),
                'recommended_action' => ['source' => 'case_recommendation'],
            ], $dryRun);
        }
    }

    /** @param array<string, int> $result */
    private function backfillAlertRecommendations(array &$result, ?string $companyId, ?string $businessProfileId, ?int $limit, bool $dryRun): void
    {
        if (! Schema::hasTable('alert_recommendations')) {
            return;
        }

        $query = DB::table('alert_recommendations')->orderBy('created_at');
        $this->applyCompanyFilter($query, $companyId);
        $this->applyBusinessProfileFilter($query, 'alert_recommendations', $businessProfileId);
        $this->applyLimit($query, $limit);

        foreach ($query->get() as $row) {
            $this->upsertFinding($result, $row, [
                'business_profile_id' => $this->businessProfileForRow($row, (string) $row->company_id, $businessProfileId),
                'investigation_id' => null,
                'category' => $this->normalizeCategory((string) ($row->source_risk_domain ?? 'unsure')),
                'source_module' => 'alert_recommendations',
                'source_record_type' => 'alert_recommendation',
                'source_record_id' => $row->id,
                'title' => $row->title,
                'summary' => $row->summary ?? null,
                'detail' => null,
                'severity' => $this->normalizeSeverity((string) ($row->severity ?? 'warning')),
                'confidence' => $this->confidenceFromScore($row->confidence_score ?? null),
                'confidence_score' => $row->confidence_score ?? null,
                'reason_code' => $row->alert_type ?? null,
                'status' => $this->findingStatusFromRecommendation($row->status ?? null),
                'recommended_action' => ['source' => 'alert_recommendation'],
            ], $dryRun);
        }
    }

    /** @param array<string, int> $result */
    private function backfillAlerts(array &$result, ?string $companyId, ?string $businessProfileId, ?int $limit, bool $dryRun): void
    {
        if (! Schema::hasTable('alerts')) {
            return;
        }

        $query = DB::table('alerts')->orderBy('created_at');
        $this->applyCompanyFilter($query, $companyId);
        $this->applyBusinessProfileFilter($query, 'alerts', $businessProfileId);
        $this->applyLimit($query, $limit);

        foreach ($query->get() as $row) {
            $this->upsertFinding($result, $row, [
                'business_profile_id' => $this->businessProfileForRow($row, (string) $row->company_id, $businessProfileId),
                'investigation_id' => null,
                'category' => $this->normalizeCategory((string) ($row->rule_key ?? 'unsure')),
                'source_module' => 'alerts',
                'source_record_type' => 'alert',
                'source_record_id' => $row->id,
                'title' => $row->title,
                'summary' => $row->detail ?? null,
                'detail' => null,
                'severity' => $this->normalizeSeverity((string) ($row->severity ?? 'warning')),
                'confidence' => null,
                'confidence_score' => null,
                'reason_code' => $row->rule_key ?? null,
                'status' => $row->status === 'closed' ? Finding::STATUS_REVIEWED : Finding::STATUS_NEW,
                'recommended_action' => ['source' => 'alert'],
            ], $dryRun);
        }
    }

    /** @param array<string, int> $result */
    private function backfillReconciliationDiscrepancies(array &$result, ?string $companyId, ?string $businessProfileId, ?int $limit, bool $dryRun): void
    {
        if (! Schema::hasTable('reconciliation_discrepancies')) {
            return;
        }

        $query = DB::table('reconciliation_discrepancies')->orderBy('created_at');
        $this->applyCompanyFilter($query, $companyId);
        $this->applyBusinessProfileFilter($query, 'reconciliation_discrepancies', $businessProfileId);
        $this->applyLimit($query, $limit);

        foreach ($query->get() as $row) {
            $this->upsertFinding($result, $row, [
                'business_profile_id' => $this->businessProfileForRow($row, (string) $row->company_id, $businessProfileId),
                'investigation_id' => null,
                'category' => Investigation::CATEGORY_RECONCILIATION,
                'source_module' => 'reconciliation',
                'source_record_type' => 'reconciliation_discrepancy',
                'source_record_id' => $row->id,
                'title' => 'Reconciliation discrepancy: '.($row->reason_code ?? $row->category ?? 'review required'),
                'summary' => $row->recommendation_explanation ?? null,
                'detail' => null,
                'severity' => $this->normalizeSeverity((string) ($row->risk_level ?? 'warning')),
                'confidence' => $this->confidenceFromScore($row->confidence_score ?? null),
                'confidence_score' => $row->confidence_score ?? null,
                'reason_code' => $row->reason_code ?? null,
                'status' => $row->status === 'resolved' ? Finding::STATUS_REVIEWED : Finding::STATUS_NEW,
                'recommended_action' => ['source' => 'reconciliation_discrepancy'],
            ], $dryRun);
        }
    }

    /** @param array<string, int> $result */
    private function backfillUploadValidationErrors(array &$result, ?string $companyId, ?string $businessProfileId, ?int $limit, bool $dryRun): void
    {
        if (! Schema::hasTable('upload_row_errors')) {
            return;
        }

        $query = DB::table('upload_row_errors')->orderBy('created_at');
        $this->applyCompanyFilter($query, $companyId);
        $this->applyBusinessProfileFilter($query, 'upload_row_errors', $businessProfileId);
        $this->applyLimit($query, $limit);

        foreach ($query->get() as $row) {
            $this->upsertFinding($result, $row, [
                'business_profile_id' => $this->businessProfileForRow($row, (string) $row->company_id, $businessProfileId),
                'investigation_id' => null,
                'category' => Investigation::CATEGORY_UNSURE,
                'source_module' => 'upload_validation',
                'source_record_type' => 'upload_row_error',
                'source_record_id' => $row->id,
                'title' => 'Upload validation issue: '.($row->error_code ?? 'review required'),
                'summary' => $row->message ?? null,
                'detail' => null,
                'severity' => ($row->severity ?? null) === 'blocking' ? Finding::SEVERITY_CRITICAL : Finding::SEVERITY_WARNING,
                'confidence' => 'high',
                'confidence_score' => null,
                'reason_code' => $row->error_code ?? null,
                'status' => Finding::STATUS_NEW,
                'recommended_action' => ['source' => 'upload_validation'],
            ], $dryRun);
        }
    }

    /** @param array<string, int> $result @param array<string, mixed> $data */
    private function upsertFinding(array &$result, object $sourceRow, array $data, bool $dryRun): ?string
    {
        $result['findings']++;
        if ($dryRun) {
            return null;
        }

        $key = [
            'company_id' => $sourceRow->company_id,
            'business_profile_id' => $data['business_profile_id'],
            'source_module' => $data['source_module'],
            'source_record_type' => $data['source_record_type'],
            'source_record_id' => (string) $data['source_record_id'],
        ];
        $existing = DB::table('findings')
            ->where($key)
            ->first();
        $findingId = $existing->id ?? (string) Str::uuid();

        DB::table('findings')->updateOrInsert($key, [
            'id' => $findingId,
            'investigation_id' => $data['investigation_id'],
            'category' => $data['category'],
            'title' => $data['title'],
            'summary' => $data['summary'],
            'detail' => $data['detail'],
            'severity' => $data['severity'],
            'confidence' => $data['confidence'],
            'confidence_score' => $data['confidence_score'],
            'reason_code' => $data['reason_code'],
            'status' => $data['status'],
            'evidence_refs' => json_encode([]),
            'recommended_action' => json_encode($data['recommended_action']),
            'reviewer_status' => $data['status'] === Finding::STATUS_REVIEWED ? 'reviewed' : 'pending',
            'metadata' => json_encode(['legacy_source' => $data['source_module']]),
            'created_at' => $sourceRow->created_at ?? now(),
            'updated_at' => now(),
        ]);

        return $findingId;
    }

    private function businessProfileForRow(object $row, string $companyId, ?string $requestedBusinessProfileId): ?string
    {
        if ($requestedBusinessProfileId) {
            return $requestedBusinessProfileId;
        }
        if (property_exists($row, 'business_profile_id') && $row->business_profile_id) {
            return (string) $row->business_profile_id;
        }
        if (! Schema::hasTable('business_profiles')) {
            return null;
        }

        return DB::table('business_profiles')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->value('id');
    }

    private function normalizeCategory(string $value): string
    {
        $value = str_replace('-', '_', strtolower($value));

        return match (true) {
            str_contains($value, 'vendor') || str_contains($value, 'payment') => Investigation::CATEGORY_VENDOR_PAYMENTS,
            str_contains($value, 'tax') || str_contains($value, 'irs') => Investigation::CATEGORY_TAX,
            str_contains($value, 'recon') => Investigation::CATEGORY_RECONCILIATION,
            str_contains($value, 'fraud') => Investigation::CATEGORY_FRAUD,
            str_contains($value, 'cash') => Investigation::CATEGORY_CASH_FLOW,
            str_contains($value, 'payroll') => Investigation::CATEGORY_PAYROLL,
            str_contains($value, 'expense') => Investigation::CATEGORY_EXPENSE,
            str_contains($value, 'revenue') => Investigation::CATEGORY_REVENUE,
            default => Investigation::CATEGORY_UNSURE,
        };
    }

    private function normalizeInvestigationStatus(string $value): string
    {
        return match ($value) {
            'in_progress', 'in_review', 'investigating', 'escalated' => Investigation::STATUS_IN_REVIEW,
            'waiting_on_records' => Investigation::STATUS_WAITING_ON_RECORDS,
            'pending_review', 'pending_reviewer_approval' => Investigation::STATUS_PENDING_REVIEWER_APPROVAL,
            'ready_for_package' => Investigation::STATUS_READY_FOR_PACKAGE,
            'resolved', 'closed' => Investigation::STATUS_CLOSED,
            'archived' => Investigation::STATUS_ARCHIVED,
            default => Investigation::STATUS_OPEN,
        };
    }

    private function normalizePriority(string $value): string
    {
        return match (strtolower($value)) {
            'critical' => Investigation::PRIORITY_CRITICAL,
            'high' => Investigation::PRIORITY_HIGH,
            'low', 'info' => Investigation::PRIORITY_LOW,
            default => Investigation::PRIORITY_MEDIUM,
        };
    }

    private function normalizeSeverity(string $value): string
    {
        return match (strtolower($value)) {
            'critical', 'high' => Finding::SEVERITY_CRITICAL,
            'low', 'info' => Finding::SEVERITY_INFO,
            default => Finding::SEVERITY_WARNING,
        };
    }

    private function findingStatusFromRecommendation(?string $status): string
    {
        return match ($status) {
            'approved', 'reviewed' => Finding::STATUS_REVIEWED,
            'dismissed', 'expired' => Finding::STATUS_DISMISSED,
            default => Finding::STATUS_NEW,
        };
    }

    private function confidenceFromScore(mixed $score): ?string
    {
        if (! is_numeric($score)) {
            return null;
        }
        $score = (float) $score;

        return match (true) {
            $score >= 0.8 => 'high',
            $score >= 0.5 => 'medium',
            default => 'low',
        };
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function firstUserIdForCompany(string $companyId): ?string
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        return DB::table('users')->where('company_id', $companyId)->orderBy('created_at')->value('id');
    }

    private function userBelongsToCompany(mixed $userId, string $companyId): bool
    {
        return is_string($userId)
            && Schema::hasTable('users')
            && DB::table('users')->where('id', $userId)->where('company_id', $companyId)->exists();
    }

    private function reviewEventExists(string $investigationId, object $event): bool
    {
        return DB::table('review_events')
            ->where('investigation_id', $investigationId)
            ->where('event_type', $event->event_type)
            ->where('created_at', $event->created_at)
            ->exists();
    }

    private function casePackageExists(string $investigationId, object $export): bool
    {
        return DB::table('case_packages')
            ->where('investigation_id', $investigationId)
            ->where('package_hash', $export->report_hash ?? null)
            ->where('filename', $export->filename ?? null)
            ->exists();
    }

    private function applyCompanyFilter($query, ?string $companyId): void
    {
        if ($companyId) {
            $query->where('company_id', $companyId);
        }
    }

    private function applyBusinessProfileFilter($query, string $table, ?string $businessProfileId): void
    {
        if ($businessProfileId && Schema::hasColumn($table, 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }
    }

    private function applyLimit($query, ?int $limit): void
    {
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }
    }
}
