<?php

namespace App\Services\Agents;

readonly class AgentActionExecutionResult
{
    public function __construct(
        public string $resourceType,
        public string $resourceId,
    ) {}
}
