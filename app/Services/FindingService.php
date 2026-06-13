<?php

namespace App\Services;

use App\Models\Finding;
use App\Models\Investigation;
use App\Models\ReviewEvent;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class FindingService
{
    public function __construct(private readonly InvestigationPlatformService $investigations) {}

    /** @return array<string, mixed> */
    public function list(BusinessProfileContext $context, array $filters = []): array
    {
        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = Finding::query()
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->with(['suggestedRecords']);

        foreach ([
            'category',
            'source_module',
            'status',
            'severity',
            'reviewer_status',
            'investigation_id',
        ] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, (string) $filters[$field]);
            }
        }

        $total = (clone $query)->count();

        $findings = $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (Finding $finding): array => $this->investigations->findingPayload($finding))
            ->values()
            ->all();

        return ['findings' => $findings, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function show(BusinessProfileContext $context, string $id): ?array
    {
        $finding = Finding::query()
            ->where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->with(['suggestedRecords', 'evidenceItems', 'reviewerNotes', 'reviewEvents'])
            ->where('id', $id)
            ->first();

        if (! $finding) {
            return null;
        }

        return ['finding' => $this->investigations->findingPayload($finding)];
    }

    /** @return array<string, mixed> */
    public function payload(Finding $finding): array
    {
        return $this->investigations->findingPayload($finding);
    }

    /** @return array<string, mixed> */
    public function investigationPayload(BusinessProfileContext $context, Investigation $investigation): array
    {
        return $this->investigations->investigationPayload($context, $investigation);
    }

    /** @param array<string, mixed> $data */
    public function review(BusinessProfileContext $context, User $actor, string $id, array $data): ?Finding
    {
        return DB::transaction(function () use ($context, $actor, $id, $data): ?Finding {
            $finding = Finding::query()
                ->where('company_id', $context->companyId)
                ->whereProfile($context->businessProfileId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $finding) {
                return null;
            }

            $previousStatus = $finding->status;
            $status = $this->normalizeStatus((string) ($data['status'] ?? $data['reviewer_status'] ?? $finding->status));
            $reviewerStatus = $data['reviewerStatus'] ?? $data['reviewer_status'] ?? $this->reviewerStatusFor($status);

            $finding->update([
                'status' => $status,
                'reviewer_status' => $reviewerStatus,
            ]);

            if (! empty($data['note']) && $finding->investigation_id) {
                $this->investigations->addNote($context, $actor, (string) $finding->investigation_id, (string) $data['note'], (string) $finding->id);
            }

            if ($finding->investigation) {
                $this->investigations->recordEvent(
                    investigation: $finding->investigation,
                    findingId: (string) $finding->id,
                    eventType: 'finding_reviewed',
                    actorType: ReviewEvent::ACTOR_USER,
                    actorId: (string) $actor->id,
                    previousStatus: (string) $previousStatus,
                    nextStatus: $status,
                    note: $data['note'] ?? null,
                    metadata: ['reviewer_status' => $reviewerStatus],
                );
            }

            return $finding->fresh(['suggestedRecords']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createInvestigation(BusinessProfileContext $context, User $actor, string $id, array $data = []): ?Investigation
    {
        return DB::transaction(function () use ($context, $actor, $id, $data): ?Investigation {
            $finding = Finding::query()
                ->where('company_id', $context->companyId)
                ->whereProfile($context->businessProfileId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $finding) {
                return null;
            }

            if ($finding->investigation_id) {
                return Investigation::where('id', $finding->investigation_id)
                    ->where('company_id', $context->companyId)
                    ->whereProfile($context->businessProfileId)
                    ->first();
            }

            $investigation = $this->investigations->create($context, $actor, [
                'title' => $data['title'] ?? $finding->title,
                'category' => $data['category'] ?? $finding->category,
                'priority' => $data['priority'] ?? $this->priorityFromSeverity((string) $finding->severity),
                'scopeStatement' => $data['scopeStatement'] ?? $data['scope_statement'] ?? $finding->summary,
                'scopeLimitations' => $data['scopeLimitations'] ?? [],
            ]);

            $finding->update([
                'investigation_id' => $investigation->id,
                'status' => Finding::STATUS_IN_REVIEW,
            ]);

            $this->investigations->recordEvent(
                investigation: $investigation,
                findingId: (string) $finding->id,
                eventType: 'finding_attached',
                actorType: ReviewEvent::ACTOR_USER,
                actorId: (string) $actor->id,
                note: 'Investigation opened from finding',
            );

            return $investigation->fresh();
        });
    }

    public function normalizeStatus(string $status): string
    {
        return match ($status) {
            'pending', 'open' => Finding::STATUS_NEW,
            'reviewed', 'approved' => Finding::STATUS_REVIEWED,
            'dismissed', 'ignored' => Finding::STATUS_DISMISSED,
            'needs_evidence' => Finding::STATUS_NEEDS_MORE_EVIDENCE,
            default => in_array($status, [
                Finding::STATUS_NEW,
                Finding::STATUS_IN_REVIEW,
                Finding::STATUS_NEEDS_MORE_EVIDENCE,
                Finding::STATUS_REVIEWED,
                Finding::STATUS_DISMISSED,
                Finding::STATUS_ESCALATED,
                Finding::STATUS_INCLUDED_IN_PACKAGE,
            ], true) ? $status : Finding::STATUS_IN_REVIEW,
        };
    }

    private function reviewerStatusFor(string $status): string
    {
        return match ($status) {
            Finding::STATUS_REVIEWED,
            Finding::STATUS_INCLUDED_IN_PACKAGE => 'reviewed',
            Finding::STATUS_DISMISSED => 'dismissed',
            default => 'pending',
        };
    }

    private function priorityFromSeverity(string $severity): string
    {
        return match ($severity) {
            Finding::SEVERITY_CRITICAL => Investigation::PRIORITY_HIGH,
            Finding::SEVERITY_INFO => Investigation::PRIORITY_LOW,
            default => Investigation::PRIORITY_MEDIUM,
        };
    }
}
