<?php

namespace App\Services;

use App\Models\InvestigationActivityEvent;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvestigationPackageManifestService
{
    public const DISCLAIMER = 'This package manifest summarizes included investigation materials and review activity. It is not a legal conclusion or proof of fraud.';

    private const FORMAT_JSON = 'json';

    public function __construct(
        private readonly InvestigationService $investigationService,
    ) {}

    /**
     * Generate a non-persistent, sanitized manifest of investigation export materials.
     * Only user-triggered generation is allowed. Agents are blocked at the service boundary.
     */
    public function generate(
        string $companyId,
        string $caseId,
        string $actorType,
        string $actorId,
        string $format = self::FORMAT_JSON,
    ): array {
        $this->assertUserActor($actorType);

        if ($format !== self::FORMAT_JSON) {
            throw new Exception('Unsupported package manifest format', 422);
        }

        return DB::transaction(function () use ($companyId, $caseId, $actorType, $actorId): array {
            $case = $this->findCase($companyId, $caseId);
            if (! $case) {
                throw new Exception('Investigation not found', 404);
            }

            $generatedAt = now();

            $reportExports = $this->reportExportReferences($companyId, $caseId);
            $evidenceItems = $this->evidenceItemReferences($companyId, $caseId);
            $linkedAlerts = $this->linkedAlertReferences($companyId, $case);
            $linkedRecommendations = $this->linkedRecommendationReferences($companyId, $case);
            $notes = $this->noteReferences($case);

            $activityEventCount = $this->currentActivityEventCount($companyId, $caseId) + 1;

            $this->recordManifestActivity(
                caseId: $caseId,
                companyId: $companyId,
                actorType: $actorType,
                actorId: $actorId,
                reportExportCount: count($reportExports),
                evidenceItemCount: count($evidenceItems),
                linkedAlertCount: count($linkedAlerts),
                linkedRecommendationCount: count($linkedRecommendations),
                activityEventCount: $activityEventCount,
                noteCount: count($notes),
            );

            $activityEvents = $this->activityEventReferences($companyId, $caseId);

            $includedCounts = [
                'report_exports' => count($reportExports),
                'evidence_items' => count($evidenceItems),
                'linked_alerts' => count($linkedAlerts),
                'linked_recommendations' => count($linkedRecommendations),
                'activity_events' => count($activityEvents),
                'notes' => count($notes),
            ];

            return [
                'manifest' => [
                    'investigation_id' => $caseId,
                    'generated_at' => $generatedAt->toIso8601String(),
                    'generated_by_user_id' => $actorId,
                    'included_sections' => [
                        'report_exports',
                        'evidence_items',
                        'linked_alerts',
                        'linked_recommendations',
                        'activity_events',
                        'notes',
                        'disclaimer',
                    ],
                    'included_counts' => $includedCounts,
                    'report_exports' => $reportExports,
                    'evidence_items' => $evidenceItems,
                    'linked_alerts' => $linkedAlerts,
                    'linked_recommendations' => $linkedRecommendations,
                    'activity_events' => $activityEvents,
                    'notes' => $notes,
                    'disclaimer' => self::DISCLAIMER,
                ],
            ];
        });
    }

    private function assertUserActor(string $actorType): void
    {
        if ($actorType === InvestigationActivityEvent::ACTOR_AGENT) {
            throw new Exception('Agents cannot generate investigation package manifests', 403);
        }

        if ($actorType !== InvestigationActivityEvent::ACTOR_USER) {
            throw new Exception('Only users can generate investigation package manifests', 403);
        }
    }

    private function findCase(string $companyId, string $caseId): ?object
    {
        $query = DB::table('audit_cases')
            ->where('id', $caseId)
            ->where('company_id', $companyId)
            ->select(
                'id',
                'company_id',
                'case_recommendation_id',
                'investigation_notes',
                'updated_at',
            );

        if (Schema::hasColumn('audit_cases', 'alert_ids')) {
            $query->addSelect('alert_ids');
        }

        return $query->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reportExportReferences(string $companyId, string $caseId): array
    {
        return DB::table('investigation_report_exports as ire')
            ->leftJoin('users as generator', 'generator.id', '=', 'ire.generated_by_user_id')
            ->where('ire.audit_case_id', $caseId)
            ->where('ire.company_id', $companyId)
            ->select(
                'ire.id',
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
            ->map(function (object $export): array {
                $metadata = $this->decodeJsonObject($export->metadata);

                return [
                    'id' => $export->id,
                    'generated_by_user_id' => $export->generated_by_user_id,
                    'generated_by_user_name' => $export->generated_by_user_name,
                    'format' => $export->format,
                    'filename' => $export->filename,
                    'report_hash' => $export->report_hash,
                    'generated_at' => $this->timestamp($export->generated_at),
                    'evidence_item_count' => $this->nullableInt($metadata['evidence_item_count'] ?? null),
                    'activity_event_count' => $this->nullableInt($metadata['activity_event_count'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function evidenceItemReferences(string $companyId, string $caseId): array
    {
        return DB::table('investigation_evidence_items')
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
                'created_at',
            )
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (object $item): array => [
                'id' => $item->id,
                'evidence_type' => $item->evidence_type,
                'evidence_reference_id' => $item->evidence_reference_id,
                'title' => $item->title,
                'summary' => $item->summary,
                'source' => $item->source,
                'added_by_actor_type' => $item->added_by_actor_type,
                'added_by_actor_id' => $item->added_by_actor_id,
                'created_at' => $this->timestamp($item->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkedAlertReferences(string $companyId, object $case): array
    {
        $alertIds = $this->uuidList($case->alert_ids ?? null);
        if ($alertIds === []) {
            return [];
        }

        return DB::table('alerts')
            ->where('company_id', $companyId)
            ->whereIn('id', $alertIds)
            ->select(
                'id',
                'alert_recommendation_id',
                'rule_key',
                'severity',
                'title',
                'status',
                'created_at',
            )
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (object $alert): array => [
                'id' => $alert->id,
                'alert_recommendation_id' => $alert->alert_recommendation_id,
                'rule_key' => $alert->rule_key,
                'severity' => $alert->severity,
                'title' => $alert->title,
                'status' => $alert->status,
                'created_at' => $this->timestamp($alert->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkedRecommendationReferences(string $companyId, object $case): array
    {
        $recommendations = [];
        $relatedAlertRecommendationIds = [];

        if ($case->case_recommendation_id) {
            $caseRecommendation = DB::table('case_recommendations')
                ->where('id', $case->case_recommendation_id)
                ->where('company_id', $companyId)
                ->select(
                    'id',
                    'case_type',
                    'severity',
                    'title',
                    'summary',
                    'source_risk_domains',
                    'related_alert_recommendation_ids',
                    'confidence_score',
                    'status',
                    'reviewed_by_user_id',
                    'reviewed_at',
                    'created_at',
                    'updated_at',
                )
                ->first();

            if ($caseRecommendation) {
                $sourceRiskDomains = $this->decodeJsonList($caseRecommendation->source_risk_domains);
                $relatedAlertRecommendationIds = $this->uuidList($caseRecommendation->related_alert_recommendation_ids);

                $recommendations[] = [
                    'id' => $caseRecommendation->id,
                    'recommendation_type' => 'case',
                    'case_type' => $caseRecommendation->case_type,
                    'severity' => $caseRecommendation->severity,
                    'title' => $caseRecommendation->title,
                    'summary' => $caseRecommendation->summary,
                    'source_risk_domains' => $sourceRiskDomains,
                    'related_alert_recommendation_ids' => $relatedAlertRecommendationIds,
                    'confidence_score' => $caseRecommendation->confidence_score !== null
                        ? (float) $caseRecommendation->confidence_score
                        : null,
                    'status' => $caseRecommendation->status,
                    'reviewed_by_user_id' => $caseRecommendation->reviewed_by_user_id,
                    'reviewed_at' => $this->timestamp($caseRecommendation->reviewed_at),
                    'created_at' => $this->timestamp($caseRecommendation->created_at),
                    'updated_at' => $this->timestamp($caseRecommendation->updated_at),
                ];
            }
        }

        if ($relatedAlertRecommendationIds === []) {
            return $recommendations;
        }

        $alertRecommendations = DB::table('alert_recommendations')
            ->where('company_id', $companyId)
            ->whereIn('id', $relatedAlertRecommendationIds)
            ->select(
                'id',
                'source_risk_domain',
                'alert_type',
                'severity',
                'title',
                'summary',
                'confidence_score',
                'status',
                'reviewed_by_user_id',
                'reviewed_at',
                'created_at',
                'updated_at',
            )
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (object $recommendation): array => [
                'id' => $recommendation->id,
                'recommendation_type' => 'alert',
                'source_risk_domain' => $recommendation->source_risk_domain,
                'alert_type' => $recommendation->alert_type,
                'severity' => $recommendation->severity,
                'title' => $recommendation->title,
                'summary' => $recommendation->summary,
                'confidence_score' => $recommendation->confidence_score !== null
                    ? (float) $recommendation->confidence_score
                    : null,
                'status' => $recommendation->status,
                'reviewed_by_user_id' => $recommendation->reviewed_by_user_id,
                'reviewed_at' => $this->timestamp($recommendation->reviewed_at),
                'created_at' => $this->timestamp($recommendation->created_at),
                'updated_at' => $this->timestamp($recommendation->updated_at),
            ])
            ->values()
            ->all();

        return array_values(array_merge($recommendations, $alertRecommendations));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activityEventReferences(string $companyId, string $caseId): array
    {
        return DB::table('investigation_activity_events')
            ->where('audit_case_id', $caseId)
            ->where('company_id', $companyId)
            ->select(
                'id',
                'event_type',
                'actor_type',
                'actor_id',
                'event_summary',
                'created_at',
            )
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'event_summary' => $event->event_summary,
                'created_at' => $this->timestamp($event->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function noteReferences(object $case): array
    {
        $notes = trim((string) ($case->investigation_notes ?? ''));
        if ($notes === '') {
            return [];
        }

        return [
            [
                'type' => 'investigation_notes',
                'reference' => 'audit_cases.investigation_notes',
                'summary' => 'Investigation notes are included in the investigation materials.',
                'character_count' => strlen($notes),
                'updated_at' => $this->timestamp($case->updated_at ?? null),
            ],
        ];
    }

    private function currentActivityEventCount(string $companyId, string $caseId): int
    {
        return DB::table('investigation_activity_events')
            ->where('audit_case_id', $caseId)
            ->where('company_id', $companyId)
            ->count();
    }

    private function recordManifestActivity(
        string $caseId,
        string $companyId,
        string $actorType,
        string $actorId,
        int $reportExportCount,
        int $evidenceItemCount,
        int $linkedAlertCount,
        int $linkedRecommendationCount,
        int $activityEventCount,
        int $noteCount,
    ): void {
        $this->investigationService->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_PACKAGE_MANIFEST_GENERATED,
            actorType: $actorType,
            actorId: $actorId,
            eventSummary: 'Investigation package manifest generated',
            eventMetadata: [
                'format' => self::FORMAT_JSON,
                'report_export_count' => $reportExportCount,
                'evidence_item_count' => $evidenceItemCount,
                'linked_alert_count' => $linkedAlertCount,
                'linked_recommendation_count' => $linkedRecommendationCount,
                'activity_event_count' => $activityEventCount,
                'note_count' => $noteCount,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeJsonList(mixed $value): array
    {
        $decoded = $this->decodeJsonObject($value);

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, string>
     */
    private function uuidList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $value,
            )));
        }

        if (! is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '{}' || $trimmed === '[]') {
            return [];
        }

        if (str_starts_with($trimmed, '[')) {
            return array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $this->decodeJsonList($trimmed),
            )));
        }

        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $trimmed = substr($trimmed, 1, -1);
        }

        return array_values(array_filter(array_map(
            fn (string $item): string => trim($item, " \t\n\r\0\x0B\"'"),
            str_getcsv($trimmed),
        )));
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return (string) $value;
    }
}
