<?php

namespace App\Services\Retrieval;

final readonly class RetrievalQuery
{
    public function __construct(
        public string $corpusId,
        public string $query,
        public int $limit = 5,
        /** @var array<string, mixed> */
        public array $filters = [],
    ) {
    }

    public function normalizedQuery(): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->query) ?? '');
    }

    public function normalizedLimit(int $max = 20): int
    {
        return max(1, min($this->limit, $max));
    }
}
