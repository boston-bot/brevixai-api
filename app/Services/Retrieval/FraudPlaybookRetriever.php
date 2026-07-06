<?php

namespace App\Services\Retrieval;

use App\Models\Fraud\InvestigationPlaybook;
use Illuminate\Support\Str;

class FraudPlaybookRetriever
{
    public const CORPUS_ID = 'fraud_playbooks';
    public const CORPUS_VERSION = 'fraud_playbooks:v1';
    public const SCORING_STRATEGY = 'lexical_playbook_v1';

    private const MAX_LIMIT = 20;

    /** @var array<string, int> */
    private const FIELD_WEIGHTS = [
        'title' => 40,
        'category' => 24,
        'intent_key' => 20,
        'description' => 14,
        'red_flags' => 14,
        'symptoms' => 10,
        'tests' => 8,
        'document_requests' => 6,
        'source_name' => 4,
    ];

    public function search(RetrievalQuery $query): RetrievalResponse
    {
        $normalizedQuery = $query->normalizedQuery();
        $terms = $this->terms($normalizedQuery);

        if ($normalizedQuery === '' || $terms === []) {
            return new RetrievalResponse(
                status: 'no_results',
                corpusId: self::CORPUS_ID,
                corpusVersion: self::CORPUS_VERSION,
                query: $normalizedQuery,
                scoringStrategy: self::SCORING_STRATEGY,
                results: [],
                metadata: ['reason' => 'empty_query']
            );
        }

        $results = InvestigationPlaybook::query()
            ->with(['source', 'versions'])
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->limit(250)
            ->get()
            ->map(fn (InvestigationPlaybook $playbook): ?RetrievalResult => $this->scorePlaybook($playbook, $normalizedQuery, $terms))
            ->filter()
            ->sortByDesc(fn (RetrievalResult $result): float => $result->relevanceScore)
            ->take($query->normalizedLimit(self::MAX_LIMIT))
            ->values()
            ->all();

        return new RetrievalResponse(
            status: $results === [] ? 'no_results' : 'ok',
            corpusId: self::CORPUS_ID,
            corpusVersion: self::CORPUS_VERSION,
            query: $normalizedQuery,
            scoringStrategy: self::SCORING_STRATEGY,
            results: $results,
            metadata: [
                'terms' => $terms,
                'retrieval_stage' => 'lexical_baseline',
            ]
        );
    }

    /**
     * @param list<string> $terms
     */
    private function scorePlaybook(InvestigationPlaybook $playbook, string $query, array $terms): ?RetrievalResult
    {
        $fields = $this->searchableFields($playbook);
        $components = [];
        $bestField = 'description';
        $bestFieldScore = 0;

        foreach (self::FIELD_WEIGHTS as $field => $weight) {
            $text = strtolower($fields[$field] ?? '');
            if ($text === '') {
                continue;
            }

            $fieldScore = str_contains($text, strtolower($query)) ? $weight * 2 : 0;
            foreach ($terms as $term) {
                if (str_contains($text, $term)) {
                    $fieldScore += $weight;
                }
            }

            if ($fieldScore > 0) {
                $components[$field] = $fieldScore;
            }
            if ($fieldScore > $bestFieldScore) {
                $bestFieldScore = $fieldScore;
                $bestField = $field;
            }
        }

        $score = (float) array_sum($components);
        if ($score <= 0) {
            return null;
        }

        $version = $playbook->versions
            ->sortByDesc('created_at')
            ->first()?->version_number;

        $document = $playbook->toArray();
        $document['source'] = $playbook->source?->toArray();

        return new RetrievalResult(
            sourceType: 'fraud_playbook',
            sourceId: (string) $playbook->id,
            title: $playbook->title,
            snippet: Str::limit($fields[$bestField] ?: ($fields['description'] ?: $fields['title']), 420),
            snippetField: $bestField,
            relevanceScore: $score,
            confidence: $this->confidence($score),
            scoreComponents: $components,
            document: $document,
            citations: [
                new RetrievalCitation(
                    sourceType: 'fraud_playbook',
                    sourceId: (string) $playbook->id,
                    title: $playbook->title,
                    fields: array_keys($components),
                    sourceName: $playbook->source?->name,
                    sourceVersion: $version,
                ),
            ],
        );
    }

    /** @return array<string, string> */
    private function searchableFields(InvestigationPlaybook $playbook): array
    {
        return [
            'title' => (string) $playbook->title,
            'category' => (string) $playbook->category,
            'intent_key' => (string) $playbook->intent_key,
            'description' => (string) $playbook->description,
            'symptoms' => $this->flatten($playbook->symptoms ?? []),
            'red_flags' => $this->flatten($playbook->red_flags ?? []),
            'tests' => $this->flatten($playbook->tests ?? []),
            'document_requests' => $this->flatten($playbook->document_requests ?? []),
            'source_name' => (string) $playbook->source?->name,
        ];
    }

    /**
     * @param mixed $value
     */
    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map(fn (mixed $item): string => $this->flatten($item), $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        $terms = preg_split('/[^a-z0-9-]+/', strtolower($query)) ?: [];

        return array_values(array_slice(array_unique(array_filter(
            $terms,
            fn (string $term): bool => strlen($term) >= 2
        )), 0, 10));
    }

    private function confidence(float $score): string
    {
        if ($score >= 60) {
            return 'high';
        }

        if ($score >= 24) {
            return 'medium';
        }

        return 'low';
    }
}
