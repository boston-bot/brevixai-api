<?php

namespace App\Services;

final readonly class BusinessProfileContext
{
    public function __construct(
        public string $companyId,
        public ?string $businessProfileId,
        public string $role,
        public ?string $businessProfileName = null,
    ) {}
}
