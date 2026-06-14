<?php

namespace App\Services;

use App\Models\AuditCase;
use App\Models\AuditCaseEvent;
use App\Models\TaxpayerTransparencyItem;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class TaxpayerTransparencyService
{
    private const PRINCIPLE = 'If it cannot be verified, do not present it as fact.';

    private const SECTION_KEYS = [
        TaxpayerTransparencyItem::CATEGORY_VERIFIED_FACT => 'verifiedFacts',
        TaxpayerTransparencyItem::CATEGORY_UNVERIFIED_CLAIM => 'unverifiedClaims',
        TaxpayerTransparencyItem::CATEGORY_ASSUMPTION => 'assumptions',
        TaxpayerTransparencyItem::CATEGORY_UNKNOWN => 'unknowns',
    ];

    private const SECTION_LABELS = [
        TaxpayerTransparencyItem::CATEGORY_VERIFIED_FACT => 'Verified Facts',
        TaxpayerTransparencyItem::CATEGORY_UNVERIFIED_CLAIM => 'Unverified Claims',
        TaxpayerTransparencyItem::CATEGORY_ASSUMPTION => 'Assumptions',
        TaxpayerTransparencyItem::CATEGORY_UNKNOWN => 'Unknowns',
    ];

    private const AUTHORITATIVE_SOURCE_TYPES = [
        'irs_transcript',
        'irs_notice',
        'payment_record',
        'agency_record',
        'court_record',
        'public_record',
        'filed_return_copy',
        'bank_record',
    ];

    private const ALL_SOURCE_TYPES = [
        ...self::AUTHORITATIVE_SOURCE_TYPES,
        'representative_statement',
        'taxpayer_statement',
        'internal_note',
        'other',
    ];

    /** @return list<string> */
    public function categories(): array
    {
        return array_keys(self::SECTION_KEYS);
    }

    /** @return list<string> */
    public function sourceTypes(): array
    {
        return self::ALL_SOURCE_TYPES;
    }

    /** @return array<string, mixed>|null */
    public function visibility(string $companyId, string $caseId, ?string $businessProfileId = null): ?array
    {
        $case = $this->caseQuery($companyId, $businessProfileId)
            ->where('id', $caseId)
            ->first();

        if (! $case) {
            return null;
        }

        $items = TaxpayerTransparencyItem::query()
            ->where('company_id', $companyId)
            ->where('audit_case_id', $caseId)
            ->whereProfile($businessProfileId)
            ->orderByDesc('captured_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TaxpayerTransparencyItem $item): array => $this->formatItem($item))
            ->all();

        $sections = $this->emptySections();
        foreach ($items as $item) {
            $category = (string) $item['category'];
            $sectionKey = self::SECTION_KEYS[$category] ?? self::SECTION_KEYS[TaxpayerTransparencyItem::CATEGORY_UNKNOWN];
            $sections[$sectionKey][] = $item;
        }

        return [
            'case' => [
                'id' => (string) $case->id,
                'title' => (string) $case->title,
                'status' => (string) $case->status,
                'severity' => (string) $case->severity,
            ],
            'principle' => self::PRINCIPLE,
            'sections' => $sections,
            'counts' => $this->counts($sections),
            'limitations' => [
                'Submission, receipt, and processing are separate states.',
                'Representative or taxpayer statements remain claims until supported by authoritative records.',
                'Unknowns should stay visible instead of being converted into assumptions.',
            ],
            'generatedAt' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Exception
     */
    public function createItem(string $companyId, string $userId, string $caseId, array $data, ?string $businessProfileId = null): TaxpayerTransparencyItem
    {
        $case = $this->caseQuery($companyId, $businessProfileId)
            ->where('id', $caseId)
            ->first();

        if (! $case) {
            throw new Exception('Case not found', 404);
        }

        $category = (string) $data['category'];
        $sourceType = $data['source_type'] ?? null;
        $sourceType = $sourceType === null ? null : (string) $sourceType;

        $this->assertCompatibleEvidence($category, $sourceType);

        $item = TaxpayerTransparencyItem::create([
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'audit_case_id' => $caseId,
            'created_by' => $userId,
            'category' => $category,
            'status_key' => $data['status_key'] ?? null,
            'tax_period' => $data['tax_period'] ?? null,
            'label' => $data['label'],
            'detail' => $data['detail'] ?? null,
            'source_type' => $sourceType,
            'source_label' => $data['source_label'] ?? null,
            'source_reference' => $data['source_reference'] ?? null,
            'source_date' => $data['source_date'] ?? null,
            'captured_at' => $data['captured_at'] ?? now(),
            'metadata' => $this->safeMetadata($data['metadata'] ?? []),
        ]);

        $this->recordCaseEvent($companyId, $userId, $caseId, $businessProfileId, $item);

        return $item;
    }

    /** @return array<string, mixed> */
    public function formatItem(TaxpayerTransparencyItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'category' => (string) $item->category,
            'section' => self::SECTION_LABELS[$item->category] ?? self::SECTION_LABELS[TaxpayerTransparencyItem::CATEGORY_UNKNOWN],
            'statusKey' => $item->status_key,
            'taxPeriod' => $item->tax_period,
            'label' => (string) $item->label,
            'detail' => $item->detail,
            'sourceType' => $item->source_type,
            'sourceLabel' => $item->source_label,
            'sourceReference' => $item->source_reference,
            'sourceDate' => $item->source_date?->toDateString(),
            'capturedAt' => $item->captured_at?->toJSON(),
            'metadata' => $item->metadata ?? [],
            'createdAt' => $item->created_at?->toJSON(),
        ];
    }

    private function assertCompatibleEvidence(string $category, ?string $sourceType): void
    {
        if (! array_key_exists($category, self::SECTION_KEYS)) {
            throw new \InvalidArgumentException('Unsupported transparency category.');
        }

        if ($sourceType !== null && ! in_array($sourceType, self::ALL_SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported transparency source type.');
        }

        if ($category === TaxpayerTransparencyItem::CATEGORY_VERIFIED_FACT && ! in_array($sourceType, self::AUTHORITATIVE_SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('Verified facts require an authoritative source type.');
        }

        if ($category !== TaxpayerTransparencyItem::CATEGORY_VERIFIED_FACT && in_array($sourceType, self::AUTHORITATIVE_SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('Authoritative source records should be recorded as verified facts.');
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function emptySections(): array
    {
        return [
            'verifiedFacts' => [],
            'unverifiedClaims' => [],
            'assumptions' => [],
            'unknowns' => [],
        ];
    }

    /** @param array<string, list<array<string, mixed>>> $sections */
    private function counts(array $sections): array
    {
        return [
            'verifiedFacts' => count($sections['verifiedFacts']),
            'unverifiedClaims' => count($sections['unverifiedClaims']),
            'assumptions' => count($sections['assumptions']),
            'unknowns' => count($sections['unknowns']),
        ];
    }

    /** @param array<string, mixed>|mixed $metadata */
    private function safeMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $blocked = ['raw_notice_text', 'notice_text', 'ssn', 'ein', 'tin', 'taxpayer_id'];
        $safe = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $blocked, true) || str_contains($normalizedKey, 'raw_')) {
                continue;
            }

            $safe[(string) $key] = $value;
        }

        return $safe;
    }

    private function recordCaseEvent(
        string $companyId,
        string $userId,
        string $caseId,
        ?string $businessProfileId,
        TaxpayerTransparencyItem $item,
    ): void {
        if (! Schema::hasTable('audit_case_events')) {
            return;
        }

        $payload = [
            'case_id' => $caseId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'event_type' => 'taxpayer_transparency_item_created',
            'payload' => [
                'itemId' => (string) $item->id,
                'category' => (string) $item->category,
                'label' => (string) $item->label,
            ],
        ];

        if ($businessProfileId && Schema::hasColumn('audit_case_events', 'business_profile_id')) {
            $payload['business_profile_id'] = $businessProfileId;
        }

        AuditCaseEvent::create($payload);
    }

    private function caseQuery(string $companyId, ?string $businessProfileId = null): Builder
    {
        return AuditCase::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('audit_cases', 'business_profile_id'),
                fn (Builder $query): Builder => $query->where('business_profile_id', $businessProfileId),
            );
    }
}
