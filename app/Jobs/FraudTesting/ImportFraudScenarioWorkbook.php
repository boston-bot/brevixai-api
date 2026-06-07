<?php

namespace App\Jobs\FraudTesting;

use App\Models\FraudTesting\FraudScenarioImport;
use App\Services\FraudTesting\FraudWorkbookImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportFraudScenarioWorkbook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        protected string $filePath,
        protected string $importId,
        protected ?string $uploadedById = null,
    ) {}

    public function handle(FraudWorkbookImportService $importService): void
    {
        $import = FraudScenarioImport::find($this->importId);
        if (! $import) {
            return;
        }

        $importService->importFromPath($this->filePath, $this->uploadedById);
    }
}
