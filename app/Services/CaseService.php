<?php

namespace App\Services;

use App\Models\AuditCase;
use App\Models\AuditCaseEvent;
use App\Models\Alert;
use Illuminate\Support\Facades\DB;
use Exception;

class CaseService
{
    private const TRANSITIONS = [
        'open'          => ['investigating'],
        'investigating' => ['resolved', 'open'],
        'resolved'      => ['archived', 'open'],
        'archived'      => ['open'],
    ];

    public function create(string $companyId, string $userId, array $data): AuditCase
    {
        $case = AuditCase::create([
            'company_id' => $companyId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'] ?? 'warning',
            'created_by' => $userId,
            'assigned_to' => $data['assigned_to'] ?? null,
        ]);

        if (!empty($data['alert_ids'])) {
            $alertIds = implode(',', array_map(fn($id) => "'$id'", $data['alert_ids']));
            DB::statement("UPDATE audit_cases SET alert_ids = array[$alertIds]::uuid[] WHERE id = ?", [$case->id]);
        }
        
        if (!empty($data['transaction_ids'])) {
            $txnIds = implode(',', array_map(fn($id) => "'$id'", $data['transaction_ids']));
            DB::statement("UPDATE audit_cases SET transaction_ids = array[$txnIds]::uuid[] WHERE id = ?", [$case->id]);
        }

        AuditCaseEvent::create([
            'case_id' => $case->id,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => 'case_created',
            'payload' => ['title' => $data['title'], 'severity' => $data['severity'] ?? 'warning'],
        ]);

        return $case->fresh();
    }

    public function list(string $companyId, array $filters = []): array
    {
        $limit = min((int)($filters['limit'] ?? 50), 100);
        $offset = max((int)($filters['offset'] ?? 0), 0);

        $query = DB::table('audit_cases as ac')
            ->join('users as creator', 'creator.id', '=', 'ac.created_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'ac.assigned_to')
            ->where('ac.company_id', $companyId)
            ->select(
                'ac.*',
                DB::raw("creator.first_name || ' ' || creator.last_name AS created_by_name"),
                DB::raw("assignee.first_name || ' ' || assignee.last_name AS assigned_to_name"),
                DB::raw("(SELECT COUNT(*)::int FROM audit_case_events ace WHERE ace.case_id = ac.id) AS event_count")
            );

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('ac.status', $filters['status']);
        }
        if (!empty($filters['severity'])) {
            $query->where('ac.severity', $filters['severity']);
        }
        if (!empty($filters['assigned_to'])) {
            $query->where('ac.assigned_to', $filters['assigned_to']);
        }

        $query->orderByRaw(
            "CASE ac.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END"
        )->orderBy('ac.created_at', 'desc');

        $cases = $query->offset($offset)->limit($limit)->get();

        $countRows = DB::table('audit_cases')
            ->where('company_id', $companyId)
            ->select('status', DB::raw('COUNT(*)::int as count'))
            ->groupBy('status')
            ->get();

        $counts = [];
        foreach ($countRows as $row) {
            $counts[$row->status] = $row->count;
        }

        return ['cases' => $cases, 'counts' => $counts];
    }

