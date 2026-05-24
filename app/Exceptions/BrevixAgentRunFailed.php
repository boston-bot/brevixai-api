<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class BrevixAgentRunFailed extends RuntimeException
{
    public function __construct(
        private readonly string $agentRunId,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }

    public function agentRunId(): string
    {
        return $this->agentRunId;
    }
}
