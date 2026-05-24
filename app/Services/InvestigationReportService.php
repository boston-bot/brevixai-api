<?php

namespace App\Services;

use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationReportExport;
use App\Support\ProfessionalServicesDisclaimer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Exception;

class InvestigationReportService
{
    private const DISCLAIMER = ProfessionalServicesDisclaimer::TEXT;

    private const SENSITIVE_METADATA_KEYS = [
        'evidence',
        'supporting_evidence',
        'raw_evidence',
        'transaction_details',
        'raw_payload',
        'review_note',
        'payload',
    ];

    public function __construct(
        private readonly InvestigationService $investigationService,
    ) {}

    /**
     * Generate a structured JSON report payload for an investigation.
     * Only users (actorType = 'user') may generate reports. Agents are blocked.
     */
    public function generate(
        string $companyId,
        string $caseId,
        string $actorType,
        string $actorId,
    ): array {
        $this->assertNotAgent($actorType);

        ['sections' => $sections, 'evidenceCount' => $evidenceCount, 'eventCount' => $eventCount]
            = $this->buildReportSections($companyId, $caseId);

        $generatedAt = now();
        $reportHash = $this->hashSanitizedReportPayload($sections);

        $this->recordReportExport(
            caseId: $caseId,
            companyId: $companyId,
            actorType: $actorType,
            actorId: $actorId,
            format: InvestigationReportExport::FORMAT_JSON,
            filename: null,
            reportHash: $reportHash,
            generatedAt: $generatedAt,
            metadata: $this->buildExportMetadata($evidenceCount, $eventCount),
        );

        $this->recordReportActivity(
            $caseId,
            $companyId,
            $actorType,
            $actorId,
            InvestigationReportExport::FORMAT_JSON,
            $evidenceCount,
            $eventCount,
        );

        return [
            'report' => array_merge($sections, [
                'generated_at' => $generatedAt->toIso8601String(),
                'generated_by_user_id' => $actorId,
            ]),
        ];
    }

    /**
     * Generate a PDF report for an investigation and return the raw PDF bytes.
     * Only users (actorType = 'user') may generate reports. Agents are blocked.
     */
    public function generatePdf(
        string $companyId,
        string $caseId,
        string $actorType,
        string $actorId,
        ?string $filename = null,
    ): string {
        $this->assertNotAgent($actorType);

        ['sections' => $sections, 'evidenceCount' => $evidenceCount, 'eventCount' => $eventCount]
            = $this->buildReportSections($companyId, $caseId);

        $generatedAt = now();
        $reportPayload = array_merge($sections, [
            'generated_at' => $generatedAt->toIso8601String(),
            'generated_by_user_id' => $actorId,
        ]);

        $pdfBytes = Pdf::loadView('reports.investigation-pdf', ['report' => $reportPayload])->output();

        $this->recordReportExport(
            caseId: $caseId,
            companyId: $companyId,
            actorType: $actorType,
            actorId: $actorId,
            format: InvestigationReportExport::FORMAT_PDF,
            filename: $filename,
            reportHash: $this->hashSanitizedReportPayload($sections),
            generatedAt: $generatedAt,
            metadata: $this->buildExportMetadata($evidenceCount, $eventCount),
        );

        $this->recordReportActivity(
            $caseId,
            $companyId,
            $actorType,
            $actorId,
            InvestigationReportExport::FORMAT_PDF,
            $evidenceCount,
            $eventCount,
        );

        return $pdfBytes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hashSanitizedReportPayload(array $payload): string
    {
        $canonicalPayload = $this->canonicalizeForHash($payload);

        return hash('sha256', json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Build the sanitized report sections shared by both JSON and PDF generation.
     * Returns the sections array plus counts for activity metadata.
     *
     * @return array{sections: array, evidenceCount: int, eventCount: int}
     */
    private function buildReportSections(string $companyId, string $caseId): array
    {
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

        return [
            'sections' => [
                'title' => $investigation['title'],
                'case_summary' => $caseSummary,
                'risk_summary' => $riskSummary,
                'investigative_synthesis' => $investigativeSynthesis,
                'evidence_items' => $sanitizedEvidenceItems,
                'activity_timeline' => $timeline,
                'notes' => $notes,
                'disclaimer' => self::DISCLAIMER,
            ],
            'evidenceCount' => count($sanitizedEvidenceItems),
            'eventCount' => count($timeline),
        ];
    }

    private function recordReportActivity(
        string $caseId,
        string $companyId,
        string $actorType,
        string $actorId,
        string $format,
        int $evidenceCount,
        int $eventCount,
    ): void {
        $this->investigationService->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_REPORT_GENERATED,
            actorType: $actorType,
            actorId: $actorId,
            eventSummary: 'Investigation report generated',
            eventMetadata: [
                'format' => $format,
                'evidence_item_count' => $evidenceCount,
                'activity_event_count' => $eventCount,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordReportExport(
        string $caseId,
        string $companyId,
        string $actorType,
        string $actorId,
        string $format,
        ?string $filename,
        string $reportHash,
        CarbonInterface $generatedAt,
        ?array $metadata,
    ): InvestigationReportExport {
        $this->assertNotAgent($actorType);

        if ($actorType !== InvestigationActivityEvent::ACTOR_USER) {
            throw new Exception('Only users can create investigation report export records', 403);
        }

        return InvestigationReportExport::create([
            'audit_case_id' => $caseId,
            'company_id' => $companyId,
            'generated_by_user_id' => $actorId,
            'format' => $format,
            'filename' => $filename,
            'report_hash' => $reportHash,
            'generated_at' => $generatedAt,
            'metadata' => $metadata !== null ? $this->sanitizeMetadata($metadata) : null,
        ]);
    }

    private function assertNotAgent(string $actorType): void
    {
        if ($actorType === InvestigationActivityEvent::ACTOR_AGENT) {
            throw new Exception('Agents cannot generate investigation reports', 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExportMetadata(int $evidenceCount, int $eventCount): array
    {
        return [
            'evidence_item_count' => $evidenceCount,
            'activity_event_count' => $eventCount,
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

    private function canonicalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeForHash($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeForHash($item);
        }

        return $value;
    }
}
