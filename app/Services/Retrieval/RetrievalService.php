<?php

namespace App\Services\Retrieval;

use InvalidArgumentException;

class RetrievalService
{
    public function __construct(
        private readonly FraudPlaybookRetriever $fraudPlaybookRetriever,
    ) {
    }

    public function search(RetrievalQuery $query): RetrievalResponse
    {
        return match ($query->corpusId) {
            FraudPlaybookRetriever::CORPUS_ID => $this->fraudPlaybookRetriever->search($query),
            default => throw new InvalidArgumentException("Unsupported retrieval corpus [{$query->corpusId}]."),
        };
    }
}
