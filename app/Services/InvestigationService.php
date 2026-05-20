<?php

namespace App\Services;

use App\Models\AuditCase;
use App\Models\InvestigationActivityEvent;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvestigationService
{
    private const SENSITIVE_METADATA_KEYS = [
        'evidence',
        'supporting_evidence',
        'raw_evidence',
        'transaction_details',
        'raw_payload',
    ];

    public function list(string $companyId, array $filters = []): array
    {
        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = DB::table('audit_cases as ac')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'ac.investigation_assigned_user_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'ac.created_by')
            ->leftJoin('case_recommendations as cr', 'cr.id', '=', 'ac.case_recommendation_id')
            ->where('ac.company_id', $companyId)
            ->select(
                'ac.id',
                'ac.title',
                'ac.description',
                'ac.status',
                'ac.severity',
                'ac.investigation_status',
                'ac.investigation_priority',
                'ac.investigation_summary',
                'ac.last_activity_at',
                'ac.created_at',
                'ac.updated_at',
                DB::raw("assignee.first_name || ' ' || assignee.last_name AS investigation_assigned_user_name"),
                'ac.investigation_assigned_user_id',
                DB::raw("creator.first_name || ' ' || creator.last_name AS created_by_name"),
                'cr.case_type',
                'cr.confidence_score',
                DB::raw('(SELECT COUNT(*) FROM investigation_activity_events iae WHERE iae.audit_case_id = ac.id) AS activity_count')
            );

        if (! empty($filters['investigation_status']) && $filters['investigation_status'] !== 'all') {
            $query->where('ac.investigation_status', $filters['investigation_status']);
        }

        if (! empty($filters['investigation_priority'])) {
            $query->where('ac.investigation_priority', $filters['investigation_priority']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('ac.investigation_assigned_user_id', $filters['assigned_to']);
        }

        $total = (clone $query)->count();

        $query->orderByRaw(
            "CASE ac.investigation_priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END"
        )->orderBy('ac.last_activity_at', 'desc')->orderBy('ac.created_at', 'desc');

        $investigations = $query->offset($offset)->limit($limit)->get();

        $statusCounts = DB::table('audit_cases')
            ->where('company_id', $companyId)
            ->select('investigation_status', DB::raw('COUNT(*) as count'))
            ->groupBy('investigation_status')
            ->get()
            ->pluck('count', 'investigation_status')
            ->all();

        return [
            'investigations' => $investigations,
            'total' => $total,
            'status_counts' => $statusCounts,
        ];
    }

    public function detail(string $companyId, string $caseId): ?array
    {
        $case = DB::table('audit_cases as ac')
            ->leftJoin('users as creator', 'creator.id', '=', 'ac.created_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'ac.assigned_to')
            ->leftJoin('users as inv_assignee', 'inv_assignee.id', '=', 'ac.investigation_assigned_user_id')
            ->leftJoin('case_recommendations as cr', 'cr.id', '=', 'ac.case_recommendation_id')
            ->where('ac.id', $caseId)
            ->where('ac.company_id', $companyId)
            ->select(
                'ac.id',
                'ac.title',
                'ac.description',
                'ac.status',
                'ac.severity',
                'ac.created_at',
                'ac.updated_at',
                'ac.resolved_at',
                'ac.resolution_notes',
                'ac.investigation_status',
                'ac.investigation_priority',
                'ac.investigation_summary',
                'ac.investigation_notes',
                'ac.last_activity_at',
                'ac.investigation_metadata',
                'ac.investigation_assigned_user_id',
                DB::raw("inv_assignee.first_name || ' ' || inv_assignee.last_name AS investigation_assigned_user_name"),
                DB::raw("creator.first_name || ' ' || creator.last_name AS created_by_name"),
                DB::raw("assignee.first_name || ' ' || assignee.last_name AS assigned_to_name"),
                'ac.case_recommendation_id',
                'cr.case_type',
                'cr.severity AS recommendation_severity',
                'cr.title AS recommendation_title',
                'cr.summary AS recommendation_summary',
                'cr.source_risk_domains',
                'cr.related_alert_recommendation_ids',
                'cr.confidence_score',
                'cr.status AS recommendation_status',
            )
            ->first();

        if (! $case) {
            return null;
        }

        $activity = DB::table('investigation_activity_events as iae')
            ->where('iae.audit_case_id', $caseId)
            ->select(
                'iae.id',
                'iae.event_type',
                'iae.actor_type',
                'iae.actor_id',
                'iae.event_summary',
                'iae.event_metadata',
                'iae.created_at',
            )
            ->orderBy('iae.created_at', 'asc')
            ->get()
            ->map(function ($event): object {
                $event->event_metadata = $event->event_metadata
                    ? json_decode($event->event_metadata, true)
                    : null;

                return $event;
            });

        $alertIdsStr = trim((string) ($case->alert_ids ?? ''), '{}');
        $alertIds = $alertIdsStr ? explode(',', $alertIdsStr) : [];

        $linkedAlerts = [];
        if (! empty($alertIds)) {
            $linkedAlerts = DB::table('alerts')
                ->whereIn('id', $alertIds)
                ->where('company_id', $companyId)
                ->select('id', 'rule_key', 'severity', 'title', 'status', 'created_at')
                ->get();
        }

        $evidenceItems = DB::table('investigation_evidence_items')
            ->where('audit_case_id', $caseId)
            ->where('company_id', $companyId)
            ->select(
                'id',
                'evidence_type',
                'evidence_reference_id',
                'title',
                'summary',
                'source',
                'added_by_actor_type',
                'added_by_actor_id',
                'metadata',
                'created_at',
            )
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function (object $item): object {
                $item->metadata = $item->metadata
                    ? json_decode($item->metadata, true)
                    : null;

                return $item;
            });

        $evidenceSummary = null;
        if ($case->case_recommendation_id) {
            $evidenceSummary = $this->buildEvidenceSummary(
                $case->case_recommendation_id,
                $companyId
            );
        }

        $recommendation = null;
        if ($case->case_recommendation_id) {
            $recommendation = [
                'id' => $case->case_recommendation_id,
                'case_type' => $case->case_type,
                'severity' => $case->recommendation_severity,
                'title' => $case->recommendation_title,
                'summary' => $case->recommendation_summary,
                'source_risk_domains' => $case->source_risk_domains
                    ? json_decode($case->source_risk_domains, true)
                    : [],
                'related_alert_recommendation_ids' => $case->related_alert_recommendation_ids
                    ? json_decode($case->related_alert_recommendation_ids, true)
                    : [],
                'confidence_score' => $case->confidence_score,
                'status' => $case->recommendation_status,
            ];
        }

        return [
            'investigation' => [
                'id' => $case->id,
                'title' => $case->title,
                'description' => $case->description,
                'status' => $case->status,
                'severity' => $case->severity,
                'created_at' => $case->created_at,
                'updated_at' => $case->updated_at,
                'resolved_at' => $case->resolved_at,
                'resolution_notes' => $case->resolution_notes,
                'created_by' => $case->created_by_name,
                'assigned_to' => $case->assigned_to_name,
            ],
            'workspace' => [
                'investigation_status' => $case->investigation_status,
                'investigation_priority' => $case->investigation_priority,
                'investigation_summary' => $case->investigation_summary,
                'investigation_notes' => $case->investigation_notes,
                'investigation_metadata' => $case->investigation_metadata
                    ? json_decode($case->investigation_metadata, true)
                    : null,
                'last_activity_at' => $case->last_activity_at,
                'assigned_user' => $case->investigation_assigned_user_id
                    ? [
                        'id' => $case->investigation_assigned_user_id,
                        'name' => $case->investigation_assigned_user_name,
                    ]
                    : null,
            ],
            'recommendation' => $recommendation,
            'linked_alerts' => $linkedAlerts,
            'evidence_summary' => $evidenceSummary,
            'evidence_items' => $evidenceItems,
            'activity_timeline' => $activity,
            'report_exports' => $this->getReportExports($companyId, $caseId),
        ];
    }

    public function reportExports(string $companyId, string $caseId): ?array
    {
        $exists = DB::table('audit_cases')
            ->where('id', $caseId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $exists) {
            return null;
        }

        return [
            'report_exports' => $this->getReportExports($companyId, $caseId),
        ];
    }

    public function assign(string $companyId, string $actorId, string $caseId, string $assigneeId): array
    {
        $case = AuditCase::where('id', $caseId)->where('company_id', $companyId)->first();
        if (! $case) {
            throw new Exception('Investigation not found', 404);
        }

        $assignee = DB::table('users')
            ->where('id', $assigneeId)
            ->where('company_id', $companyId)
            ->select('id', 'first_name', 'last_name', 'email')
            ->first();

        if (! $assignee) {
            throw new Exception('Assignee not found or does not belong to this company', 422);
        }

        $previousAssigneeId = $case->investigation_assigned_user_id;

        $case->update(['investigation_assigned_user_id' => $assigneeId]);

        $this->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_ASSIGNED,
            actorType: InvestigationActivityEvent::ACTOR_USER,
            actorId: $actorId,
            eventSummary: "Investigation assigned to {$assignee->first_name} {$assignee->last_name}",
            eventMetadata: [
                'previous_assignee_id' => $previousAssigneeId,
                'new_assignee_id' => $assigneeId,
                'new_assignee_name' => "{$assignee->first_name} {$assignee->last_name}",
            ],
        );

        return ['case' => $case->fresh(), 'assignee' => $assignee];
    }

    public function updateStatus(string $companyId, string $actorId, string $caseId, string $newStatus): array
    {
        $validStatuses = [
            AuditCase::INVESTIGATION_STATUS_OPEN,
            AuditCase::INVESTIGATION_STATUS_IN_REVIEW,
            AuditCase::INVESTIGATION_STATUS_ESCALATED,
            AuditCase::INVESTIGATION_STATUS_RESOLVED,
            AuditCase::INVESTIGATION_STATUS_ARCHIVED,
        ];

        if (! in_array($newStatus, $validStatuses, true)) {
            throw new Exception('Invalid investigation status', 422);
        }

        $case = AuditCase::where('id', $caseId)->where('company_id', $companyId)->first();
        if (! $case) {
            throw new Exception('Investigation not found', 404);
        }

        $previousStatus = $case->investigation_status;

        if ($previousStatus === $newStatus) {
            return ['case' => $case];
        }

        $case->update(['investigation_status' => $newStatus]);

        $this->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_STATUS_CHANGED,
            actorType: InvestigationActivityEvent::ACTOR_USER,
            actorId: $actorId,
            eventSummary: "Investigation status changed from {$previousStatus} to {$newStatus}",
            eventMetadata: [
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
            ],
        );

        return ['case' => $case->fresh()];
    }

    public function addNotes(string $companyId, string $actorId, string $caseId, string $notes): array
    {
        $case = AuditCase::where('id', $caseId)->where('company_id', $companyId)->first();
        if (! $case) {
            throw new Exception('Investigation not found', 404);
        }

        $hadNotesBefore = $case->investigation_notes !== null;
        $case->update(['investigation_notes' => $notes]);

        $this->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_NOTES_ADDED,
            actorType: InvestigationActivityEvent::ACTOR_USER,
            actorId: $actorId,
            eventSummary: $hadNotesBefore ? 'Investigation notes updated' : 'Investigation notes added',
            eventMetadata: [
                'has_previous_notes' => $hadNotesBefore,
                'note_length' => strlen($notes),
            ],
        );

        return ['case' => $case->fresh()];
    }

    public function recordActivity(
        string $caseId,
        string $companyId,
        string $eventType,
        string $actorType,
        ?string $actorId,
        string $eventSummary,
        ?array $eventMetadata = null,
    ): InvestigationActivityEvent {
        $sanitizedMetadata = $eventMetadata !== null
            ? $this->sanitizeMetadata($eventMetadata)
            : null;

        $event = InvestigationActivityEvent::create([
            'audit_case_id' => $caseId,
            'company_id' => $companyId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_summary' => $eventSummary,
            'event_metadata' => $sanitizedMetadata,
        ]);

        DB::table('audit_cases')
            ->where('id', $caseId)
            ->update(['last_activity_at' => now()]);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_METADATA_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $sanitized;
    }

    private function buildEvidenceSummary(string $caseRecommendationId, string $companyId): ?array
    {
        $recommendation = DB::table('case_recommendations')
            ->where('id', $caseRecommendationId)
            ->where('company_id', $companyId)
            ->select('evidence', 'source_risk_domains')
            ->first();

        if (! $recommendation) {
            return null;
        }

        $evidence = json_decode($recommendation->evidence ?? '{}', true);
        $sourceDomains = json_decode($recommendation->source_risk_domains ?? '[]', true);

        return [
            'source_risk_domains' => $sourceDomains,
            'evidence_domains' => array_keys($evidence),
            'evidence_domain_count' => count($evidence),
        ];
    }

    private function getReportExports(string $companyId, string $caseId): Collection
    {
        return DB::table('investigation_report_exports as ire')
            ->leftJoin('users as generator', 'generator.id', '=', 'ire.generated_by_user_id')
            ->where('ire.audit_case_id', $caseId)
            ->where('ire.company_id', $companyId)
            ->select(
                'ire.id',
                'ire.audit_case_id',
                'ire.company_id',
                'ire.generated_by_user_id',
                DB::raw("generator.first_name || ' ' || generator.last_name AS generated_by_user_name"),
                'ire.format',
                'ire.filename',
                'ire.report_hash',
                'ire.generated_at',
                'ire.metadata',
            )
            ->orderBy('ire.generated_at', 'desc')
            ->get()
            ->map(function (object $export): object {
                $export->metadata = $export->metadata
                    ? json_decode($export->metadata, true)
                    : null;

                return $export;
            });
    }
}
