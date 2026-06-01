<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AuditCase;
use App\Models\AuditCaseEvent;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CaseService
{
    private const TRANSITIONS = [
        'open' => ['investigating'],
        'investigating' => ['resolved', 'open'],
        'resolved' => ['archived', 'open'],
        'archived' => ['open'],
    ];

    public function create(string $companyId, string $userId, array $data, ?string $businessProfileId = null): AuditCase
    {
        $alertIds = $this->normalizeIds($data['alert_ids'] ?? []);
        $transactionIds = $this->normalizeIds($data['transaction_ids'] ?? []);

        $this->assertAlertsBelongToContext($companyId, $alertIds, $businessProfileId);
        $this->assertTransactionsBelongToContext($companyId, $transactionIds, $businessProfileId);

        $payload = [
            'company_id' => $companyId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'] ?? 'warning',
            'created_by' => $userId,
            'assigned_to' => $data['assigned_to'] ?? null,
        ];

        if ($businessProfileId && Schema::hasColumn('audit_cases', 'business_profile_id')) {
            $payload['business_profile_id'] = $businessProfileId;
        }

        $case = AuditCase::create($payload);

        if ($alertIds !== []) {
            $this->writeCaseArray($case->id, 'alert_ids', $alertIds);
        }

        if ($transactionIds !== []) {
            $this->writeCaseArray($case->id, 'transaction_ids', $transactionIds);
        }

        AuditCaseEvent::create($this->eventPayload([
            'case_id' => $case->id,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => 'case_created',
            'payload' => ['title' => $data['title'], 'severity' => $data['severity'] ?? 'warning'],
        ], $businessProfileId));

        return $case->fresh();
    }

    public function list(string $companyId, array $filters = [], ?string $businessProfileId = null): array
    {
        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = DB::table('audit_cases as ac')
            ->join('users as creator', 'creator.id', '=', 'ac.created_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'ac.assigned_to')
            ->where('ac.company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_cases', 'business_profile_id'),
                fn ($query) => $query->where('ac.business_profile_id', $businessProfileId),
            )
            ->select(
                'ac.*',
                DB::raw("creator.first_name || ' ' || creator.last_name AS created_by_name"),
                DB::raw("assignee.first_name || ' ' || assignee.last_name AS assigned_to_name"),
                DB::raw('(SELECT COUNT(*) FROM audit_case_events ace WHERE ace.case_id = ac.id) AS event_count')
            );

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('ac.status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $query->where('ac.severity', $filters['severity']);
        }
        if (! empty($filters['assigned_to'])) {
            $query->where('ac.assigned_to', $filters['assigned_to']);
        }

        $query->orderByRaw(
            "CASE ac.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END"
        )->orderBy('ac.created_at', 'desc');

        $cases = $query->offset($offset)->limit($limit)->get();

        $countRows = DB::table('audit_cases')
            ->where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_cases', 'business_profile_id'),
                fn ($query) => $query->where('business_profile_id', $businessProfileId),
            )
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        $counts = [];
        foreach ($countRows as $row) {
            $counts[$row->status] = $row->count;
        }

        return ['cases' => $cases, 'counts' => $counts];
    }

    public function detail(string $companyId, string $caseId, ?string $businessProfileId = null): ?array
    {
        $case = DB::table('audit_cases as ac')
            ->join('users as creator', 'creator.id', '=', 'ac.created_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'ac.assigned_to')
            ->where('ac.id', $caseId)
            ->where('ac.company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_cases', 'business_profile_id'),
                fn ($query) => $query->where('ac.business_profile_id', $businessProfileId),
            )
            ->select(
                'ac.*',
                DB::raw("creator.first_name || ' ' || creator.last_name AS created_by_name"),
                DB::raw("assignee.first_name || ' ' || assignee.last_name AS assigned_to_name")
            )
            ->first();

        if (! $case) {
            return null;
        }

        $events = DB::table('audit_case_events as ace')
            ->leftJoin('users as u', 'u.id', '=', 'ace.user_id')
            ->where('ace.case_id', $caseId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_case_events', 'business_profile_id'),
                fn ($query) => $query->where('ace.business_profile_id', $businessProfileId),
            )
            ->select('ace.*', DB::raw("u.first_name || ' ' || u.last_name AS user_name"))
            ->orderBy('ace.created_at', 'asc')
            ->get()
            ->map(function ($event) {
                $event->payload = $this->decodeJson($event->payload);

                return $event;
            });

        $alertIds = $this->parseCaseArray($case->alert_ids ?? null);
        $alerts = [];
        if ($alertIds !== []) {
            $alerts = DB::table('alerts')
                ->whereIn('id', $alertIds)
                ->where('company_id', $companyId)
                ->when(
                    $businessProfileId && Schema::hasColumn('alerts', 'business_profile_id'),
                    fn ($query) => $query->where('business_profile_id', $businessProfileId),
                )
                ->select($this->alertSelectColumns())
                ->get()
                ->map(function ($alert) {
                    $alert->evidence = $this->decodeJson($alert->evidence ?? null, []);
                    $alert->reasonCodes = $this->decodeJson($alert->reason_codes ?? null, []);
                    $alert->sourceSystem = $alert->source_system ?? null;
                    $alert->evidenceRefs = $this->decodeJson($alert->evidence_refs ?? null, []);
                    $alert->confidenceScore = (float) ($alert->confidence_score ?? 0);
                    $alert->deterministicCheckName = $alert->rule_key;
                    $alert->comparisonWindow = $this->decodeJson($alert->comparison_window ?? null);
                    $alert->humanReviewStatus = $alert->status === 'reviewed' ? 'reviewed' : 'pending';

                    return $alert;
                });
        }

        $txnIds = $this->parseCaseArray($case->transaction_ids ?? null);
        $transactions = [];
        if ($txnIds !== [] && Schema::hasTable('all_transactions')) {
            $transactions = DB::table('all_transactions')
                ->whereIn('id', $txnIds)
                ->where('company_id', $companyId)
                ->when(
                    $businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id'),
                    fn ($query) => $query->where('business_profile_id', $businessProfileId),
                )
                ->get();
        }

        return [
            'case' => $case,
            'events' => $events,
            'alerts' => $alerts,
            'transactions' => $transactions,
            'pdfs' => [], // Skipped PDF implementation for now
        ];
    }

    public function update(string $companyId, string $userId, string $caseId, array $data, ?string $businessProfileId = null): array
    {
        $case = $this->caseQuery($companyId, $businessProfileId)
            ->where('id', $caseId)
            ->first();
        if (! $case) {
            throw new Exception('Case not found', 404);
        }

        $status = $data['status'] ?? null;
        if ($status && $status !== $case->status) {
            $allowed = self::TRANSITIONS[$case->status] ?? [];
            if (! in_array($status, $allowed, true)) {
                throw new Exception("Invalid transition: {$case->status} -> {$status}", 422);
            }
            if ($status === 'resolved' && empty($data['resolution_notes']) && empty($case->resolution_notes)) {
                throw new Exception('Resolution notes required when resolving a case', 422);
            }
        }

        $updates = [];
        foreach (['title', 'description', 'severity', 'assigned_to', 'resolution_notes', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if ($status === 'resolved' || $status === 'archived') {
            $updates['resolved_at'] = now();
        }

        $case->update($updates);

        if ($status && $status !== $case->getOriginal('status')) {
            AuditCaseEvent::create($this->eventPayload([
                'case_id' => $case->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'event_type' => 'status_change',
                'payload' => ['from' => $case->getOriginal('status'), 'to' => $status],
            ], $businessProfileId));
        }

        return ['case' => $case->fresh()];
    }

    public function addEvent(string $companyId, string $userId, string $caseId, string $eventType, array $payload, ?string $businessProfileId = null): array
    {
        $case = $this->caseQuery($companyId, $businessProfileId)
            ->where('id', $caseId)
            ->first();
        if (! $case) {
            throw new Exception('Case not found', 404);
        }

        $event = AuditCaseEvent::create($this->eventPayload([
            'case_id' => $caseId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'payload' => $payload,
        ], $businessProfileId));

        $case->touch();

        return ['event' => $event];
    }

    public function linkAlert(string $companyId, string $userId, string $caseId, string $alertId, ?string $businessProfileId = null): array
    {
        $alert = $this->alertQuery($companyId, $businessProfileId)
            ->where('id', $alertId)
            ->first();
        if (! $alert) {
            throw new Exception('Alert not found', 404);
        }

        $case = $this->caseQuery($companyId, $businessProfileId)
            ->where('id', $caseId)
            ->first();
        if (! $case) {
            throw new Exception('Case not found', 404);
        }

        $alertIds = $this->parseCaseArray($case->alert_ids ?? null);
        if (in_array($alertId, $alertIds, true)) {
            throw new Exception('Case not found or alert already linked', 404);
        }

        $alertIds[] = $alertId;
        $this->writeCaseArray($caseId, 'alert_ids', $alertIds);

        AuditCaseEvent::create($this->eventPayload([
            'case_id' => $caseId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => 'alert_linked',
            'payload' => ['alertId' => $alertId, 'alertTitle' => $alert->title],
        ], $businessProfileId));

        return ['case' => $this->caseQuery($companyId, $businessProfileId)->where('id', $caseId)->first()];
    }

    public function unlinkAlert(string $companyId, string $userId, string $caseId, string $alertId, ?string $businessProfileId = null): array
    {
        $case = $this->caseQuery($companyId, $businessProfileId)
            ->where('id', $caseId)
            ->first();
        if (! $case) {
            throw new Exception('Case not found', 404);
        }

        $alertIds = array_values(array_filter(
            $this->parseCaseArray($case->alert_ids ?? null),
            fn (string $id): bool => $id !== $alertId,
        ));
        $this->writeCaseArray($caseId, 'alert_ids', $alertIds);

        AuditCaseEvent::create($this->eventPayload([
            'case_id' => $caseId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => 'alert_unlinked',
            'payload' => ['alertId' => $alertId],
        ], $businessProfileId));

        return ['case' => $this->caseQuery($companyId, $businessProfileId)->where('id', $caseId)->first()];
    }

    public function summary(string $companyId, string $caseId, ?string $businessProfileId = null): ?array
    {
        $case = DB::table('audit_cases as ac')
            ->leftJoin('users as u', 'u.id', '=', 'ac.created_by')
            ->leftJoin('users as ua', 'ua.id', '=', 'ac.assigned_to')
            ->where('ac.id', $caseId)
            ->where('ac.company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_cases', 'business_profile_id'),
                fn ($query) => $query->where('ac.business_profile_id', $businessProfileId),
            )
            ->select(
                'ac.*',
                DB::raw("u.first_name || ' ' || u.last_name AS created_by_name"),
                DB::raw("ua.first_name || ' ' || ua.last_name AS assigned_to_name")
            )
            ->first();

        if (! $case) {
            return null;
        }

        $events = DB::table('audit_case_events as ace')
            ->leftJoin('users as u', 'u.id', '=', 'ace.user_id')
            ->where('ace.case_id', $caseId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_case_events', 'business_profile_id'),
                fn ($query) => $query->where('ace.business_profile_id', $businessProfileId),
            )
            ->select('ace.*', DB::raw("u.first_name || ' ' || u.last_name AS user_name"))
            ->orderBy('ace.created_at', 'asc')
            ->get()
            ->map(function ($event) {
                $event->payload = $this->decodeJson($event->payload);

                return $event;
            });

        $alertIds = $this->parseCaseArray($case->alert_ids ?? null);
        $txnIds = $this->parseCaseArray($case->transaction_ids ?? null);
        $vendors = [];
        $totalImpact = 0.0;

        if ($txnIds !== [] && Schema::hasTable('all_transactions')) {
            $transactionQuery = DB::table('all_transactions')
                ->whereIn('id', $txnIds)
                ->where('company_id', $companyId)
                ->when(
                    $businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id'),
                    fn ($query) => $query->where('business_profile_id', $businessProfileId),
                );

            $totalImpact = (float) (clone $transactionQuery)->sum('amount');
            $vendors = $transactionQuery
                ->select('vendor_customer', DB::raw('COUNT(*) AS count'), DB::raw('SUM(amount) AS total'))
                ->groupBy('vendor_customer')
                ->orderByDesc('total')
                ->get();
        }

        return [
            'case' => [
                'id' => $case->id,
                'title' => $case->title,
                'description' => $case->description,
                'status' => $case->status,
                'severity' => $case->severity,
                'created_at' => $case->created_at,
                'resolved_at' => $case->resolved_at,
                'created_by' => $case->created_by_name,
                'assigned_to' => $case->assigned_to_name,
                'resolution_notes' => $case->resolution_notes,
            ],
            'stats' => [
                'alertCount' => count($alertIds),
                'transactionCount' => count($txnIds),
                'totalImpact' => $totalImpact,
            ],
            'vendors' => $vendors,
            'timeline' => $events,
        ];
    }

    private function caseQuery(string $companyId, ?string $businessProfileId = null): Builder
    {
        return AuditCase::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_cases', 'business_profile_id'),
                fn ($query) => $query->where('business_profile_id', $businessProfileId),
            );
    }

    private function alertQuery(string $companyId, ?string $businessProfileId = null): Builder
    {
        return Alert::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('alerts', 'business_profile_id'),
                fn ($query) => $query->where('business_profile_id', $businessProfileId),
            );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function eventPayload(array $payload, ?string $businessProfileId = null): array
    {
        if ($businessProfileId && Schema::hasColumn('audit_case_events', 'business_profile_id')) {
            $payload['business_profile_id'] = $businessProfileId;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function normalizeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($id): string => trim((string) $id), $ids),
            fn (string $id): bool => $id !== '',
        )));
    }

    /**
     * @param  list<string>  $alertIds
     */
    private function assertAlertsBelongToContext(string $companyId, array $alertIds, ?string $businessProfileId = null): void
    {
        if ($alertIds === []) {
            return;
        }

        $count = $this->alertQuery($companyId, $businessProfileId)
            ->whereIn('id', $alertIds)
            ->count();

        if ($count !== count($alertIds)) {
            throw new Exception('Linked alert not found', 404);
        }
    }

    /**
     * @param  list<string>  $transactionIds
     */
    private function assertTransactionsBelongToContext(string $companyId, array $transactionIds, ?string $businessProfileId = null): void
    {
        if ($transactionIds === []) {
            return;
        }

        if (! Schema::hasTable('all_transactions')) {
            throw new Exception('Linked transaction not found', 404);
        }

        $count = DB::table('all_transactions')
            ->where('company_id', $companyId)
            ->whereIn('id', $transactionIds)
            ->when(
                $businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id'),
                fn ($query) => $query->where('business_profile_id', $businessProfileId),
            )
            ->count();

        if ($count !== count($transactionIds)) {
            throw new Exception('Linked transaction not found', 404);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function writeCaseArray(string $caseId, string $column, array $ids): void
    {
        if (! in_array($column, ['alert_ids', 'transaction_ids'], true) || ! Schema::hasColumn('audit_cases', $column)) {
            return;
        }

        $ids = $this->normalizeIds($ids);
        $literal = $this->toPostgresArrayLiteral($ids);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "UPDATE audit_cases SET {$column} = ?::uuid[], updated_at = ? WHERE id = ?",
                [$literal, now(), $caseId],
            );

            return;
        }

        DB::table('audit_cases')
            ->where('id', $caseId)
            ->update([
                $column => $literal,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return list<string>
     */
    private function parseCaseArray(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalizeIds($value);
        }

        if ($value === null || $value === '') {
            return [];
        }

        $value = trim((string) $value);
        if ($value === '{}' || $value === '') {
            return [];
        }

        return $this->normalizeIds(explode(',', trim($value, '{}')));
    }

    /**
     * @param  list<string>  $ids
     */
    private function toPostgresArrayLiteral(array $ids): string
    {
        if ($ids === []) {
            return '{}';
        }

        return '{'.implode(',', array_map(
            fn (string $id): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $id),
            $ids,
        )).'}';
    }

    /**
     * @return array<int, string>
     */
    private function alertSelectColumns(): array
    {
        return array_values(array_filter([
            'id',
            Schema::hasColumn('alerts', 'rule_key') ? 'rule_key' : null,
            'severity',
            'title',
            Schema::hasColumn('alerts', 'detail') ? 'detail' : null,
            Schema::hasColumn('alerts', 'evidence') ? 'evidence' : null,
            'created_at',
            'status',
            Schema::hasColumn('alerts', 'reason_codes') ? 'reason_codes' : null,
            Schema::hasColumn('alerts', 'source_system') ? 'source_system' : null,
            Schema::hasColumn('alerts', 'confidence_score') ? 'confidence_score' : null,
            Schema::hasColumn('alerts', 'evidence_refs') ? 'evidence_refs' : null,
            Schema::hasColumn('alerts', 'comparison_window') ? 'comparison_window' : null,
        ]));
    }

    private function decodeJson(mixed $value, mixed $default = null): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}
