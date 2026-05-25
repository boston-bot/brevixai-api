<?php

namespace App\Exceptions;

use RuntimeException;

class BusinessProfileAccessException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 403)
    {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
