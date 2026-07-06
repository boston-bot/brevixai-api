<?php

namespace App\Services\Retrieval;

use App\Models\Fraud\InvestigationPlaybook;
use App\Models\Fraud\RetrievalFeedback;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FraudPlaybookRetriever
{
    public const CORPUS_ID = 'fraud_playbooks';
    public const CORPUS_VERSION = 'fraud_playbooks:v2';
    public const SCORING_STRATEGY = 'lexical_playbook_v2';

    private const MAX_LIMIT = 20;
    private const MAX_ORIGINAL_TERMS = 10;
    private const MAX_TOTAL_TERMS = 16;

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

    /**
     * Deterministic lay-language expansion: when a phrase appears in the
     * query, the mapped corpus vocabulary joins the term list. This is what
     * lets "my bookkeeper quit suddenly" reach the segregation-of-duties
     * playbook without an LLM in the loop.
     *
     * @var array<string, list<string>>
     */
    private const QUERY_EXPANSIONS = [
        'stealing' => ['fraud', 'missing', 'cash'],
        'stolen' => ['fraud', 'missing', 'cash'],
        'theft' => ['fraud', 'missing', 'cash'],
        'embezzl' => ['fraud', 'reconciliation', 'controls'],
        'missing money' => ['cash', 'skimming', 'deposits'],
        'money is missing' => ['cash', 'skimming', 'deposits'],
        'paid twice' => ['duplicate', 'invoice'],
        'double charged' => ['duplicate', 'invoice'],
        'charged twice' => ['duplicate', 'invoice'],
        'bookkeeper' => ['segregation', 'reconciliation', 'controls', 'transition'],
        'quit suddenly' => ['transition', 'segregation', 'controls'],
        'ghost' => ['payroll', 'employee', 'personnel'],
        'do not recognize' => ['ghost', 'personnel', 'payroll'],
        "don't recognize" => ['ghost', 'personnel', 'payroll'],
        'irs' => ['tax', 'notice', 'payroll', 'deposits'],
        'levy' => ['irs', 'tax', 'notice'],
        'kickback' => ['vendor', 'concentration', 'bids'],
        'wire' => ['payment', 'redirection', 'bank', 'remittance'],
        'hacked' => ['email', 'compromise', 'redirection', 'bank'],
        'phishing' => ['email', 'compromise', 'redirection'],
        'impersonat' => ['email', 'compromise', 'redirection'],
        'fake vendor' => ['shell', 'fictitious', 'billing'],
        'fake invoice' => ['shell', 'fictitious', 'billing'],
        'overtime' => ['payroll', 'spike', 'off-cycle'],
        'refund' => ['credit', 'memo', 'revenue'],
    ];

    public function search(RetrievalQuery $query): RetrievalResponse
    {
        $normalizedQuery = $query->normalizedQuery();
        $originalTerms = $this->terms($normalizedQuery, self::MAX_ORIGINAL_TERMS);

        if ($normalizedQuery === '' || $originalTerms === []) {
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

        $expandedTerms = $this->expandTerms($normalizedQuery, $originalTerms);

        $candidates = InvestigationPlaybook::query()
            ->with(['source', 'versions'])
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->limit(250)
            ->get()
            ->map(fn (InvestigationPlaybook $playbook): ?RetrievalResult => $this->scorePlaybook(
                $playbook,
                strtolower($normalizedQuery),
                $originalTerms,
                $expandedTerms
            ))
            ->filter()
            ->values();

        $boosts = $this->feedbackBoosts($candidates->map(fn (RetrievalResult $r): string => $r->sourceId)->all());

        $results = $candidates
            ->map(function (RetrievalResult $result) use ($boosts): RetrievalResult {
                $boost = $boosts[$result->sourceId] ?? 1.0;
                if ($boost === 1.0) {
                    return $result;
                }

                return new RetrievalResult(
                    sourceType: $result->sourceType,
                    sourceId: $result->sourceId,
                    title: $result->title,
                    snippet: $result->snippet,
                    snippetField: $result->snippetField,
                    relevanceScore: round($result->relevanceScore * $boost, 2),
                    confidence: $this->confidence($result->relevanceScore * $boost),
                    scoreComponents: array_merge($result->scoreComponents, ['feedback_boost' => round($boost, 3)]),
                    document: $result->document,
                    citations: $result->citations,
                );
            })
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
                'terms' => $originalTerms,
                'expanded_terms' => array_values(array_diff($expandedTerms, $originalTerms)),
                'retrieval_stage' => 'lexical_expanded',
                'feedback_boost_applied' => $boosts !== [],
            ]
        );
    }

    /**
     * @param list<string> $originalTerms
     * @param list<string> $expandedTerms
     */
    private function scorePlaybook(
        InvestigationPlaybook $playbook,
        string $query,
        array $originalTerms,
        array $expandedTerms
    ): ?RetrievalResult {
        $fields = $this->searchableFields($playbook);
        $components = [];
        $bestField = 'description';
        $bestFieldScore = 0;

        foreach (self::FIELD_WEIGHTS as $field => $weight) {
            $text = strtolower($fields[$field] ?? '');
            if ($text === '') {
                continue;
            }

            $fieldScore = str_contains($text, $query) ? $weight * 2 : 0;
            foreach ($expandedTerms as $term) {
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

        $rawScore = (float) array_sum($components);
        if ($rawScore <= 0) {
            return null;
        }

        // Reward playbooks that cover more of the user's actual words, so a
        // playbook matching one common term can't outrank one matching most
        // of the query.
        $combinedText = strtolower(implode(' ', $fields));
        $matchedOriginal = count(array_filter(
            $originalTerms,
            fn (string $term): bool => str_contains($combinedText, $term)
        ));
        $coverage = 0.6 + 0.4 * ($matchedOriginal / max(1, count($originalTerms)));
        $score = round($rawScore * $coverage, 2);

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
            scoreComponents: array_merge($components, ['coverage_multiplier' => round($coverage, 3)]),
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

    /**
     * Aggregate stored reader feedback into gentle ranking boosts. Requires
     * at least two votes per playbook; caps at ±12% so feedback tunes rather
     * than dominates lexical relevance.
     *
     * @param list<string> $playbookIds
     * @return array<string, float>
     */
    private function feedbackBoosts(array $playbookIds): array
    {
        if ($playbookIds === [] || ! Schema::hasTable('retrieval_feedback')) {
            return [];
        }

        return RetrievalFeedback::query()
            ->selectRaw('playbook_id, AVG(relevance_score) as avg_score, COUNT(*) as feedback_count')
            ->whereIn('playbook_id', $playbookIds)
            ->groupBy('playbook_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->mapWithKeys(function ($row): array {
                $boost = 1.0 + max(-0.12, min(0.12, ((float) $row->avg_score - 3.0) * 0.06));

                return [(string) $row->playbook_id => $boost];
            })
            ->all();
    }

    /**
     * @param list<string> $originalTerms
     * @return list<string>
     */
    private function expandTerms(string $normalizedQuery, array $originalTerms): array
    {
        $lower = strtolower($normalizedQuery);
        $terms = $originalTerms;

        foreach (self::QUERY_EXPANSIONS as $phrase => $expansions) {
            if (str_contains($lower, $phrase)) {
                $terms = array_merge($terms, $expansions);
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, self::MAX_TOTAL_TERMS);
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
    private function terms(string $query, int $max): array
    {
        $terms = preg_split('/[^a-z0-9-]+/', strtolower($query)) ?: [];

        return array_values(array_slice(array_unique(array_filter(
            $terms,
            fn (string $term): bool => strlen($term) >= 2
        )), 0, $max));
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
