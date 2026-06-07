<?php

namespace App\Services\FraudTesting;

use App\Models\FraudTesting\FraudScenarioImport;
use App\Models\FraudTesting\FraudScenarioSubmission;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FraudWorkbookImportService
{
    public const REQUIRED_COLUMNS = ['Scenario ID', 'Title', 'Narrative'];
    public const OPTIONAL_COLUMNS = ['Source', 'Severity', 'Status'];
    public const ALL_COLUMNS = ['Scenario ID', 'Title', 'Narrative', 'Source', 'Severity', 'Status'];

    public const SEVERITY_VALUES = ['Low', 'Medium', 'High', 'Critical'];
    public const STATUS_VALUES = ['Draft', 'Ready', 'Sample', 'Imported', 'Needs Review'];

    public function importFromPath(string $filePath, ?string $uploadedById = null, bool $dryRun = false): FraudScenarioImport
    {
        $import = FraudScenarioImport::create([
            'id' => (string) Str::uuid(),
            'original_filename' => basename($filePath),
            'uploaded_by_id' => $uploadedById,
            'status' => FraudScenarioImport::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $result = $this->processWorksheet($worksheet, $import->id, $dryRun);

            $import->update([
                'status' => $result['failed_rows'] > 0
                    ? FraudScenarioImport::STATUS_COMPLETED_WITH_ERRORS
                    : FraudScenarioImport::STATUS_COMPLETED,
                'total_rows' => $result['total_rows'],
                'successful_rows' => $result['successful_rows'],
                'failed_rows' => $result['failed_rows'],
                'validation_errors' => $result['validation_errors'],
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $import->update([
                'status' => FraudScenarioImport::STATUS_FAILED,
                'validation_errors' => [['row' => 0, 'error' => $e->getMessage()]],
                'completed_at' => now(),
            ]);
        }

        return $import->fresh();
    }

    public function importFromContents(string $contents, string $extension, ?string $uploadedById = null, ?string $originalFilename = null, bool $dryRun = false): FraudScenarioImport
    {
        $tmpPath = sys_get_temp_dir() . '/' . Str::uuid() . '.' . $extension;
        file_put_contents($tmpPath, $contents);

        try {
            $import = $this->importFromPath($tmpPath, $uploadedById, $dryRun);
            if ($originalFilename) {
                $import->update(['original_filename' => $originalFilename]);
            }
            return $import->fresh();
        } finally {
            @unlink($tmpPath);
        }
    }

    private function processWorksheet(Worksheet $worksheet, string $importId, bool $dryRun): array
    {
        $rows = $worksheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return ['total_rows' => 0, 'successful_rows' => 0, 'failed_rows' => 0, 'validation_errors' => []];
        }

        $headers = array_map('trim', (array) $rows[0]);
        $dataRows = array_slice($rows, 1);

        $missingRequired = array_diff(self::REQUIRED_COLUMNS, $headers);
        if (! empty($missingRequired)) {
            throw new \InvalidArgumentException('Missing required columns: ' . implode(', ', $missingRequired));
        }

        $columnIndex = array_flip($headers);
        $seenIds = [];
        $totalRows = 0;
        $successfulRows = 0;
        $failedRows = 0;
        $validationErrors = [];

        foreach ($dataRows as $rowNumber => $row) {
            $rowNum = $rowNumber + 2;

            $mapped = $this->mapRow($row, $columnIndex);

            if ($this->isBlankRow($mapped)) {
                continue;
            }

            $totalRows++;
            $errors = $this->validateRow($mapped, $rowNum);

            if (! empty($errors)) {
                $failedRows++;
                $validationErrors[] = ['row' => $rowNum, 'errors' => $errors, 'data' => $mapped];
                continue;
            }

            $externalId = trim((string) ($mapped['Scenario ID'] ?? ''));
            if (isset($seenIds[$externalId])) {
                $validationErrors[] = ['row' => $rowNum, 'warning' => "Duplicate Scenario ID '{$externalId}' skipped"];
                $failedRows++;
                continue;
            }
            $seenIds[$externalId] = true;

            if (! $dryRun) {
                FraudScenarioSubmission::updateOrCreate(
                    ['external_scenario_id' => $externalId],
                    [
                        'id' => (string) Str::uuid(),
                        'import_id' => $importId,
                        'title' => trim((string) ($mapped['Title'] ?? '')),
                        'narrative' => trim((string) ($mapped['Narrative'] ?? '')),
                        'source' => trim((string) ($mapped['Source'] ?? '')) ?: null,
                        'severity' => trim((string) ($mapped['Severity'] ?? '')) ?: null,
                        'status' => FraudScenarioSubmission::STATUS_IMPORTED,
                        'extraction_status' => FraudScenarioSubmission::EXTRACTION_STATUS_PENDING,
                        'mock_data_status' => FraudScenarioSubmission::MOCK_DATA_STATUS_PENDING,
                        'review_status' => FraudScenarioSubmission::REVIEW_STATUS_UNREVIEWED,
                        'row_number' => $rowNum,
                        'raw_row' => $mapped,
                    ]
                );
            }

            $successfulRows++;
        }

        return [
            'total_rows' => $totalRows,
            'successful_rows' => $successfulRows,
            'failed_rows' => $failedRows,
            'validation_errors' => $validationErrors,
        ];
    }

    private function mapRow(array $row, array $columnIndex): array
    {
        $mapped = [];
        foreach (self::ALL_COLUMNS as $col) {
            $idx = $columnIndex[$col] ?? null;
            $mapped[$col] = ($idx !== null && isset($row[$idx])) ? $row[$idx] : null;
        }
        return $mapped;
    }

    private function isBlankRow(array $mapped): bool
    {
        foreach ($mapped as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function validateRow(array $mapped, int $rowNum): array
    {
        $errors = [];

        foreach (self::REQUIRED_COLUMNS as $col) {
            if (empty(trim((string) ($mapped[$col] ?? '')))) {
                $errors[] = "Row {$rowNum}: '{$col}' is required.";
            }
        }

        $severity = trim((string) ($mapped['Severity'] ?? ''));
        if ($severity !== '' && ! in_array($severity, self::SEVERITY_VALUES, true)) {
            $errors[] = "Row {$rowNum}: Invalid Severity '{$severity}'. Allowed: " . implode(', ', self::SEVERITY_VALUES);
        }

        return $errors;
    }
}
