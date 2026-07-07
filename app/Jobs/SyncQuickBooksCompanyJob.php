<?php

namespace App\Jobs;

use App\Services\QboService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncQuickBooksCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public function __construct(
        private readonly string $companyId,
        private readonly string $realmId,
        private readonly ?string $businessProfileId = null,
    ) {}

    public function handle(QboService $qboService): void
    {
        $qboService->sync($this->companyId, $this->realmId, $this->businessProfileId);
    }
}
