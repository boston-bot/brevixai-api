<?php

namespace App\Services;

use App\Models\EvidenceItem;
use App\Models\Finding;
use App\Models\Investigation;
use App\Models\ReviewerNote;
use App\Models\ReviewEvent;
use App\Models\User;
use App\Support\ProfessionalServicesDisclaimer;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvestigationPlatformService
{
    public const DISCLAIMER = ProfessionalServicesDisclaimer::TEXT;

    /** @return array<string, mixed> */
    public function list(BusinessProfileContext $context, array $filters = []): array
    {
        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = $this->baseQuery($context)
            ->with(['assignee:id,first_name,last_name', 'creator:id,first_name,last_name'])
            ->withCount(['findings', 'evidenceItems', 'suggestedRecords', 'casePackages']);

        $statusFilter = $filters['status'] ?? $filters['investigation_status'] ?? null;
        $statuses = is_string($statusFilter) ? $this->statusesForFilter($statusFilter) : [];
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $priority = $filters['priority'] ?? $filters['investigation_priority'] ?? null;
        if (is_string($priority) && $priority !== '') {
            $query->where('priority', $this->normalizePriority($priority));
        }

        if (! empty($filters['category'])) {
            $query->where('category', $this->normalizeCategory((string) $filters['category']));
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', (string) $filters['assigned_to']);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (Investigation $investigation): array => $this->listPayload($investigation))
            ->values()
            ->all();

        $canonicalCounts = $this->baseQuery($context)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'investigations' => $rows,
            'total' => $total,
            'counts' => $this->frontendCounts($canonicalCounts),
            'status_counts' => $canonicalCounts,
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(BusinessProfileContext $context, User $actor, array $data): Investigation
    {
        $assigneeId = $data['assignedTo'] ?? $data['assigned_to'] ?? null;
        if ($assigneeId !== null) {
            $this->assertUserInWorkspace($context->companyId, (string) $assigneeId);
        }

        return DB::transaction(function () use ($context, $actor, $data, $assigneeId): Investigation {
            $investigation = Investigation::create([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'title' => (string) $data['title'],
                'category' => $this->normalizeCategory((string) ($data['category'] ?? Investigation::CATEGORY_UNSURE)),
                'subcategory' => $data['subcategory'] ?? null,
                'status' => $this->normalizeStatus((string) ($data['status'] ?? Investigation::STATUS_OPEN)),
                'priority' => $this->normalizePriority((string) ($data['priority'] ?? Investigation::PRIORITY_MEDIUM)),
                'review_period_start' => $data['reviewPeriodStart'] ?? $data['review_period_start'] ?? null,
                'review_period_end' => $data['reviewPeriodEnd'] ?? $data['review_period_end'] ?? null,
                'scope_statement' => $data['scopeStatement'] ?? $data['scope_statement'] ?? null,
                'scope_limitations' => $data['scopeLimitations'] ?? $data['scope_limitations'] ?? [],
                'assigned_to' => $assigneeId,
                'created_by' => $actor->id,
                'opened_at' => now(),
                'last_activity_at' => now(),
                'metadata' => $this->safeArray($data['metadata'] ?? []),
            ]);

            $this->recordEvent(
                investigation: $investigation,
                eventType: 'investigation_created',
                actorType: ReviewEvent::ACTOR_USER,
                actorId: (string) $actor->id,
                note: 'Investigation created',
                metadata: ['source' => 'api'],
            );

            return $investigation->fresh(['assignee', 'creator']);
        });
    }

    /** @return array<string, mixed>|null */
    public function detail(BusinessProfileContext $context, string $id): ?array
    {
        $investigation = $this->baseQuery($context)
            ->with([
                'assignee:id,first_name,last_name',
                'creator:id,first_name,last_name',
                'findings.suggestedRecords',
                'evidenceItems',
                'suggestedRecords',
                'reviewerNotes',
                'reviewEvents',
                'casePackages',
            ])
            ->withCount(['findings', 'evidenceItems', 'suggestedRecords', 'casePackages'])
            ->where('id', $id)
            ->first();

        if (! $investigation) {
            return null;
        }

        $events = $investigation->reviewEvents
            ->map(fn (ReviewEvent $event): array => $this->eventPayload($event))
            ->values()
            ->all();
        $notes = $investigation->reviewerNotes
            ->map(fn (ReviewerNote $note): array => $this->notePayload($note))
            ->values()
            ->all();
        $findings = $investigation->findings
            ->map(fn (Finding $finding): array => $this->findingPayload($finding))
            ->values()
            ->all();
        $evidence = $investigation->evidenceItems
            ->map(fn (EvidenceItem $item): array => $this->evidencePayload($item))
            ->values()
            ->all();

        $casePackages = $investigation->casePackages->map(fn ($package): array => $this->casePackagePayload($package))->values()->all();

        $payload = array_merge($this->detailPayload($investigation), [
            'activity' => $events,
            'activity_timeline' => $events,
            'findings' => $findings,
            'linked_alerts' => [],
            'linked_recommendations' => [],
            'evidence_items' => $evidence,
            'evidence_summary' => count($evidence).' evidence item'.(count($evidence) === 1 ? '' : 's'),
            'suggested_records' => $investigation->suggestedRecords->map(fn ($record): array => $this->suggestedRecordPayload($record))->values()->all(),
            'notes' => $notes,
            'case_packages' => $casePackages,
            'casePackages' => $casePackages,
            'disclaimer' => self::DISCLAIMER,
        ]);

        return [
            'investigation' => $payload,
            'workspace' => [
                'investigation_status' => $investigation->status,
                'investigation_priority' => $investigation->priority,
                'investigation_summary' => $investigation->scope_statement,
                'investigation_notes' => $notes[0]['body'] ?? null,
                'last_activity_at' => $this->timestamp($investigation->last_activity_at),
                'assigned_user' => $investigation->assigned_to ? [
                    'id' => $investigation->assigned_to,
                    'name' => $this->userName($investigation->assignee),
                ] : null,
            ],
            'findings' => $findings,
            'evidence_items' => $evidence,
            'activity_timeline' => $events,
            'reviewer_notes' => $notes,
            'case_packages' => $payload['case_packages'],
            'casePackages' => $payload['casePackages'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(BusinessProfileContext $context, User $actor, string $id, array $data): ?Investigation
    {
        $investigation = $this->baseQuery($context)->where('id', $id)->first();
        if (! $investigation) {
            return null;
        }

        $updates = [];
        foreach ([
            'title', 'subcategory', 'scope_statement', 'review_period_start', 'review_period_end',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('scopeStatement', $data)) {
            $updates['scope_statement'] = $data['scopeStatement'];
        }
        if (array_key_exists('reviewPeriodStart', $data)) {
            $updates['review_period_start'] = $data['reviewPeriodStart'];
        }
        if (array_key_exists('reviewPeriodEnd', $data)) {
            $updates['review_period_end'] = $data['reviewPeriodEnd'];
        }
        if (array_key_exists('category', $data)) {
            $updates['category'] = $this->normalizeCategory((string) $data['category']);
        }
        if (array_key_exists('priority', $data)) {
            $updates['priority'] = $this->normalizePriority((string) $data['priority']);
        }
        if (array_key_exists('status', $data)) {
            $updates['status'] = $this->normalizeStatus((string) $data['status']);
        }
        if (array_key_exists('scopeLimitations', $data)) {
            $updates['scope_limitations'] = $this->safeArray($data['scopeLimitations']);
        }
        if (array_key_exists('scope_limitations', $data)) {
            $updates['scope_limitations'] = $this->safeArray($data['scope_limitations']);
        }

        $assigneeId = $data['assignedTo'] ?? $data['assigned_to'] ?? null;
        if ($assigneeId !== null) {
            $this->assertUserInWorkspace($context->companyId, (string) $assigneeId);
            $updates['assigned_to'] = $assigneeId;
        }

        if ($updates === []) {
            return $investigation->fresh();
        }

        $previousStatus = $investigation->status;
        $updates['last_activity_at'] = now();
        if (($updates['status'] ?? null) === Investigation::STATUS_CLOSED && $investigation->closed_at === null) {
            $updates['closed_at'] = now();
        }

        $investigation->update($updates);
        $this->recordEvent(
            investigation: $investigation->fresh(),
            eventType: isset($updates['status']) && $updates['status'] !== $previousStatus ? 'status_changed' : 'investigation_updated',
            actorType: ReviewEvent::ACTOR_USER,
            actorId: (string) $actor->id,
            previousStatus: $previousStatus,
            nextStatus: $updates['status'] ?? null,
            note: 'Investigation updated',
        );

        return $investigation->fresh(['assignee', 'creator']);
    }

    public function updateStatus(BusinessProfileContext $context, User $actor, string $id, string $status): ?Investigation
    {
        return $this->update($context, $actor, $id, ['status' => $status]);
    }

    /** @return array<string, mixed> */
    public function investigationPayload(BusinessProfileContext $context, Investigation $investigation): array
    {
        $detail = $this->detail($context, (string) $investigation->id);

        return $detail['investigation'] ?? $this->detailPayload($investigation);
    }

    public function addNote(BusinessProfileContext $context, User $actor, string $id, string $body, ?string $findingId = null): ?ReviewerNote
    {
        $investigation = $this->baseQuery($context)->where('id', $id)->first();
        if (! $investigation) {
            return null;
        }

        if ($findingId !== null && ! Finding::where('id', $findingId)
            ->where('company_id', $context->companyId)
            ->where('investigation_id', $id)
            ->exists()) {
            throw new Exception('Finding not found', 404);
        }

        return DB::transaction(function () use ($context, $actor, $investigation, $body, $findingId): ReviewerNote {
            $note = ReviewerNote::create([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'investigation_id' => $investigation->id,
                'finding_id' => $findingId,
                'author_id' => $actor->id,
                'author_name' => $this->userName($actor),
                'body' => $body,
                'visibility' => 'internal',
            ]);

            $this->recordEvent(
                investigation: $investigation,
                findingId: $findingId,
                eventType: 'note_added',
                actorType: ReviewEvent::ACTOR_USER,
                actorId: (string) $actor->id,
                note: 'Reviewer note added',
                metadata: ['reviewer_note_id' => $note->id],
            );

            return $note->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function listEvidence(BusinessProfileContext $context, string $id): array
    {
        $investigation = $this->baseQuery($context)->where('id', $id)->first();
        if (! $investigation) {
            throw new Exception('Investigation not found', 404);
        }

        $items = EvidenceItem::where('investigation_id', $id)
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (EvidenceItem $item): array => $this->evidencePayload($item))
            ->values()
            ->all();

        return ['evidence_items' => $items, 'total' => count($items)];
    }

    /** @param array<string, mixed> $data */
    public function addEvidence(BusinessProfileContext $context, User $actor, string $id, array $data): EvidenceItem
    {
        $investigation = $this->baseQuery($context)->where('id', $id)->first();
        if (! $investigation) {
            throw new Exception('Investigation not found', 404);
        }

        return DB::transaction(function () use ($context, $actor, $investigation, $data): EvidenceItem {
            $item = EvidenceItem::create([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'investigation_id' => $investigation->id,
                'finding_id' => $data['finding_id'] ?? $data['findingId'] ?? null,
                'evidence_type' => (string) $data['evidence_type'],
                'source_type' => $data['source_type'] ?? $data['sourceType'] ?? $data['source'] ?? null,
                'source_id' => $data['source_id'] ?? $data['sourceId'] ?? null,
                'source_record_id' => $data['source_record_id'] ?? $data['sourceRecordId'] ?? $data['evidence_reference_id'] ?? null,
                'title' => (string) $data['title'],
                'summary' => $data['summary'] ?? null,
                'citation_label' => $data['citation_label'] ?? $data['citationLabel'] ?? null,
                'source_row_range' => $data['source_row_range'] ?? $data['sourceRowRange'] ?? null,
                'file_name' => $data['file_name'] ?? $data['fileName'] ?? null,
                'storage_key' => $data['storage_key'] ?? $data['storageKey'] ?? null,
                'hash' => $data['hash'] ?? null,
                'added_by_actor_type' => EvidenceItem::ACTOR_USER,
                'added_by_actor_id' => $actor->id,
                'metadata' => $this->sanitizeMetadata($this->safeArray($data['metadata'] ?? [])),
            ]);

            $this->recordEvent(
                investigation: $investigation,
                findingId: $item->finding_id,
                eventType: 'evidence_linked',
                actorType: ReviewEvent::ACTOR_USER,
                actorId: (string) $actor->id,
                note: 'Evidence added: '.$item->title,
                metadata: ['evidence_item_id' => $item->id, 'evidence_type' => $item->evidence_type],
            );

            return $item->fresh();
        });
    }

    public function removeEvidence(BusinessProfileContext $context, User $actor, string $id, string $evidenceItemId): bool
    {
        $item = EvidenceItem::where('id', $evidenceItemId)
            ->where('investigation_id', $id)
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->first();

        if (! $item) {
            return false;
        }

        $investigation = $item->investigation;
        $title = $item->title;
        $item->delete();

        if ($investigation) {
            $this->recordEvent(
                investigation: $investigation,
                eventType: 'evidence_removed',
                actorType: ReviewEvent::ACTOR_USER,
                actorId: (string) $actor->id,
                note: 'Evidence removed: '.$title,
                metadata: ['evidence_item_id' => $evidenceItemId],
            );
        }

        return true;
    }

    public function recordEvent(
        Investigation $investigation,
        string $eventType,
        string $actorType,
        ?string $actorId,
        ?string $findingId = null,
        ?string $previousStatus = null,
        ?string $nextStatus = null,
        ?string $note = null,
        array $metadata = [],
    ): ReviewEvent {
        $event = ReviewEvent::create([
            'company_id' => $investigation->company_id,
            'business_profile_id' => $investigation->business_profile_id,
            'investigation_id' => $investigation->id,
            'finding_id' => $findingId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'previous_status' => $previousStatus,
            'next_status' => $nextStatus,
            'note' => $note,
            'metadata' => $this->sanitizeMetadata($metadata),
            'created_at' => now(),
        ]);

        $investigation->forceFill(['last_activity_at' => now()])->save();

        return $event;
    }

    public function normalizeStatus(string $status): string
    {
        return match ($status) {
            'investigating', 'in_progress' => Investigation::STATUS_IN_REVIEW,
            'pending_review' => Investigation::STATUS_PENDING_REVIEWER_APPROVAL,
            'resolved' => Investigation::STATUS_CLOSED,
            default => in_array($status, [
                Investigation::STATUS_OPEN,
                Investigation::STATUS_IN_REVIEW,
                Investigation::STATUS_WAITING_ON_RECORDS,
                Investigation::STATUS_PENDING_REVIEWER_APPROVAL,
                Investigation::STATUS_READY_FOR_PACKAGE,
                Investigation::STATUS_CLOSED,
                Investigation::STATUS_ARCHIVED,
            ], true) ? $status : Investigation::STATUS_OPEN,
        };
    }

    public function normalizeCategory(string $category): string
    {
        $category = str_replace('-', '_', strtolower(trim($category)));

        return match ($category) {
            'vendor', 'vendor_risk', 'payments', 'vendor_payment' => Investigation::CATEGORY_VENDOR_PAYMENTS,
            'irs', 'tax_notice', 'tax_notices' => Investigation::CATEGORY_TAX,
            'recon' => Investigation::CATEGORY_RECONCILIATION,
            default => in_array($category, [
                Investigation::CATEGORY_REVENUE,
                Investigation::CATEGORY_EXPENSE,
                Investigation::CATEGORY_PAYROLL,
                Investigation::CATEGORY_TAX,
                Investigation::CATEGORY_FRAUD,
                Investigation::CATEGORY_RECONCILIATION,
                Investigation::CATEGORY_CONTROLS,
                Investigation::CATEGORY_VENDOR_PAYMENTS,
                Investigation::CATEGORY_CASH_FLOW,
                Investigation::CATEGORY_UNSURE,
            ], true) ? $category : Investigation::CATEGORY_UNSURE,
        };
    }

    public function normalizePriority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        if ($priority === 'warning') {
            return Investigation::PRIORITY_MEDIUM;
        }
        if ($priority === 'info') {
            return Investigation::PRIORITY_LOW;
        }

        return in_array($priority, [
            Investigation::PRIORITY_CRITICAL,
            Investigation::PRIORITY_HIGH,
            Investigation::PRIORITY_MEDIUM,
            Investigation::PRIORITY_LOW,
        ], true) ? $priority : Investigation::PRIORITY_MEDIUM;
    }

    public function frontendStatus(string $status): string
    {
        return match ($status) {
            Investigation::STATUS_IN_REVIEW => 'in_progress',
            Investigation::STATUS_WAITING_ON_RECORDS,
            Investigation::STATUS_PENDING_REVIEWER_APPROVAL,
            Investigation::STATUS_READY_FOR_PACKAGE => 'pending_review',
            Investigation::STATUS_CLOSED,
            Investigation::STATUS_ARCHIVED => 'closed',
            default => 'open',
        };
    }

    /** @return Builder<Investigation> */
    private function baseQuery(BusinessProfileContext $context): Builder
    {
        return Investigation::query()
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId);
    }

    /** @return list<string> */
    private function statusesForFilter(string $status): array
    {
        return match ($status) {
            'all' => [],
            'in_progress', 'investigating' => [Investigation::STATUS_IN_REVIEW],
            'pending_review' => [
                Investigation::STATUS_WAITING_ON_RECORDS,
                Investigation::STATUS_PENDING_REVIEWER_APPROVAL,
                Investigation::STATUS_READY_FOR_PACKAGE,
            ],
            'closed', 'resolved' => [Investigation::STATUS_CLOSED, Investigation::STATUS_ARCHIVED],
            default => [$this->normalizeStatus($status)],
        };
    }

    /** @param array<string, int> $canonicalCounts */
    private function frontendCounts(array $canonicalCounts): array
    {
        $counts = ['open' => 0, 'in_progress' => 0, 'pending_review' => 0, 'closed' => 0];
        foreach ($canonicalCounts as $status => $count) {
            $key = $this->frontendStatus((string) $status);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $count;
        }

        return $counts;
    }

    private function assertUserInWorkspace(string $companyId, string $userId): void
    {
        $query = User::where('id', $userId);

        if (Schema::hasTable('workspace_memberships')) {
            $query->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereExists(function ($subquery) use ($companyId): void {
                        $subquery->selectRaw('1')
                            ->from('workspace_memberships')
                            ->whereColumn('workspace_memberships.user_id', 'users.id')
                            ->where('workspace_memberships.company_id', $companyId);
                    });
            });
        } else {
            $query->where('company_id', $companyId);
        }

        $exists = $query->exists();

        if (! $exists) {
            throw new Exception('Assignee not found or is not a member of this workspace', 422);
        }
    }

    /** @return array<string, mixed> */
    private function listPayload(Investigation $investigation): array
    {
        return [
            'id' => (string) $investigation->id,
            'case_id' => $investigation->legacy_audit_case_id,
            'workspaceId' => (string) $investigation->company_id,
            'clientOrCompanyId' => $investigation->business_profile_id,
            'title' => (string) $investigation->title,
            'category' => (string) $investigation->category,
            'subcategory' => $investigation->subcategory,
            'status' => $this->frontendStatus((string) $investigation->status),
            'investigation_status' => (string) $investigation->status,
            'priority' => (string) $investigation->priority,
            'investigation_priority' => (string) $investigation->priority,
            'summary' => $investigation->scope_statement,
            'scopeStatement' => $investigation->scope_statement,
            'scopeLimitations' => $investigation->scope_limitations ?? [],
            'assigned_to_name' => $this->userName($investigation->assignee),
            'assigned_to_id' => $investigation->assigned_to,
            'assignedTo' => $investigation->assigned_to,
            'createdBy' => $investigation->created_by,
            'openedAt' => $this->timestamp($investigation->opened_at),
            'closedAt' => $this->timestamp($investigation->closed_at),
            'last_activity_at' => $this->timestamp($investigation->last_activity_at),
            'lastActivityAt' => $this->timestamp($investigation->last_activity_at),
            'created_at' => $this->timestamp($investigation->created_at),
            'updated_at' => $this->timestamp($investigation->updated_at),
            'finding_count' => (int) ($investigation->findings_count ?? 0),
            'evidence_count' => (int) ($investigation->evidence_items_count ?? 0),
            'missing_record_count' => (int) ($investigation->suggested_records_count ?? 0),
            'package_count' => (int) ($investigation->case_packages_count ?? 0),
            'alert_count' => 0,
            'recommendation_count' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(Investigation $investigation): array
    {
        return array_merge($this->listPayload($investigation), [
            'reviewPeriod' => [
                'start' => $investigation->review_period_start?->toDateString(),
                'end' => $investigation->review_period_end?->toDateString(),
            ],
            'metadata' => $this->sanitizeMetadata($investigation->metadata ?? []),
        ]);
    }

    /** @return array<string, mixed> */
    public function findingPayload(Finding $finding): array
    {
        $suggestedRecords = $finding->relationLoaded('suggestedRecords')
            ? $finding->suggestedRecords->map(fn ($record): array => $this->suggestedRecordPayload($record))->values()->all()
            : [];

        return [
            'id' => (string) $finding->id,
            'investigationId' => $finding->investigation_id,
            'investigation_id' => $finding->investigation_id,
            'category' => (string) $finding->category,
            'sourceModule' => (string) $finding->source_module,
            'source_module' => (string) $finding->source_module,
            'sourceRecordType' => (string) $finding->source_record_type,
            'source_record_type' => (string) $finding->source_record_type,
            'sourceRecordId' => (string) $finding->source_record_id,
            'source_record_id' => (string) $finding->source_record_id,
            'title' => (string) $finding->title,
            'summary' => $finding->summary,
            'detail' => $finding->detail,
            'severity' => (string) $finding->severity,
            'confidence' => $finding->confidence,
            'confidenceScore' => $finding->confidence_score,
            'reasonCode' => $finding->reason_code,
            'reason_code' => $finding->reason_code,
            'status' => (string) $finding->status,
            'evidenceRefs' => $finding->evidence_refs ?? [],
            'evidence_refs' => $finding->evidence_refs ?? [],
            'suggestedRecords' => $suggestedRecords,
            'recommendedAction' => $finding->recommended_action,
            'recommended_action' => $finding->recommended_action,
            'reviewerStatus' => $finding->reviewer_status,
            'reviewer_status' => $finding->reviewer_status,
            'createdAt' => $this->timestamp($finding->created_at),
            'updatedAt' => $this->timestamp($finding->updated_at),
            'created_at' => $this->timestamp($finding->created_at),
            'updated_at' => $this->timestamp($finding->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function evidencePayload(EvidenceItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'investigationId' => $item->investigation_id,
            'findingId' => $item->finding_id,
            'evidence_type' => (string) $item->evidence_type,
            'evidenceType' => (string) $item->evidence_type,
            'source_type' => $item->source_type,
            'sourceType' => $item->source_type,
            'source_id' => $item->source_id,
            'sourceId' => $item->source_id,
            'source_record_id' => $item->source_record_id,
            'sourceRecordId' => $item->source_record_id,
            'evidence_reference_id' => $item->source_record_id,
            'title' => (string) $item->title,
            'summary' => $item->summary,
            'citation_label' => $item->citation_label,
            'citationLabel' => $item->citation_label,
            'source_row_range' => $item->source_row_range,
            'sourceRowRange' => $item->source_row_range,
            'file_name' => $item->file_name,
            'fileName' => $item->file_name,
            'storage_key' => $item->storage_key,
            'storageKey' => $item->storage_key,
            'hash' => $item->hash,
            'source' => $item->source_type,
            'added_by_actor_type' => $item->added_by_actor_type,
            'addedByActorType' => $item->added_by_actor_type,
            'added_by_actor_id' => $item->added_by_actor_id,
            'addedByActorId' => $item->added_by_actor_id,
            'metadata' => $this->sanitizeMetadata($item->metadata ?? []),
            'created_at' => $this->timestamp($item->created_at),
            'createdAt' => $this->timestamp($item->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private function suggestedRecordPayload($record): array
    {
        return [
            'id' => (string) $record->id,
            'recordType' => (string) $record->record_type,
            'record_type' => (string) $record->record_type,
            'label' => (string) $record->label,
            'reason' => $record->reason,
            'priority' => (string) $record->priority,
            'status' => (string) $record->status,
            'satisfyingEvidenceItemId' => $record->satisfying_evidence_item_id,
        ];
    }

    /** @return array<string, mixed> */
    private function notePayload(ReviewerNote $note): array
    {
        return [
            'id' => (string) $note->id,
            'author_id' => $note->author_id,
            'authorId' => $note->author_id,
            'author_name' => $note->author_name,
            'authorName' => $note->author_name,
            'body' => (string) $note->body,
            'visibility' => (string) $note->visibility,
            'created_at' => $this->timestamp($note->created_at),
            'createdAt' => $this->timestamp($note->created_at),
            'updated_at' => $this->timestamp($note->updated_at),
            'updatedAt' => $this->timestamp($note->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function eventPayload(ReviewEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'event_type' => (string) $event->event_type,
            'eventType' => (string) $event->event_type,
            'actor_type' => (string) $event->actor_type,
            'actorType' => (string) $event->actor_type,
            'actor_id' => $event->actor_id,
            'actorId' => $event->actor_id,
            'note' => $event->note,
            'previous_status' => $event->previous_status,
            'next_status' => $event->next_status,
            'metadata' => $this->sanitizeMetadata($event->metadata ?? []),
            'created_at' => $this->timestamp($event->created_at),
            'createdAt' => $this->timestamp($event->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private function casePackagePayload($package): array
    {
        return [
            'id' => (string) $package->id,
            'investigation_id' => $package->investigation_id,
            'investigationId' => $package->investigation_id,
            'format' => (string) $package->format,
            'status' => (string) $package->status,
            'title' => (string) $package->title,
            'generated_at' => $this->timestamp($package->generated_at),
            'generatedAt' => $this->timestamp($package->generated_at),
            'generated_by' => $package->generated_by,
            'generatedBy' => $package->generated_by,
            'included_sections' => $package->included_sections ?? [],
            'includedSections' => $package->included_sections ?? [],
            'included_counts' => $package->included_counts ?? [],
            'includedCounts' => $package->included_counts ?? [],
            'package_hash' => $package->package_hash,
            'packageHash' => $package->package_hash,
            'filename' => $package->filename,
            'storage_key' => $package->storage_key,
            'storageKey' => $package->storage_key,
        ];
    }

    private function userName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->email;
    }

    /** @return array<string, mixed> */
    private function safeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $metadata */
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

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }
}
