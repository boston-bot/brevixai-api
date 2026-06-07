<?php

namespace App\Console\Commands\FraudTesting;

use App\Services\FraudTesting\FraudWorkbookImportService;
use Illuminate\Console\Command;

class ImportScenariosCommand extends Command
{
    protected $signature = 'fraud-testing:import-scenarios
                            {file : Path to the Excel workbook (.xlsx)}
                            {--dry-run : Validate rows without saving to the database}
                            {--fail-on-error : Exit with non-zero code if any row fails validation}';

    protected $description = 'Import a fraud scenario workbook (.xlsx) into the database';

    public function handle(FraudWorkbookImportService $importService): int
    {
        $filePath = $this->argument('file');
        $dryRun = $this->option('dry-run');
        $failOnError = $this->option('fail-on-error');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Importing: {$filePath}");

        $import = $importService->importFromPath($filePath, null, $dryRun);

        $this->table(
            ['Field', 'Value'],
            [
                ['Import ID', $import->id],
                ['Status', $import->status],
                ['Total Rows', $import->total_rows],
                ['Successful', $import->successful_rows],
                ['Failed', $import->failed_rows],
            ]
        );

        if (! empty($import->validation_errors)) {
            $this->warn('Validation errors:');
            foreach ($import->validation_errors as $err) {
                $this->line('  Row ' . ($err['row'] ?? '?') . ': ' . implode(', ', $err['errors'] ?? [$err['warning'] ?? $err['error'] ?? json_encode($err)]));
            }
        }

        if ($failOnError && $import->failed_rows > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
