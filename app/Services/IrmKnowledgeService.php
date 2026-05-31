<?php

namespace App\Services;

use App\Models\IrmDocument;
use App\Models\IrmSection;
use App\Support\ProfessionalServicesDisclaimer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IrmKnowledgeService
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 10;

    /** @var array<int, array{phrases: list<string>, prefixes: list<string>}> */
    private const QUERY_PREFIX_BOOSTS = [
        [
            'phrases' => ['levy', 'cp504', 'lt11', '1058', 'cdp'],
            'prefixes' => ['5.11.', '5.19.'],
        ],
        [
            'phrases' => ['lien', 'ftl'],
            'prefixes' => ['5.12.'],
        ],
        [
            'phrases' => ['trust fund', 'tfrp', 'payroll', 'employment tax'],
            'prefixes' => ['5.7.', '8.25.', '20.1.'],
        ],
        [
            'phrases' => ['installment', 'payment plan', 'balance due'],
            'prefixes' => ['5.14.', '5.19.'],
        ],
        [
            'phrases' => ['cp2000', 'underreported', 'underreporter'],
            'prefixes' => ['4.19.', '20.1.', '4.10.'],
        ],
    ];

    private const NOTICE_QUERIES = [
        'CP2000' => 'CP2000 underreported income proposed changes notice deficiency',
        'CP3219A' => 'statutory notice of deficiency 90 day letter Tax Court',
        'CP501' => 'balance due reminder notice collection',
        'CP504' => 'levy notice intent to levy balance due collection',
        'LT11' => 'notice intent to levy collection due process hearing',
        '1058' => 'notice intent to levy collection due process hearing',
        '941' => 'employment tax payroll tax trust fund recovery penalty',
        '1099-K' => 'information return payment card third party network transactions',
    ];

    /** @return array<string, mixed> */
    public function search(string $topic, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $this->normalizeLimit($limit);

        return [
            'status' => 'ok',
            'query' => trim($topic),
            'results' => $this->searchSections($topic, $limit)->all(),
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    /** @return array<string, mixed> */
    public function section(string $reference): array
    {
        $reference = $this->normalizeReference($reference);
        $section = IrmSection::query()
            ->with('document')
            ->where('irm_reference', $reference)
            ->first();

        if ($section) {
            $results = [$this->serializeSection($section)];
        } else {
            $document = IrmDocument::query()
                ->where('irm_reference', $reference)
                ->first();

            if ($document) {
                $results = [$this->serializeDocumentReference($document)];
            } else {
                $descendants = IrmSection::query()
                    ->with('document')
                    ->whereRaw('LOWER(irm_sections.irm_reference) LIKE ?', [strtolower($reference).'.%'])
                    ->orderBy('irm_reference')
                    ->limit(3)
                    ->get();

                $results = $descendants->isNotEmpty()
                    ? [$this->serializeReferenceGroup($reference, $descendants)]
                    : [];
            }
        }

        return [
            'status' => $results ? 'ok' : 'no_results',
            'reference' => $reference,
            'results' => $results,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    /** @return array<string, mixed> */
    public function explainNoticeType(string $noticeCode, int $limit = self::DEFAULT_LIMIT): array
    {
        $code = strtoupper(trim($noticeCode));
        $query = self::NOTICE_QUERIES[$code] ?? $code;
        $results = $this->searchSections($query, $this->normalizeLimit($limit))->all();

        return [
            'status' => $results ? 'ok' : 'no_results',
            'notice_code' => $code,
            'query' => $query,
            'summary' => $results
                ? "Source-backed IRM sections related to {$code}."
                : "No source-backed IRM sections were found for {$code}.",
            'results' => $results,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    /** @return array<string, mixed> */
    public function summarizeCollectionRisk(string $issueType, int $limit = self::DEFAULT_LIMIT): array
    {
        $issueType = trim($issueType);
        $query = $this->queryForIssueType($issueType);
        $results = $this->searchSections($query, $this->normalizeLimit($limit))->all();

        return [
            'status' => $results ? 'ok' : 'no_results',
            'issue_type' => $issueType,
            'query' => $query,
            'severity' => $this->severityForIssueType($issueType),
            'summary' => $results
                ? "Source-backed IRM sections related to {$issueType} collection risk."
                : "No source-backed IRM sections were found for {$issueType}.",
            'results' => $results,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    /** @return array<string, mixed> */
    public function recommendRecordsToGather(string $issueType, int $limit = self::DEFAULT_LIMIT): array
    {
        $issueType = trim($issueType);
        $query = $this->queryForIssueType($issueType);
        $results = $this->searchSections($query, $this->normalizeLimit($limit))->all();

        return [
            'status' => $results ? 'ok' : 'no_results',
            'issue_type' => $issueType,
            'query' => $query,
            'recommended_records' => $this->recordsForIssueType($issueType),
            'results' => $results,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function searchSections(string $query, int $limit): Collection
    {
        $terms = $this->searchTerms($query);
        if ($terms === []) {
            return collect();
        }

        [$scoreSql, $scoreBindings] = $this->relevanceScore($query, $terms);

        return IrmSection::query()
            ->with('document')
            ->where(function ($sectionQuery) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%' . $term . '%';
                    $sectionQuery
                        ->orWhereRaw('LOWER(irm_sections.irm_reference) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(irm_sections.title) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(irm_sections.body_text) LIKE ?', [$like])
                        ->orWhereHas('document', function ($documentQuery) use ($like): void {
                            $documentQuery
                                ->whereRaw('LOWER(irm_documents.irm_reference) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(irm_documents.title) LIKE ?', [$like]);
                        });
                }
            })
            ->selectRaw("irm_sections.*, ({$scoreSql}) AS relevance_score", $scoreBindings)
            ->orderByRaw('relevance_score DESC')
            ->orderBy('irm_reference')
            ->limit($limit)
            ->get()
            ->map(fn (IrmSection $section): array => $this->serializeSection($section));
    }

    /**
     * Build a SQL expression that scores each section by procedural relevance.
     *
     * @param list<string> $terms
     * @return array{0: string, 1: list<mixed>}
     */
    private function relevanceScore(string $query, array $terms): array
    {
        $lowerQuery = strtolower(trim($query));
        $boostPrefixes = $this->boostPrefixesForQuery($lowerQuery);

        $parts = [];
        $bindings = [];

        // Issue-family prefix boosts — highest-weight signal for procedural queries
        foreach ($boostPrefixes as $prefix) {
            $parts[] = 'CASE WHEN LOWER(irm_sections.irm_reference) LIKE ? THEN 60 ELSE 0 END';
            $bindings[] = $prefix . '%';
        }

        // Exact reference match — useful when the topic is an IRM reference string
        $parts[] = 'CASE WHEN LOWER(irm_sections.irm_reference) = ? THEN 100 ELSE 0 END';
        $bindings[] = $lowerQuery;

        // Per-term title matches
        foreach (array_slice($terms, 0, 4) as $term) {
            $parts[] = 'CASE WHEN LOWER(irm_sections.title) LIKE ? THEN 10 ELSE 0 END';
            $bindings[] = '%' . $term . '%';
        }

        // Per-term body matches
        foreach (array_slice($terms, 0, 4) as $term) {
            $parts[] = 'CASE WHEN LOWER(irm_sections.body_text) LIKE ? THEN 3 ELSE 0 END';
            $bindings[] = '%' . $term . '%';
        }

        return [implode(' + ', $parts), $bindings];
    }

    /** @return list<string> */
    private function boostPrefixesForQuery(string $lowerQuery): array
    {
        $prefixes = [];
        foreach (self::QUERY_PREFIX_BOOSTS as $group) {
            foreach ($group['phrases'] as $phrase) {
                if (str_contains($lowerQuery, $phrase)) {
                    array_push($prefixes, ...$group['prefixes']);
                    break;
                }
            }
        }

        return array_values(array_unique($prefixes));
    }

    /** @return list<string> */
    private function searchTerms(string $query): array
    {
        $terms = preg_split('/[^a-z0-9-]+/', strtolower($query)) ?: [];

        return array_values(array_slice(array_unique(array_filter(
            $terms,
            fn (string $term): bool => strlen($term) >= 2
        )), 0, 8));
    }

    /** @return array<string, mixed> */
    private function serializeSection(IrmSection $section): array
    {
        $document = $section->document;
        $effectiveDate = $section->effective_date ?? $document?->effective_date;

        return [
            'irm_reference' => $section->irm_reference,
            'document_title' => $document?->title,
            'section_title' => $section->title,
            'effective_date' => $effectiveDate?->format('Y-m-d'),
            'excerpt' => Str::limit(preg_replace('/\s+/', ' ', trim($section->body_text)) ?? '', 700),
            'source_type' => 'irm',
            's3_key' => $document?->s3_key,
        ];
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }

    private function queryForIssueType(string $issueType): string
    {
        $noticeQuery = $this->queryForNoticeCode($issueType);
        if ($noticeQuery !== null) {
            return $noticeQuery;
        }

        $normalized = strtolower($issueType);

        if (str_contains($normalized, 'levy')) {
            return 'levy notice intent to levy collection due process';
        }

        if (str_contains($normalized, 'lien')) {
            return 'federal tax lien collection notice';
        }

        if (str_contains($normalized, 'payroll') || str_contains($normalized, 'trust fund') || str_contains($normalized, 'tfrp')) {
            return 'employment tax payroll trust fund recovery penalty responsible person';
        }

        if (str_contains($normalized, 'installment') || str_contains($normalized, 'payment plan')) {
            return 'installment agreement balance due collection';
        }

        return $issueType;
    }

    private function severityForIssueType(string $issueType): string
    {
        $normalized = strtolower($issueType);

        $noticeCode = $this->noticeCodeFrom($issueType);
        if (in_array($noticeCode, ['CP504', 'LT11', '1058'], true)) {
            return 'critical';
        }
        if (in_array($noticeCode, ['CP3219A'], true)) {
            return 'high';
        }

        if (
            str_contains($normalized, 'levy') ||
            str_contains($normalized, 'seizure') ||
            str_contains($normalized, 'trust fund') ||
            str_contains($normalized, 'tfrp')
        ) {
            return 'critical';
        }

        if (str_contains($normalized, 'lien') || str_contains($normalized, 'balance due')) {
            return 'high';
        }

        return 'medium';
    }

    /** @return list<string> */
    private function recordsForIssueType(string $issueType): array
    {
        $normalized = strtolower($issueType);

        $noticeCode = $this->noticeCodeFrom($issueType);
        if (in_array($noticeCode, ['CP504', 'LT11', '1058'], true)) {
            return [
                'The IRS notice or letter, including all pages and dates.',
                'Account transcripts for the tax periods named in the notice.',
                'Payment history and confirmation records.',
                'Prior IRS correspondence and any collection appeal submissions.',
                'Current financial records needed to discuss collection alternatives with a tax professional.',
            ];
        }

        if ($noticeCode === 'CP2000') {
            return [
                'The full CP2000 notice, including proposed changes and response pages.',
                'The originally filed return for the tax year named in the notice.',
                'Forms W-2, 1099, K-1, brokerage, payment-card, or third-party network statements tied to the mismatch.',
                'Business income records and reconciliation workpapers for the reported amounts.',
                'Prior correspondence or response drafts about the proposed changes.',
            ];
        }

        if ($noticeCode === 'CP3219A') {
            return [
                'The full statutory notice, including the petition deadline page.',
                'The originally filed return and any proposed deficiency schedules.',
                'Income, deduction, credit, and payment records tied to the disputed items.',
                'Prior CP2000 or examination correspondence.',
                'Notes from any tax professional already involved.',
            ];
        }

        if (str_contains($normalized, 'levy')) {
            return [
                'The IRS notice or letter, including all pages and dates.',
                'Account transcripts for the tax periods named in the notice.',
                'Payment history and confirmation records.',
                'Prior IRS correspondence and any collection appeal submissions.',
                'Current financial records needed to discuss collection alternatives with a tax professional.',
            ];
        }

        if (str_contains($normalized, 'payroll') || str_contains($normalized, 'trust fund') || str_contains($normalized, 'tfrp')) {
            return [
                'Payroll tax returns and deposit confirmations for the affected quarters.',
                'Payroll registers and bank statements for the deposit periods.',
                'IRS notices, transcripts, and penalty correspondence.',
                'Responsible-person, signer, and payroll approval records.',
                'Prior accountant or payroll-provider correspondence.',
            ];
        }

        return [
            'The notice or letter that triggered the review.',
            'Tax periods and amounts identified by the IRS.',
            'Relevant returns, amendments, payment confirmations, and transcripts.',
            'Supporting business records tied to the IRS issue.',
            'Prior correspondence or professional notes about attempted resolution.',
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDocumentReference(IrmDocument $document): array
    {
        $sections = $document->sections()
            ->orderBy('irm_reference')
            ->limit(3)
            ->get();

        return [
            'irm_reference' => $document->irm_reference,
            'document_title' => $document->title,
            'section_title' => $document->title,
            'effective_date' => $document->effective_date?->format('Y-m-d'),
            'excerpt' => $this->excerptFromSections($sections),
            'source_type' => 'irm',
            's3_key' => $document->s3_key,
        ];
    }

    /**
     * @param Collection<int, IrmSection> $sections
     * @return array<string, mixed>
     */
    private function serializeReferenceGroup(string $reference, Collection $sections): array
    {
        $first = $sections->first();
        $document = $first?->document;

        return [
            'irm_reference' => $reference,
            'document_title' => $document?->title,
            'section_title' => $first?->title ?: $document?->title,
            'effective_date' => ($first?->effective_date ?? $document?->effective_date)?->format('Y-m-d'),
            'excerpt' => $this->excerptFromSections($sections),
            'source_type' => 'irm',
            's3_key' => $document?->s3_key,
        ];
    }

    /** @param Collection<int, IrmSection> $sections */
    private function excerptFromSections(Collection $sections): string
    {
        $text = $sections
            ->map(fn (IrmSection $section): string => trim($section->body_text))
            ->filter()
            ->implode(' ');

        return Str::limit(preg_replace('/\s+/', ' ', trim($text)) ?? '', 700);
    }

    private function queryForNoticeCode(string $issueType): ?string
    {
        $noticeCode = $this->noticeCodeFrom($issueType);

        return $noticeCode !== null ? (self::NOTICE_QUERIES[$noticeCode] ?? null) : null;
    }

    private function noticeCodeFrom(string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');

        return $normalized !== '' && array_key_exists($normalized, self::NOTICE_QUERIES)
            ? $normalized
            : null;
    }

    private function normalizeReference(string $reference): string
    {
        return preg_replace('/^IRM\s+/i', '', trim($reference)) ?? trim($reference);
    }
}
