<?php

namespace App\Services\Retrieval;

final readonly class RetrievalResult
{
    /**
     * @param array<string, int|float> $scoreComponents
     * @param array<string, mixed> $document
     * @param list<RetrievalCitation> $citations
     */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $title,
        public string $snippet,
        public string $snippetField,
        public float $relevanceScore,
        public string $confidence,
        public array $scoreComponents,
        public array $document,
        public array $citations,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'snippet_field' => $this->snippetField,
            'relevance_score' => $this->relevanceScore,
            'confidence' => $this->confidence,
            'score_components' => $this->scoreComponents,
            'document' => $this->document,
            'citations' => array_map(
                fn (RetrievalCitation $citation): array => $citation->toArray(),
                $this->citations
            ),
        ];
    }
}