    public function detail(string $companyId, string $caseId): ?array
    {
        $case = DB::table('audit_cases as ac')
            ->join('users as creator', 'creator.id', '=', 'ac.created_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'ac.assigned_to')
            ->where('ac.id', $caseId)
            ->where('ac.company_id', $companyId)
            ->select(
                'ac.*',
                DB::raw("creator.first_name || ' ' || creator.last_name AS created_by_name"),
                DB::raw("assignee.first_name || ' ' || assignee.last_name AS assigned_to_name")
            )
            ->first();

        if (!$case) return null;

        $events = DB::table('audit_case_events as ace')
            ->leftJoin('users as u', 'u.id', '=', 'ace.user_id')
            ->where('ace.case_id', $caseId)
            ->select('ace.*', DB::raw("u.first_name || ' ' || u.last_name AS user_name"))
            ->orderBy('ace.created_at', 'asc')
            ->get()
            ->map(function ($event) {
                $event->payload = json_decode($event->payload, true);
                return $event;
            });

        // Convert Postgres array string "{uuid,uuid}" to PHP array
        $alertIdsStr = trim($case->alert_ids, '{}');
        $alertIds = $alertIdsStr ? explode(',', $alertIdsStr) : [];
        
        $alerts = [];
        if (!empty($alertIds)) {
            $alerts = DB::table('alerts')
                ->whereIn('id', $alertIds)
                ->where('company_id', $companyId)
                ->select(
                    'id', 'rule_key', 'severity', 'title', 'detail', 'evidence', 'created_at', 'status',
                    'reason_codes', 'source_system', 'confidence_score', 'evidence_refs', 'comparison_window'
                )
                ->get()
                ->map(function ($alert) {
                    $alert->evidence = json_decode($alert->evidence, true);
                    $alert->reasonCodes = json_decode($alert->reason_codes ?? '[]', true);
                    $alert->sourceSystem = $alert->source_system;
                    $alert->evidenceRefs = json_decode($alert->evidence_refs ?? '[]', true);
                    $alert->confidenceScore = (float) $alert->confidence_score;
                    $alert->deterministicCheckName = $alert->rule_key;
                    $alert->comparisonWindow = json_decode($alert->comparison_window ?? 'null', true);
                    $alert->humanReviewStatus = $alert->status === 'reviewed' ? 'reviewed' : 'pending';
                    return $alert;
                });
        }

        $txnIdsStr = trim($case->transaction_ids, '{}');
        $txnIds = $txnIdsStr ? explode(',', $txnIdsStr) : [];

        $transactions = [];
        if (!empty($txnIds)) {
            $transactions = DB::table('all_transactions')
                ->whereIn('id', $txnIds)
                ->where('company_id', $companyId)
                ->select('id', 'date', 'vendor_customer', 'amount', 'category', 'type', 'anomaly_reason', 'payment_method')
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

    public function update(string $companyId, string $userId, string $caseId, array $data): array
    {
        $case = AuditCase::where('id', $caseId)->where('company_id', $companyId)->first();
        if (!$case) throw new Exception('Case not found', 404);

        $status = $data['status'] ?? null;
        if ($status && $status !== $case->status) {
            $allowed = self::TRANSITIONS[$case->status] ?? [];
            if (!in_array($status, $allowed)) {
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
            AuditCaseEvent::create([
                'case_id' => $case->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'event_type' => 'status_change',
                'payload' => ['from' => $case->getOriginal('status'), 'to' => $status],
            ]);
        }

        return ['case' => $case->fresh()];
    }

    public function addEvent(string $companyId, string $userId, string $caseId, string $eventType, array $payload): array
    {
        $case = AuditCase::where('id', $caseId)->where('company_id', $companyId)->first();
        if (!$case) throw new Exception('Case not found', 404);

        $event = AuditCaseEvent::create([
            'case_id' => $caseId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);

        $case->touch(); // Update updated_at

        return ['event' => $event];
    }

    public function linkAlert(string $companyId, string $userId, string $caseId, string $alertId): array
    {
        $alert = Alert::where('id', $alertId)->where('company_id', $companyId)->first();
        if (!$alert) throw new Exception('Alert not found', 404);

        $updated = DB::update(
            "UPDATE audit_cases
             SET alert_ids = array_append(alert_ids, ?::uuid),
                 updated_at = NOW()
             WHERE id = ? AND company_id = ?
               AND NOT (?::uuid = ANY(alert_ids))",
            [$alertId, $caseId, $companyId, $alertId]
        );

        if (!$updated) throw new Exception('Case not found or alert already linked', 404);

        AuditCaseEvent::create([
            'case_id' => $caseId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => 'alert_linked',
            'payload' => ['alertId' => $alertId, 'alertTitle' => $alert->title],
        ]);

        return ['case' => AuditCase::find($caseId)];
    }

    public function unlinkAlert(string $companyId, string $userId, string $caseId, string $alertId): array
    {
        $updated = DB::update(
            "UPDATE audit_cases
             SET alert_ids = array_remove(alert_ids, ?::uuid),
                 updated_at = NOW()
             WHERE id = ? AND company_id = ?",
            [$alertId, $caseId, $companyId]
        );

        if (!$updated) throw new Exception('Case not found', 404);

        AuditCaseEvent::create([
            'case_id' => $caseId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => 'alert_unlinked',
            'payload' => ['alertId' => $alertId],
        ]);

        return ['case' => AuditCase::find($caseId)];
    }

    public function summary(string $companyId, string $caseId): ?array
    {
        $case = DB::table('audit_cases as ac')
            ->leftJoin('users as u', 'u.id', '=', 'ac.created_by')
            ->leftJoin('users as ua', 'ua.id', '=', 'ac.assigned_to')
            ->where('ac.id', $caseId)
            ->where('ac.company_id', $companyId)
            ->select(
                'ac.*',
                DB::raw("u.first_name || ' ' || u.last_name AS created_by_name"),
                DB::raw("ua.first_name || ' ' || ua.last_name AS assigned_to_name"),
                DB::raw("array_length(ac.alert_ids, 1) AS alert_count"),
                DB::raw("array_length(ac.transaction_ids, 1) AS transaction_count"),
                DB::raw("(SELECT COALESCE(SUM(amount), 0) FROM all_transactions WHERE id = ANY(ac.transaction_ids)) AS total_impact")
            )
            ->first();

        if (!$case) return null;

        $events = DB::table('audit_case_events as ace')
            ->leftJoin('users as u', 'u.id', '=', 'ace.user_id')
            ->where('ace.case_id', $caseId)
            ->select('ace.*', DB::raw("u.first_name || ' ' || u.last_name AS user_name"))
            ->orderBy('ace.created_at', 'asc')
            ->get()
            ->map(function ($event) {
                $event->payload = json_decode($event->payload, true);
                return $event;
            });

        $vendors = [];
        $txnIdsStr = trim($case->transaction_ids, '{}');
        if ($txnIdsStr) {
            $txnIdsArray = explode(',', $txnIdsStr);
            $txnIdsList = '{' . implode(',', $txnIdsArray) . '}';
            $vendors = DB::select(
                "SELECT vendor_customer, COUNT(*)::int AS count, SUM(amount) AS total
                 FROM all_transactions
                 WHERE id = ANY(?::uuid[])
                 GROUP BY vendor_customer
                 ORDER BY total DESC",
                [$txnIdsList]
            );
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
                'alertCount' => $case->alert_count ?: 0,
                'transactionCount' => $case->transaction_count ?: 0,
                'totalImpact' => (float)($case->total_impact ?: 0),
            ],
            'vendors' => $vendors,
            'timeline' => $events,
        ];
    }
}
