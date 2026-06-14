<?php

namespace App\Services;

use App\Models\EvidenceItem;
use App\Models\Finding;
use App\Models\Investigation;
use App\Models\SuggestedRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SourceFindingMaterializationService
{
    public function __construct(
        private readonly SourceFindingAdapterService $sourceFindingAdapter,
        private readonly InvestigationPlatformService $investigations,
    ) {}

    /**
     * Materialize current source analyzer projections into durable canonical
     * finding records. This is idempotent by source module/type/id.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function materializeFromSources(BusinessProfileContext $context, User $actor, array $filters = []): array
    {
        $this->assertTablesExist();

        $payload = $this->sourceFindingAdapter->list(
            companyId: $context->companyId,
            filters: $filters,
            businessProfileId: $context->businessProfileId,
        );

        return $this->materializePayload($context, $actor, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function materializePayload(
        BusinessProfileContext $context,
        User $actor,
        array $payload,
        ?string $investigationId = null,
    ): array {
        $this->assertTablesExist();
        $investigationId = $this->validatedInvestigationId($context, $investigationId);

        return DB::transaction(function () use ($context, $actor, $payload, $investigationId): array {
            $counts = [
                'findings' => 0,
                'findingsCreated' => 0,
                'findingsUpdated' => 0,
                'evidenceItems' => 0,
                'evidenceItemsCreated' => 0,
                'evidenceItemsUpdated' => 0,
                'suggestedRecords' => 0,
                'suggestedRecordsCreated' => 0,
                'suggestedRecordsUpdated' => 0,
            ];
            $materializedFindings = [];
            $topLevelEvidence = $this->listValue($payload['evidenceItems'] ?? []);
            $topLevelSuggestedRecords = $this->listValue($payload['suggestedRecords'] ?? []);

            foreach ($this->listValue($payload['findings'] ?? []) as $projectedFinding) {
                if (! is_array($projectedFinding)) {
                    continue;
                }

                $sourceFindingId = (string) ($projectedFinding['id'] ?? '');
                $finding = $this->upsertFinding($context, $projectedFinding, $investigationId, $counts);

                $evidenceItems = $this->listValue($projectedFinding['evidenceRefs'] ?? []);
                if ($evidenceItems === []) {
                    $evidenceItems = $this->itemsForFinding($topLevelEvidence, $sourceFindingId);
                }

                $evidenceRefs = [];
                foreach ($evidenceItems as $projectedEvidence) {
                    if (! is_array($projectedEvidence)) {
                        continue;
                    }
                    $evidence = $this->upsertEvidenceItem($context, $actor, $finding, $projectedEvidence, $counts);
                    $evidenceRefs[] = $this->evidenceReferencePayload($evidence);
                }

                $suggestedRecords = $this->listValue($projectedFinding['suggestedRecords'] ?? []);
                if ($suggestedRecords === []) {
                    $suggestedRecords = $this->itemsForFinding($topLevelSuggestedRecords, $sourceFindingId);
                }

                foreach ($suggestedRecords as $projectedRecord) {
                    if (! is_array($projectedRecord)) {
                        continue;
                    }
                    $this->upsertSuggestedRecord($context, $finding, $projectedRecord, $counts);
                }

                if ($evidenceRefs !== []) {
                    $finding->update(['evidence_refs' => $evidenceRefs]);
                }

                $counts['findings']++;
                $materializedFindings[] = $this->investigations->findingPayload(
                    $finding->fresh(['suggestedRecords']) ?? $finding
                );
            }

            return [
                'contractVersion' => InvestigationPlatformContractService::CONTRACT_VERSION,
                'materialized' => $counts,
                'findings' => $materializedFindings,
            ];
        });
    }

    public function attachFindingRecordsToInvestigation(
        BusinessProfileContext $context,
        Finding $finding,
        string $investigationId,
    ): void {
        $investigationId = $this->validatedInvestigationId($context, $investigationId);
        if (! $investigationId || ! Schema::hasTable('evidence_items') || ! Schema::hasTable('suggested_records')) {
            return;
        }

        EvidenceItem::query()
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->where('finding_id', $finding->id)
            ->whereNull('investigation_id')
            ->update(['investigation_id' => $investigationId, 'updated_at' => now()]);

        SuggestedRecord::query()
            ->where('company_id', $context->companyId)
            ->where('business_profile_id', $context->businessProfileId)
            ->where('finding_id', $finding->id)
            ->whereNull('investigation_id')
            ->update(['investigation_id' => $investigationId, 'updated_at' => now()]);

        $evidenceRefs = EvidenceItem::query()
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->where('finding_id', $finding->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (EvidenceItem $item): array => $this->evidenceReferencePayload($item))
            ->values()
            ->all();

        if ($evidenceRefs !== []) {
            $finding->update(['evidence_refs' => $evidenceRefs]);
        }
    }

    private function assertTablesExist(): void
    {
        foreach (['findings', 'evidence_items', 'suggested_records'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Canonical finding table is missing: {$table}", 503);
            }
        }
    }

    /** @param array<string, mixed> $projected */
    private function upsertFinding(
        BusinessProfileContext $context,
        array $projected,
        ?string $investigationId,
        array &$counts,
    ): Finding {
        $lookup = [
            'company_id' => $context->companyId,
            'business_profile_id' => $context->businessProfileId,
            'source_module' => (string) ($projected['sourceModule'] ?? $projected['source_module'] ?? 'unknown'),
            'source_record_type' => (string) ($projected['sourceRecordType'] ?? $projected['source_record_type'] ?? 'unknown'),
            'source_record_id' => (string) ($projected['sourceRecordId'] ?? $projected['source_record_id'] ?? $projected['id'] ?? ''),
        ];

        $finding = Finding::query()->where($lookup)->lockForUpdate()->first();
        $created = false;
        if (! $finding) {
            $finding = new Finding($lookup);
            $finding->status = $this->findingStatus((string) ($projected['status'] ?? Finding::STATUS_NEW));
            $finding->reviewer_status = (string) ($projected['reviewerStatus'] ?? $projected['reviewer_status'] ?? 'pending');
            $created = true;
        }

        if ($investigationId && ! $finding->investigation_id) {
            $finding->investigation_id = $investigationId;
        } elseif (! $finding->investigation_id && ! empty($projected['investigationId'])) {
            $finding->investigation_id = $this->validatedInvestigationId($context, (string) $projected['investigationId']);
        }

        $finding->fill([
            'category' => $this->category((string) ($projected['category'] ?? 'unsure')),
            'title' => (string) ($projected['title'] ?? 'Review finding'),
            'summary' => $this->nullableString($projected['summary'] ?? null),
            'detail' => $this->nullableString($projected['detail'] ?? null),
            'severity' => $this->severity((string) ($projected['severity'] ?? Finding::SEVERITY_WARNING)),
            'confidence' => $this->confidence($projected['confidence'] ?? null),
            'confidence_score' => $this->confidenceScore($projected['confidenceScore'] ?? $projected['confidence_score'] ?? null),
            'reason_code' => $this->nullableString($projected['reasonCode'] ?? $projected['reason_code'] ?? null),
            'recommended_action' => is_array($projected['recommendedAction'] ?? null)
                ? $projected['recommendedAction']
                : ($projected['recommended_action'] ?? null),
            'metadata' => $this->sanitizeMetadata(array_merge(
                $this->arrayValue($finding->metadata),
                $this->arrayValue($projected['metadata'] ?? []),
                [
                    'source_finding_id' => $projected['id'] ?? null,
                    'limitations' => $this->listValue($projected['limitations'] ?? []),
                    'materialized_from' => 'source_finding_adapter',
                    'contract_version' => InvestigationPlatformContractService::CONTRACT_VERSION,
                ],
            )),
        ]);
        $finding->save();

        $created ? $counts['findingsCreated']++ : $counts['findingsUpdated']++;

        return $finding;
    }

    /** @param array<string, mixed> $projected */
    private function upsertEvidenceItem(
        BusinessProfileContext $context,
        User $actor,
        Finding $finding,
        array $projected,
        array &$counts,
    ): EvidenceItem {
        $sourceType = $this->nullableString($projected['sourceType'] ?? $projected['source_type'] ?? null);
        $sourceId = $this->nullableString($projected['sourceId'] ?? $projected['source_id'] ?? null);
        $sourceRecordId = $this->nullableString($projected['sourceRecordId'] ?? $projected['source_record_id'] ?? null);

        $query = EvidenceItem::query()
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->where('finding_id', $finding->id)
            ->where('evidence_type', (string) ($projected['evidenceType'] ?? $projected['evidence_type'] ?? 'system_summary'));
        $this->whereNullable($query, 'source_type', $sourceType);
        $this->whereNullable($query, 'source_id', $sourceId);
        $this->whereNullable($query, 'source_record_id', $sourceRecordId);

        $evidence = $query->lockForUpdate()->first();
        $created = false;
        if (! $evidence) {
            $evidence = new EvidenceItem([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'finding_id' => $finding->id,
            ]);
            $created = true;
        }

        $actorType = (string) ($projected['addedByActorType'] ?? $projected['added_by_actor_type'] ?? EvidenceItem::ACTOR_SYSTEM);
        $evidence->fill([
            'investigation_id' => $finding->investigation_id,
            'evidence_type' => (string) ($projected['evidenceType'] ?? $projected['evidence_type'] ?? 'system_summary'),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_record_id' => $sourceRecordId,
            'title' => (string) ($projected['title'] ?? 'Evidence item'),
            'summary' => $this->nullableString($projected['summary'] ?? null),
            'citation_label' => $this->nullableString($projected['citationLabel'] ?? $projected['citation_label'] ?? null),
            'source_row_range' => $this->nullableString($projected['sourceRowRange'] ?? $projected['source_row_range'] ?? null),
            'file_name' => $this->nullableString($projected['fileName'] ?? $projected['file_name'] ?? null),
            'storage_key' => $this->nullableString($projected['storageKey'] ?? $projected['storage_key'] ?? null),
            'hash' => $this->nullableString($projected['hash'] ?? null),
            'added_by_actor_type' => in_array($actorType, [EvidenceItem::ACTOR_USER, EvidenceItem::ACTOR_SYSTEM, EvidenceItem::ACTOR_AGENT], true)
                ? $actorType
                : EvidenceItem::ACTOR_SYSTEM,
            'added_by_actor_id' => $projected['addedByActorId'] ?? $projected['added_by_actor_id'] ?? ($actorType === EvidenceItem::ACTOR_USER ? $actor->id : null),
            'metadata' => $this->sanitizeMetadata(array_merge($this->arrayValue($projected['metadata'] ?? []), [
                'source_evidence_id' => $projected['id'] ?? null,
                'source_finding_id' => $projected['findingId'] ?? $projected['finding_id'] ?? null,
                'materialized_from' => 'source_finding_adapter',
            ])),
        ]);
        $evidence->save();

        $evidence->findings()->syncWithoutDetaching([$finding->id => ['created_at' => now()]]);
        $created ? $counts['evidenceItemsCreated']++ : $counts['evidenceItemsUpdated']++;
        $counts['evidenceItems']++;

        return $evidence;
    }

    /** @param array<string, mixed> $projected */
    private function upsertSuggestedRecord(
        BusinessProfileContext $context,
        Finding $finding,
        array $projected,
        array &$counts,
    ): SuggestedRecord {
        $recordType = (string) ($projected['recordType'] ?? $projected['record_type'] ?? 'supporting_record');
        $label = (string) ($projected['label'] ?? 'Supporting record');

        $record = SuggestedRecord::query()
            ->where('company_id', $context->companyId)
            ->where('business_profile_id', $context->businessProfileId)
            ->where('finding_id', $finding->id)
            ->where('record_type', $recordType)
            ->where('label', $label)
            ->lockForUpdate()
            ->first();

        $created = false;
        if (! $record) {
            $record = new SuggestedRecord([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'finding_id' => $finding->id,
                'status' => $this->suggestedRecordStatus((string) ($projected['status'] ?? 'requested')),
            ]);
            $created = true;
        }

        $record->fill([
            'investigation_id' => $finding->investigation_id,
            'record_type' => $recordType,
            'label' => $label,
            'reason' => $this->nullableString($projected['reason'] ?? null),
            'priority' => $this->suggestedRecordPriority((string) ($projected['priority'] ?? 'recommended')),
            'satisfying_evidence_item_id' => $projected['satisfyingEvidenceItemId'] ?? $projected['satisfying_evidence_item_id'] ?? null,
            'metadata' => $this->sanitizeMetadata(array_merge($this->arrayValue($record->metadata), [
                'source_suggested_record_id' => $projected['id'] ?? null,
                'materialized_from' => 'source_finding_adapter',
            ])),
        ]);
        $record->save();

        $created ? $counts['suggestedRecordsCreated']++ : $counts['suggestedRecordsUpdated']++;
        $counts['suggestedRecords']++;

        return $record;
    }

    private function validatedInvestigationId(BusinessProfileContext $context, ?string $investigationId): ?string
    {
        if (! $investigationId) {
            return null;
        }

        $exists = Investigation::query()
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->where('id', $investigationId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException('Investigation not found', 404);
        }

        return $investigationId;
    }

    /** @param list<mixed> $items @return list<array<string, mixed>> */
    private function itemsForFinding(array $items, string $sourceFindingId): array
    {
        return array_values(array_filter($items, function (mixed $item) use ($sourceFindingId): bool {
            return is_array($item)
                && (string) ($item['findingId'] ?? $item['finding_id'] ?? '') === $sourceFindingId;
        }));
    }

    /** @return array<string, mixed> */
    private function evidenceReferencePayload(EvidenceItem $evidence): array
    {
        return [
            'id' => (string) $evidence->id,
            'investigationId' => $evidence->investigation_id,
            'findingId' => $evidence->finding_id,
            'evidenceType' => $evidence->evidence_type,
            'sourceType' => $evidence->source_type,
            'sourceId' => $evidence->source_id,
            'sourceRecordId' => $evidence->source_record_id,
            'title' => $evidence->title,
            'summary' => $evidence->summary,
            'citationLabel' => $evidence->citation_label,
            'sourceRowRange' => $evidence->source_row_range,
            'fileName' => $evidence->file_name,
            'storageKey' => $evidence->storage_key,
            'hash' => $evidence->hash,
        ];
    }

    private function category(string $category): string
    {
        return in_array($category, InvestigationPlatformContractService::INVESTIGATION_CATEGORIES, true) ? $category : 'unsure';
    }

    private function severity(string $severity): string
    {
        return in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning';
    }

    private function confidence(mixed $confidence): ?string
    {
        return in_array($confidence, ['low', 'medium', 'high'], true) ? $confidence : null;
    }

    private function confidenceScore(mixed $score): ?float
    {
        return is_numeric($score) ? max(0.0, min(1.0, (float) $score)) : null;
    }

    private function findingStatus(string $status): string
    {
        return in_array($status, InvestigationPlatformContractService::FINDING_STATUSES, true) ? $status : Finding::STATUS_NEW;
    }

    private function suggestedRecordPriority(string $priority): string
    {
        return in_array($priority, ['required', 'recommended', 'optional'], true) ? $priority : 'recommended';
    }

    private function suggestedRecordStatus(string $status): string
    {
        return in_array($status, InvestigationPlatformContractService::SUGGESTED_RECORD_STATUSES, true) ? $status : 'requested';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<mixed> */
    private function listValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function sanitizeMetadata(array $metadata): array
    {
        $blocked = [
            'evidence',
            'supporting_evidence',
            'raw_evidence',
            'transaction_details',
            'raw_payload',
            'payload',
            'review_note',
            'notice_text',
            'notice_text_encrypted',
            'raw_row',
            'raw_value',
        ];
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $sanitized;
    }

    private function whereNullable($query, string $column, ?string $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }
}
