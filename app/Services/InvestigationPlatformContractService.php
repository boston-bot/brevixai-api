<?php

namespace App\Services;

use App\Support\ProfessionalServicesDisclaimer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InvestigationPlatformContractService
{
    public const CONTRACT_VERSION = '2026-06-12';

    /** @var list<string> */
    public const INVESTIGATION_CATEGORIES = [
        'revenue',
        'expense',
        'payroll',
        'tax',
        'fraud',
        'reconciliation',
        'controls',
        'vendor_payments',
        'cash_flow',
        'unsure',
    ];

    /** @var list<string> */
    public const INVESTIGATION_STATUSES = [
        'open',
        'in_review',
        'waiting_on_records',
        'pending_reviewer_approval',
        'ready_for_package',
        'closed',
        'archived',
    ];

    /** @var list<string> */
    public const FINDING_STATUSES = [
        'new',
        'in_review',
        'needs_more_evidence',
        'reviewed',
        'dismissed',
        'escalated',
        'included_in_package',
    ];

    /** @var list<string> */
    public const FINDING_SOURCE_MODULES = [
        'case_recommendations',
        'alerts',
        'reconciliation',
        'transactions',
        'vendor_risk',
        'tax_notices',
        'upload_validation',
    ];

    /** @var list<string> */
    public const EVIDENCE_TYPES = [
        'transaction',
        'reconciliation_discrepancy',
        'tax_notice',
        'uploaded_file',
        'source_row',
        'bank_statement',
        'ledger_export',
        'invoice',
        'receipt',
        'payroll_record',
        'reviewer_note',
        'system_summary',
        'vendor',
        'alert',
        'recommendation',
        'document',
        'note',
    ];

    /** @var list<string> */
    public const SUGGESTED_RECORD_STATUSES = [
        'requested',
        'received',
        'waived',
        'not_available',
    ];

    /** @var list<string> */
    public const PACKAGE_FORMATS = [
        'json',
        'pdf',
    ];

    public function __construct(
        private readonly InvestigationService $investigationService,
    ) {}

    /**
     * Static vocabulary and response-shape contract for frontend/mobile/agent repos.
     *
     * @return array<string, mixed>
     */
    public function contract(): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'resources' => [
                'Investigation',
                'Finding',
                'EvidenceItem',
                'SuggestedRecord',
                'ReviewEvent',
                'CasePackage',
            ],
            'vocabulary' => [
                'investigationCategories' => self::INVESTIGATION_CATEGORIES,
                'investigationStatuses' => self::INVESTIGATION_STATUSES,
                'investigationPriorities' => ['critical', 'high', 'medium', 'low'],
                'findingStatuses' => self::FINDING_STATUSES,
                'findingSeverities' => ['info', 'warning', 'critical'],
                'findingConfidence' => ['low', 'medium', 'high'],
                'findingSourceModules' => self::FINDING_SOURCE_MODULES,
                'evidenceTypes' => self::EVIDENCE_TYPES,
                'suggestedRecordPriorities' => ['required', 'recommended', 'optional'],
                'suggestedRecordStatuses' => self::SUGGESTED_RECORD_STATUSES,
                'reviewActorTypes' => ['user', 'system', 'agent'],
                'packageFormats' => self::PACKAGE_FORMATS,
            ],
            'responseShapes' => [
                'investigation' => $this->investigationShape(),
                'finding' => $this->findingShape(),
                'evidenceItem' => $this->evidenceItemShape(),
                'suggestedRecord' => $this->suggestedRecordShape(),
                'reviewEvent' => $this->reviewEventShape(),
                'casePackage' => $this->casePackageShape(),
            ],
            'guardrails' => [
                'findingsAreConclusions' => false,
                'requiresHumanReviewForFinalJudgment' => true,
                'rexRole' => 'investigation_assistant',
                'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function investigationContract(string $companyId, string $investigationId): ?array
    {
        $detail = $this->investigationService->detail($companyId, $investigationId);
        if (! $detail) {
            return null;
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'investigation' => $this->investigation($detail),
            'findings' => $this->findingsFromDetail($detail),
            'evidenceItems' => $this->evidenceItemsFromDetail($detail),
            'suggestedRecords' => $this->suggestedRecordsFromDetail($detail),
            'reviewEvents' => $this->reviewEventsFromDetail($detail),
            'casePackages' => $this->casePackagesFromDetail($detail),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findings(string $companyId, string $investigationId): ?array
    {
        $detail = $this->investigationService->detail($companyId, $investigationId);
        if (! $detail) {
            return null;
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'investigationId' => $investigationId,
            'findings' => $this->findingsFromDetail($detail),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function suggestedRecords(string $companyId, string $investigationId): ?array
    {
        $detail = $this->investigationService->detail($companyId, $investigationId);
        if (! $detail) {
            return null;
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'investigationId' => $investigationId,
            'suggestedRecords' => $this->suggestedRecordsFromDetail($detail),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reviewEvents(string $companyId, string $investigationId): ?array
    {
        $detail = $this->investigationService->detail($companyId, $investigationId);
        if (! $detail) {
            return null;
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'investigationId' => $investigationId,
            'reviewEvents' => $this->reviewEventsFromDetail($detail),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function casePackages(string $companyId, string $investigationId): ?array
    {
        $detail = $this->investigationService->detail($companyId, $investigationId);
        if (! $detail) {
            return null;
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'investigationId' => $investigationId,
            'casePackages' => $this->casePackagesFromDetail($detail),
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function investigation(array $detail): array
    {
        $investigation = $detail['investigation'] ?? [];
        $workspace = $detail['workspace'] ?? [];
        $recommendation = is_array($detail['recommendation'] ?? null) ? $detail['recommendation'] : null;
        $metadata = is_array($workspace['investigation_metadata'] ?? null) ? $workspace['investigation_metadata'] : [];

        $category = $metadata['category'] ?? $this->categoryFromRecommendation($recommendation);
        $reviewPeriod = is_array($metadata['review_period'] ?? null) ? $metadata['review_period'] : null;

        return [
            'id' => (string) ($investigation['id'] ?? ''),
            'workspaceId' => (string) ($metadata['workspace_id'] ?? ''),
            'clientOrCompanyId' => (string) ($metadata['client_or_company_id'] ?? ''),
            'title' => (string) ($investigation['title'] ?? ''),
            'category' => $this->normalizeCategory((string) $category),
            'subcategory' => $recommendation['case_type'] ?? ($metadata['subcategory'] ?? null),
            'status' => $this->canonicalInvestigationStatus((string) ($workspace['investigation_status'] ?? 'open')),
            'priority' => $this->canonicalPriority((string) ($workspace['investigation_priority'] ?? 'medium')),
            'reviewPeriod' => [
                'startDate' => $reviewPeriod['startDate'] ?? $reviewPeriod['start_date'] ?? null,
                'endDate' => $reviewPeriod['endDate'] ?? $reviewPeriod['end_date'] ?? null,
                'label' => $reviewPeriod['label'] ?? null,
            ],
            'scopeStatement' => (string) ($workspace['investigation_summary'] ?? $investigation['description'] ?? ''),
            'scopeLimitations' => array_values(array_filter(
                is_array($metadata['scope_limitations'] ?? null) ? $metadata['scope_limitations'] : []
            )),
            'assignedTo' => $workspace['assigned_user'] ?? null,
            'createdBy' => $investigation['created_by'] ?? null,
            'openedAt' => $investigation['created_at'] ?? null,
            'lastActivityAt' => $workspace['last_activity_at'] ?? null,
            'closedAt' => $investigation['resolved_at'] ?? null,
            'sourceFindingIds' => array_values(array_map(
                fn (array $finding): string => $finding['id'],
                $this->findingsFromDetail($detail)
            )),
            'evidenceItemIds' => array_values(array_map(
                fn (array $item): string => $item['id'],
                $this->evidenceItemsFromDetail($detail)
            )),
            'reviewerNoteIds' => [],
            'packageIds' => array_values(array_map(
                fn (array $package): string => $package['id'],
                $this->casePackagesFromDetail($detail)
            )),
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function findingsFromDetail(array $detail): array
    {
        $findings = [];
        $investigationId = (string) (($detail['investigation']['id'] ?? '') ?: '');
        $recommendation = is_array($detail['recommendation'] ?? null) ? $detail['recommendation'] : null;
        $evidenceItems = $this->evidenceItemsFromDetail($detail);

        if ($recommendation) {
            $sourceRecordId = (string) ($recommendation['id'] ?? '');
            $category = $this->categoryFromRecommendation($recommendation);

            $findings[] = [
                'id' => "finding:case_recommendation:{$sourceRecordId}",
                'investigationId' => $investigationId,
                'category' => $category,
                'sourceModule' => 'case_recommendations',
                'sourceRecordType' => 'case_recommendation',
                'sourceRecordId' => $sourceRecordId,
                'title' => (string) ($recommendation['title'] ?? 'Investigation finding'),
                'summary' => (string) ($recommendation['summary'] ?? ''),
                'detail' => (string) ($recommendation['summary'] ?? ''),
                'severity' => $this->canonicalFindingSeverity((string) ($recommendation['severity'] ?? 'warning')),
                'confidence' => $this->confidenceLabel($recommendation['confidence_score'] ?? null),
                'reasonCode' => $recommendation['case_type'] ?? null,
                'status' => $this->findingStatusFromRecommendation((string) ($recommendation['status'] ?? 'pending_review')),
                'evidenceRefs' => $this->evidenceRefsForSource($evidenceItems, $sourceRecordId, 'recommendation'),
                'suggestedRecords' => [],
                'recommendedAction' => [
                    'key' => 'review_finding',
                    'label' => 'Review finding',
                    'explanation' => 'A reviewer should confirm the cited evidence, limitations, and next records before this finding is included in a package.',
                    'requiresConfirmation' => true,
                ],
                'reviewerStatus' => $this->reviewerStatusFromRecommendation((string) ($recommendation['status'] ?? 'pending_review')),
                'createdAt' => $detail['investigation']['created_at'] ?? null,
                'updatedAt' => $detail['investigation']['updated_at'] ?? null,
            ];
        }

        foreach ($this->collectionToArray($detail['linked_alerts'] ?? []) as $alert) {
            $sourceRecordId = (string) ($alert['id'] ?? '');
            if ($sourceRecordId === '') {
                continue;
            }

            $findings[] = [
                'id' => "finding:alert:{$sourceRecordId}",
                'investigationId' => $investigationId,
                'category' => $this->categoryFromReasonCode((string) ($alert['rule_key'] ?? '')),
                'sourceModule' => 'alerts',
                'sourceRecordType' => 'alert',
                'sourceRecordId' => $sourceRecordId,
                'title' => (string) ($alert['title'] ?? 'Linked alert finding'),
                'summary' => (string) ($alert['title'] ?? ''),
                'detail' => '',
                'severity' => $this->canonicalFindingSeverity((string) ($alert['severity'] ?? 'warning')),
                'confidence' => null,
                'reasonCode' => $alert['rule_key'] ?? null,
                'status' => $this->findingStatusFromAlert((string) ($alert['status'] ?? 'open')),
                'evidenceRefs' => $this->evidenceRefsForSource($evidenceItems, $sourceRecordId, 'alert'),
                'suggestedRecords' => [],
                'recommendedAction' => null,
                'reviewerStatus' => ((string) ($alert['status'] ?? 'open')) === 'dismissed' ? 'dismissed' : 'pending',
                'createdAt' => $alert['created_at'] ?? null,
                'updatedAt' => null,
            ];
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function evidenceItemsFromDetail(array $detail): array
    {
        $investigationId = (string) (($detail['investigation']['id'] ?? '') ?: '');

        return array_map(function (array $item) use ($investigationId): array {
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $sourceRowRange = $metadata['sourceRowRange']
                ?? $metadata['source_row_range']
                ?? (isset($metadata['source_row_number']) ? (string) $metadata['source_row_number'] : null);

            $evidenceReferenceId = $item['evidence_reference_id'] ?? null;

            return [
                'id' => (string) ($item['id'] ?? ''),
                'investigationId' => $investigationId,
                'findingId' => $metadata['finding_id'] ?? null,
                'evidenceType' => $this->canonicalEvidenceType((string) ($item['evidence_type'] ?? 'document')),
                'sourceType' => (string) ($metadata['source_type'] ?? $item['evidence_type'] ?? 'document'),
                'sourceId' => $metadata['source_id'] ?? null,
                'sourceRecordId' => $evidenceReferenceId ? (string) $evidenceReferenceId : null,
                'title' => (string) ($item['title'] ?? ''),
                'summary' => (string) ($item['summary'] ?? ''),
                'citationLabel' => (string) ($metadata['citation_label'] ?? $this->citationLabel($item)),
                'sourceRowRange' => $sourceRowRange,
                'fileName' => $metadata['file_name'] ?? null,
                'storageKey' => $metadata['storage_key'] ?? null,
                'hash' => $metadata['hash'] ?? $metadata['row_content_hash'] ?? null,
                'addedByActorType' => (string) ($item['added_by_actor_type'] ?? 'system'),
                'addedByActorId' => $item['added_by_actor_id'] ?? null,
                'createdAt' => $item['created_at'] ?? null,
                'metadata' => $metadata,
            ];
        }, $this->collectionToArray($detail['evidence_items'] ?? []));
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function suggestedRecordsFromDetail(array $detail): array
    {
        $workspace = $detail['workspace'] ?? [];
        $metadata = is_array($workspace['investigation_metadata'] ?? null) ? $workspace['investigation_metadata'] : [];
        $records = is_array($metadata['suggested_records'] ?? null) ? $metadata['suggested_records'] : [];
        $investigationId = (string) (($detail['investigation']['id'] ?? '') ?: '');

        return array_values(array_map(function (array $record) use ($investigationId): array {
            $id = (string) ($record['id'] ?? Str::uuid());

            return [
                'id' => $id,
                'investigationId' => $investigationId,
                'findingId' => $record['findingId'] ?? $record['finding_id'] ?? null,
                'recordType' => (string) ($record['recordType'] ?? $record['record_type'] ?? 'supporting_document'),
                'label' => (string) ($record['label'] ?? 'Supporting record'),
                'reason' => (string) ($record['reason'] ?? 'Additional evidence may clarify this finding.'),
                'priority' => $this->canonicalSuggestedRecordPriority((string) ($record['priority'] ?? 'recommended')),
                'status' => $this->canonicalSuggestedRecordStatus((string) ($record['status'] ?? 'requested')),
                'satisfyingEvidenceItemId' => $record['satisfyingEvidenceItemId'] ?? $record['satisfying_evidence_item_id'] ?? null,
            ];
        }, $records));
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function reviewEventsFromDetail(array $detail): array
    {
        $investigationId = (string) (($detail['investigation']['id'] ?? '') ?: '');

        return array_map(function (array $event) use ($investigationId): array {
            $metadata = is_array($event['event_metadata'] ?? null) ? $event['event_metadata'] : [];

            return [
                'id' => (string) ($event['id'] ?? ''),
                'investigationId' => $investigationId,
                'findingId' => $metadata['finding_id'] ?? null,
                'eventType' => (string) ($event['event_type'] ?? ''),
                'actorType' => (string) ($event['actor_type'] ?? 'system'),
                'actorId' => $event['actor_id'] ?? null,
                'previousStatus' => $metadata['previous_status'] ?? null,
                'nextStatus' => $metadata['next_status'] ?? $metadata['new_status'] ?? null,
                'note' => (string) ($event['event_summary'] ?? ''),
                'createdAt' => $event['created_at'] ?? null,
                'metadata' => $metadata,
            ];
        }, $this->collectionToArray($detail['activity_timeline'] ?? []));
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function casePackagesFromDetail(array $detail): array
    {
        $investigationId = (string) (($detail['investigation']['id'] ?? '') ?: '');

        return array_map(function (array $export) use ($investigationId): array {
            $metadata = is_array($export['metadata'] ?? null) ? $export['metadata'] : [];

            return [
                'id' => (string) ($export['id'] ?? ''),
                'investigationId' => $investigationId,
                'format' => $this->canonicalPackageFormat((string) ($export['format'] ?? 'json')),
                'title' => (string) ($export['filename'] ?? 'Investigation case package'),
                'generatedAt' => $export['generated_at'] ?? null,
                'generatedBy' => [
                    'id' => $export['generated_by_user_id'] ?? null,
                    'name' => $export['generated_by_user_name'] ?? null,
                ],
                'includedSections' => $metadata['included_sections'] ?? [
                    'scope_statement',
                    'limitations',
                    'findings',
                    'supporting_evidence',
                    'source_citations',
                    'suggested_records',
                    'reviewer_notes',
                    'activity_timeline',
                    'disclaimers',
                    'package_manifest',
                ],
                'includedCounts' => [
                    'findings' => $metadata['finding_count'] ?? null,
                    'evidenceItems' => $metadata['evidence_item_count'] ?? null,
                    'reviewEvents' => $metadata['activity_event_count'] ?? null,
                    'suggestedRecords' => $metadata['suggested_record_count'] ?? null,
                ],
                'packageHash' => $export['report_hash'] ?? null,
                'filename' => $export['filename'] ?? null,
                'storageKey' => $metadata['storage_key'] ?? null,
                'manifest' => $metadata['manifest'] ?? null,
            ];
        }, $this->collectionToArray($detail['report_exports'] ?? []));
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private function collectionToArray(mixed $items): array
    {
        if ($items instanceof Collection) {
            $items = $items->all();
        }

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $item): array => is_object($item) ? (array) $item : (is_array($item) ? $item : []),
            $items,
        ));
    }

    private function normalizeCategory(string $category): string
    {
        return in_array($category, self::INVESTIGATION_CATEGORIES, true) ? $category : 'unsure';
    }

    /**
     * @param array<string, mixed>|null $recommendation
     */
    private function categoryFromRecommendation(?array $recommendation): string
    {
        if (! $recommendation) {
            return 'unsure';
        }

        $caseType = (string) ($recommendation['case_type'] ?? '');
        $domains = is_array($recommendation['source_risk_domains'] ?? null)
            ? implode(' ', $recommendation['source_risk_domains'])
            : '';
        $text = strtolower($caseType.' '.$domains.' '.($recommendation['title'] ?? ''));

        return $this->categoryFromReasonCode($text);
    }

    private function categoryFromReasonCode(string $text): string
    {
        $text = strtolower($text);

        return match (true) {
            str_contains($text, 'tax') || str_contains($text, 'irs') || str_contains($text, 'notice') => 'tax',
            str_contains($text, 'reconciliation') || str_contains($text, 'ledger') || str_contains($text, 'bank') => 'reconciliation',
            str_contains($text, 'vendor') || str_contains($text, 'payment') => 'vendor_payments',
            str_contains($text, 'payroll') || str_contains($text, 'employee') => 'payroll',
            str_contains($text, 'fraud') || str_contains($text, 'suspicious') || str_contains($text, 'conflict') => 'fraud',
            str_contains($text, 'control') || str_contains($text, 'approval') => 'controls',
            str_contains($text, 'revenue') || str_contains($text, 'deposit') => 'revenue',
            str_contains($text, 'cash') => 'cash_flow',
            str_contains($text, 'expense') || str_contains($text, 'spend') => 'expense',
            default => 'unsure',
        };
    }

    private function canonicalInvestigationStatus(string $status): string
    {
        return match ($status) {
            'open' => 'open',
            'in_review', 'investigating' => 'in_review',
            'escalated' => 'pending_reviewer_approval',
            'resolved', 'closed' => 'closed',
            'archived' => 'archived',
            default => 'open',
        };
    }

    private function canonicalPriority(string $priority): string
    {
        return in_array($priority, ['critical', 'high', 'medium', 'low'], true) ? $priority : 'medium';
    }

    private function canonicalFindingSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'high', 'medium', 'warning' => 'warning',
            default => 'info',
        };
    }

    private function confidenceLabel(mixed $score): ?string
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

    private function findingStatusFromRecommendation(string $status): string
    {
        return match ($status) {
            'approved' => 'reviewed',
            'dismissed' => 'dismissed',
            'expired' => 'needs_more_evidence',
            default => 'new',
        };
    }

    private function reviewerStatusFromRecommendation(string $status): ?string
    {
        return match ($status) {
            'approved' => 'reviewed',
            'dismissed' => 'dismissed',
            default => 'pending',
        };
    }

    private function findingStatusFromAlert(string $status): string
    {
        return match ($status) {
            'resolved', 'reviewed' => 'reviewed',
            'dismissed' => 'dismissed',
            'escalated' => 'escalated',
            default => 'new',
        };
    }

    private function canonicalEvidenceType(string $type): string
    {
        return in_array($type, self::EVIDENCE_TYPES, true) ? $type : match ($type) {
            'system_finding' => 'system_summary',
            default => 'document',
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function citationLabel(array $item): string
    {
        $type = (string) ($item['evidence_type'] ?? 'evidence');
        $id = (string) ($item['evidence_reference_id'] ?? $item['id'] ?? '');

        return $id === '' ? $type : "{$type}:{$id}";
    }

    /**
     * @param list<array<string, mixed>> $evidenceItems
     * @return list<array<string, mixed>>
     */
    private function evidenceRefsForSource(array $evidenceItems, string $sourceRecordId, string $sourceType): array
    {
        return array_values(array_filter($evidenceItems, function (array $item) use ($sourceRecordId, $sourceType): bool {
            return ($item['sourceRecordId'] ?? null) === $sourceRecordId
                && ($item['evidenceType'] ?? null) === $sourceType;
        }));
    }

    private function canonicalSuggestedRecordPriority(string $priority): string
    {
        return in_array($priority, ['required', 'recommended', 'optional'], true) ? $priority : 'recommended';
    }

    private function canonicalSuggestedRecordStatus(string $status): string
    {
        return in_array($status, self::SUGGESTED_RECORD_STATUSES, true) ? $status : 'requested';
    }

    private function canonicalPackageFormat(string $format): string
    {
        return in_array($format, self::PACKAGE_FORMATS, true) ? $format : 'json';
    }

    /** @return array<string, string> */
    private function investigationShape(): array
    {
        return [
            'id' => 'string',
            'workspaceId' => 'string',
            'clientOrCompanyId' => 'string',
            'title' => 'string',
            'category' => 'InvestigationCategory',
            'subcategory' => 'string|null',
            'status' => 'InvestigationStatus',
            'priority' => 'critical|high|medium|low',
            'reviewPeriod' => 'object',
            'scopeStatement' => 'string',
            'scopeLimitations' => 'string[]',
            'assignedTo' => 'object|null',
            'createdBy' => 'string|null',
            'openedAt' => 'ISO8601|null',
            'lastActivityAt' => 'ISO8601|null',
            'closedAt' => 'ISO8601|null',
            'sourceFindingIds' => 'string[]',
            'evidenceItemIds' => 'string[]',
            'reviewerNoteIds' => 'string[]',
            'packageIds' => 'string[]',
        ];
    }

    /** @return array<string, string> */
    private function findingShape(): array
    {
        return [
            'id' => 'string',
            'investigationId' => 'string|null',
            'category' => 'InvestigationCategory',
            'sourceModule' => 'string',
            'sourceRecordType' => 'string',
            'sourceRecordId' => 'string',
            'title' => 'string',
            'summary' => 'string',
            'detail' => 'string',
            'severity' => 'info|warning|critical',
            'confidence' => 'low|medium|high|null',
            'reasonCode' => 'string|null',
            'status' => 'FindingStatus',
            'evidenceRefs' => 'EvidenceItem[]',
            'suggestedRecords' => 'SuggestedRecord[]',
            'recommendedAction' => 'RecommendedAction|null',
            'reviewerStatus' => 'pending|reviewed|dismissed|null',
            'createdAt' => 'ISO8601|null',
            'updatedAt' => 'ISO8601|null',
        ];
    }

    /** @return array<string, string> */
    private function evidenceItemShape(): array
    {
        return [
            'id' => 'string',
            'investigationId' => 'string|null',
            'findingId' => 'string|null',
            'evidenceType' => 'EvidenceType',
            'sourceType' => 'string',
            'sourceId' => 'string|null',
            'sourceRecordId' => 'string|null',
            'title' => 'string',
            'summary' => 'string',
            'citationLabel' => 'string|null',
            'sourceRowRange' => 'string|null',
            'fileName' => 'string|null',
            'storageKey' => 'string|null',
            'hash' => 'string|null',
            'addedByActorType' => 'user|system|agent',
            'addedByActorId' => 'string|null',
            'createdAt' => 'ISO8601|null',
            'metadata' => 'object|null',
        ];
    }

    /** @return array<string, string> */
    private function suggestedRecordShape(): array
    {
        return [
            'id' => 'string',
            'investigationId' => 'string|null',
            'findingId' => 'string|null',
            'recordType' => 'string',
            'label' => 'string',
            'reason' => 'string',
            'priority' => 'required|recommended|optional',
            'status' => 'requested|received|waived|not_available',
            'satisfyingEvidenceItemId' => 'string|null',
        ];
    }

    /** @return array<string, string> */
    private function reviewEventShape(): array
    {
        return [
            'id' => 'string',
            'investigationId' => 'string|null',
            'findingId' => 'string|null',
            'eventType' => 'string',
            'actorType' => 'user|system|agent',
            'actorId' => 'string|null',
            'previousStatus' => 'string|null',
            'nextStatus' => 'string|null',
            'note' => 'string|null',
            'createdAt' => 'ISO8601|null',
            'metadata' => 'object|null',
        ];
    }

    /** @return array<string, string> */
    private function casePackageShape(): array
    {
        return [
            'id' => 'string',
            'investigationId' => 'string',
            'format' => 'json|pdf',
            'title' => 'string',
            'generatedAt' => 'ISO8601|null',
            'generatedBy' => 'object|null',
            'includedSections' => 'string[]',
            'includedCounts' => 'object',
            'packageHash' => 'string|null',
            'filename' => 'string|null',
            'storageKey' => 'string|null',
            'manifest' => 'object|null',
        ];
    }
}
