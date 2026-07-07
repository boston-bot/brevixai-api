<?php

namespace App\Services\Retrieval;

final readonly class RetrievalCitation
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $title,
        public array $fields,
        public ?string $sourceName = null,
        public ?string $sourceVersion = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'source_name' => $this->sourceName,
            'source_version' => $this->sourceVersion,
            'fields' => $this->fields,
        ];
    }
}
