<?php

namespace App\Services\Retrieval;

use App\Support\ProfessionalServicesDisclaimer;

final readonly class RetrievalResponse
{
    /**
     * @param list<RetrievalResult> $results
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $status,
        public string $corpusId,
        public string $corpusVersion,
        public string $query,
        public string $scoringStrategy,
        public array $results,
        public array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $results = array_map(
            fn (RetrievalResult $result): array => $result->toArray(),
            $this->results
        );

        return [
            'status' => $this->status,
            'corpus_id' => $this->corpusId,
            'corpus_version' => $this->corpusVersion,
            'query' => $this->query,
            'result_count' => count($results),
            'scoring' => [
                'strategy' => $this->scoringStrategy,
                'hybrid' => false,
            ],
            'results' => $results,
            'citations' => $this->uniqueCitations($results),
            'metadata' => $this->metadata,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    private function uniqueCitations(array $results): array
    {
        $citations = [];
        foreach ($results as $result) {
            foreach ($result['citations'] ?? [] as $citation) {
                if (! is_array($citation)) {
                    continue;
                }
                $key = ($citation['source_type'] ?? '').':'.($citation['source_id'] ?? '');
                $citations[$key] = $citation;
            }
        }

        return array_values($citations);
    }
}
