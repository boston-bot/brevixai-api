<?php

namespace App\Exceptions;

use RuntimeException;

class CaseRecommendationReviewConflict extends RuntimeException
{
    public function __construct(
        public readonly string $currentStatus,
    ) {
        parent::__construct('Case recommendation has already been reviewed.');
    }
}
