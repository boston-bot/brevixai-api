<?php

namespace App\Services;

use App\Models\InvestigationActivityEvent;
use Exception;

class InvestigationReportService
{
    private const DISCLAIMER = 'This report summarizes risk indicators and review activity. It is not a legal conclusion or proof of fraud.';

    private const SENSITIVE_METADATA_KEYS = [
        'evidence',
        'supporting_evidence',
        'raw_evidence',
        'transaction_details',
        'raw_payload',
    ];

    public function __construct(
        private readonly InvestigationService $investigationService,
    ) {}

    /**
     * Generate a structured report payload for an investigation.
     * Only users (actorType = 'user') may generate reports. Agents are blocked.
     */
    public function generate(
        string $companyId,
        string $caseId,
        string $actorType,
        string $actorId,
    ): array {
        if ($actorType === InvestigationActivityEvent::ACTOR_AGENT) {
            throw new Exception('Agents cannot generate investigation reports', 403);
        }

        $detail = $this->investigationService->detail($companyId, $caseId);
        if (! $detail) {
            throw new Exception('Investigation not found', 404);
        }

        $investigation = $detail['investigation'];
        $workspace = $detail['workspace'];
        $recommendation = $detail['recommendation'];
        $evidenceItems = $detail['evidence_items'];
        $activityTimeline = $detail['activity_timeline'];
        $linkedAlerts = $detail['linked_alerts'];

        $caseSummary = [
            'id' => $investigation['id'],
            'title' => $investigation['title'],
            'description' => $investigation['description'],
            'status' => $investigation['status'],
            'severity' => $investigation['severity'],
            'created_at' => $investigation['created_at'],
            'resolved_at' => $investigation['resolved_at'],
            'resolution_notes' => $investigation['resolution_notes'],
            'created_by' => $investigation['created_by'],
            'assigned_to' => $investigation['assigned_to'],
        ];

        $riskSummary = $this->buildRiskSummary($recommendation, $linkedAlerts);

        $investigativeSynthesis = $this->buildInvestigativeSynthesis($workspace, $recommendation);

        $sanitizedEvidenceItems = collect($evidenceItems)->map(function (object $item): array {
            $row = (array) $item;
            if (! empty($row['metadata']) && is_array($row['metadata'])) {
                $row['metadata'] = $this->sanitizeMetadata($row['metadata']);
            }

            return $row;
        })->values()->all();

        // Activity timeline excludes event_metadata to avoid leaking internal tracking data.
        $timeline = collect($activityTimeline)->map(function (object $event): array {
            $row = (array) $event;
            unset($row['event_metadata']);

            return $row;
        })->values()->all();

        $notes = $workspace['investigation_notes']
            ? [['content' => $workspace['investigation_notes'], 'type' => 'investigation_notes']]
            : [];

        $this->investigationService->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_REPORT_GENERATED,
            actorType: $actorType,
            actorId: $actorId,
            eventSummary: 'Investigation report generated',
            eventMetadata: [
                'format' => 'json',
                'evidence_item_count' => count($sanitizedEvidenceItems),
                'activity_event_count' => count($timeline),
            ],
        );

        return [
            'report' => [
                'title' => $investigation['title'],
                'generated_at' => now()->toIso8601String(),
                'generated_by_user_id' => $actorId,
                'case_summary' => $caseSummary,
                'risk_summary' => $riskSummary,
                'investigative_synthesis' => $investigativeSynthesis,
                'evidence_items' => $sanitizedEvidenceItems,
                'activity_timeline' => $timeline,
                'notes' => $notes,
                'disclaimer' => self::DISCLAIMER,
            ],
        ];
    }

    private function buildRiskSummary(?array $recommendation, mixed $linkedAlerts): array
    {
        $alertCount = is_countable($linkedAlerts) ? count($linkedAlerts) : 0;

        if (! $recommendation) {
            return [
                'case_type' => null,
                'severity' => null,
                'source_risk_domains' => [],
                'confidence_score' => null,
                'linked_alert_count' => $alertCount,
            ];
        }

        return [
            'case_type' => $recommendation['case_type'],
            'severity' => $recommendation['severity'],
            'source_risk_domains' => $recommendation['source_risk_domains'] ?? [],
            'confidence_score' => $recommendation['confidence_score'],
            'linked_alert_count' => $alertCount,
        ];
    }

    private function buildInvestigativeSynthesis(array $workspace, ?array $recommendation): array
    {
        return [
            'investigation_status' => $workspace['investigation_status'],
            'investigation_priority' => $workspace['investigation_priority'],
            'investigation_summary' => $workspace['investigation_summary'],
            'last_activity_at' => $workspace['last_activity_at'],
            'assigned_user' => $workspace['assigned_user'],
            'recommendation_summary' => $recommendation ? $recommendation['summary'] : null,
            'recommendation_status' => $recommendation ? $recommendation['status'] : null,
        ];
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
}
