<?php

namespace App\Services;

use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use Exception;
use Illuminate\Support\Facades\DB;

class InvestigationEvidenceService
{
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

    public function list(string $companyId, string $caseId): array
    {
        $exists = DB::table('audit_cases')
            ->where('id', $caseId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $exists) {
            throw new Exception('Investigation not found', 404);
        }

        $items = DB::table('investigation_evidence_items')
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
                $item->metadata = $this->decodeAndSanitizeMetadata($item->metadata);

                return $item;
            });

        return ['evidence_items' => $items, 'total' => $items->count()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{evidence_item: InvestigationEvidenceItem}
     */
    public function add(
        string $companyId,
        string $actorType,
        ?string $actorId,
        string $caseId,
        array $data,
    ): array {
        if ($actorType === InvestigationEvidenceItem::ACTOR_AGENT) {
            throw new Exception('Agents cannot add evidence items', 403);
        }

        $exists = DB::table('audit_cases')
            ->where('id', $caseId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $exists) {
            throw new Exception('Investigation not found', 404);
        }

        $sanitizedMetadata = isset($data['metadata']) && is_array($data['metadata'])
            ? $this->sanitizeMetadata($data['metadata'])
            : null;

        $item = InvestigationEvidenceItem::create([
            'audit_case_id' => $caseId,
            'company_id' => $companyId,
            'evidence_type' => $data['evidence_type'],
            'evidence_reference_id' => $data['evidence_reference_id'] ?? null,
            'title' => $data['title'],
            'summary' => $data['summary'],
            'source' => $data['source'],
            'added_by_actor_type' => $actorType,
            'added_by_actor_id' => $actorId,
            'metadata' => $sanitizedMetadata,
        ]);

        $this->investigationService->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_EVIDENCE_LINKED,
            actorType: $actorType,
            actorId: $actorId,
            eventSummary: "Evidence added: {$data['title']}",
            eventMetadata: [
                'evidence_item_id' => $item->id,
                'evidence_type' => $data['evidence_type'],
                'evidence_reference_id' => $data['evidence_reference_id'] ?? null,
            ],
        );

        return ['evidence_item' => $item];
    }

    public function remove(
        string $companyId,
        string $actorType,
        ?string $actorId,
        string $caseId,
        string $evidenceItemId,
    ): void {
        if ($actorType === InvestigationEvidenceItem::ACTOR_AGENT) {
            throw new Exception('Agents cannot remove evidence items', 403);
        }

        $exists = DB::table('audit_cases')
            ->where('id', $caseId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $exists) {
            throw new Exception('Investigation not found', 404);
        }

        $item = InvestigationEvidenceItem::where('id', $evidenceItemId)
            ->where('audit_case_id', $caseId)
            ->where('company_id', $companyId)
            ->first();

        if (! $item) {
            throw new Exception('Evidence item not found', 404);
        }

        $itemTitle = $item->title;
        $itemType = $item->evidence_type;
        $item->delete();

        $this->investigationService->recordActivity(
            caseId: $caseId,
            companyId: $companyId,
            eventType: InvestigationActivityEvent::EVENT_EVIDENCE_REMOVED,
            actorType: $actorType,
            actorId: $actorId,
            eventSummary: "Evidence removed: {$itemTitle}",
            eventMetadata: [
                'evidence_item_id' => $evidenceItemId,
                'evidence_type' => $itemType,
            ],
        );
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

    private function decodeAndSanitizeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        if (is_array($metadata)) {
            return $this->sanitizeMetadata($metadata);
        }

        if (! is_string($metadata)) {
            return null;
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $this->sanitizeMetadata($decoded) : null;
    }
}
