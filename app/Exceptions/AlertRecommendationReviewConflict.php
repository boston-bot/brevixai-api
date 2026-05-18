<?php

namespace App\Exceptions;

use RuntimeException;

class AlertRecommendationReviewConflict extends RuntimeException
{
    public function __construct(
        public readonly string $currentStatus,
    ) {
        parent::__construct('Alert recommendation has already been reviewed.');
    }
}
