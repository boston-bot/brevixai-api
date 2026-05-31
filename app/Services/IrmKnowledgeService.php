<?php

namespace App\Services;

use App\Models\IrmSection;
use App\Support\ProfessionalServicesDisclaimer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IrmKnowledgeService
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 10;

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
        $reference = trim($reference);
        $section = IrmSection::query()
            ->with('document')
            ->where('irm_reference', $reference)
            ->first();

        return [
            'status' => $section ? 'ok' : 'no_results',
            'reference' => $reference,
            'results' => $section ? [$this->serializeSection($section)] : [],
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
            ->orderBy('irm_reference')
            ->limit($limit)
            ->get()
            ->map(fn (IrmSection $section): array => $this->serializeSection($section));
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
}
