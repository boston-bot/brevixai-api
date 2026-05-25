<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class ValidateUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    protected string $uploadId;

    protected string $companyId;

    protected string $userId;

    public function __construct(string $uploadId, string $companyId, string $userId)
    {
        $this->uploadId = $uploadId;
        $this->companyId = $companyId;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        $upload = DB::table('uploads')
            ->where('id', $this->uploadId)
            ->where('company_id', $this->companyId)
            ->first();

        if (! $upload) {
            return;
        }

        try {
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'validating',
                'status_detail' => 'Validating data against mappings...',
            ]);

            $mappingRow = DB::table('upload_mapping_versions')
                ->where('id', $upload->latest_mapping_version_id)
                ->first();

            if (! $mappingRow) {
                throw new Exception('No mapping version found for this upload', 409);
            }

            $fieldMappings = json_decode($mappingRow->field_mappings, true) ?: [];
            $extension = strtolower($upload->file_extension ?: pathinfo($upload->filename, PATHINFO_EXTENSION));

            if ($extension === 'xlsx') {
                throw new Exception('XLSX validation is not yet supported. Please use CSV format.', 422);
            }

            $disk = $upload->quarantine_bucket ?: config('filesystems.default', 'local');
            $contents = Storage::disk($disk)->get($upload->quarantine_key);

            if ($contents === null) {
                throw new Exception('File not found in storage');
            }

            $validationRunId = (string) Str::uuid();
            $businessProfileId = property_exists($upload, 'business_profile_id') ? $upload->business_profile_id : null;

            $validationRun = [
                'id' => $validationRunId,
                'upload_id' => $this->uploadId,
                'company_id' => $this->companyId,
                'mapping_version_id' => $mappingRow->id,
                'status' => 'pending',
                'total_row_count' => 0,
                'valid_row_count' => 0,
                'invalid_row_count' => 0,
                'blocking_error_count' => 0,
                'warning_count' => 0,
                'summary' => json_encode([]),
                'created_at' => now(),
            ];
            if ($businessProfileId && Schema::hasColumn('upload_validation_runs', 'business_profile_id')) {
                $validationRun['business_profile_id'] = $businessProfileId;
            }

            DB::table('upload_validation_runs')->insert($validationRun);

            [$totalRows, $validRows, $blockingErrors, $warnings, $rowErrors] =
                $this->validateRows($contents, $fieldMappings, $validationRunId, $mappingRow->source_sheet_name ?? 'Sheet1', $businessProfileId);

            foreach (array_chunk($rowErrors, 500) as $chunk) {
                DB::table('upload_row_errors')->insert($chunk);
            }

            $runStatus = $blockingErrors > 0 ? 'failed' : 'validated';

            DB::table('upload_validation_runs')
                ->where('id', $validationRunId)
                ->update([
                    'status' => $runStatus,
                    'total_row_count' => $totalRows,
                    'valid_row_count' => $validRows,
                    'invalid_row_count' => $totalRows - $validRows,
                    'blocking_error_count' => $blockingErrors,
                    'warning_count' => $warnings,
                    'summary' => json_encode([
                        'totalRows' => $totalRows,
                        'validRows' => $validRows,
                        'blockingErrors' => $blockingErrors,
                        'warnings' => $warnings,
                    ]),
                    'completed_at' => now(),
                ]);

            if ($blockingErrors > 0) {
                DB::table('uploads')->where('id', $this->uploadId)->update([
                    'status' => 'failed',
                    'status_detail' => "Validation failed: {$blockingErrors} blocking error(s) found in {$totalRows} rows.",
                    'failure_code' => 'VALIDATION_ERRORS',
                    'latest_validation_run_id' => $validationRunId,
                ]);
            } else {
                DB::table('uploads')->where('id', $this->uploadId)->update([
                    'status' => 'validated',
                    'status_detail' => "Validation complete. {$validRows} of {$totalRows} rows ready to promote.",
                    'latest_validation_run_id' => $validationRunId,
                    'validated_at' => now(),
                ]);
            }
        } catch (Exception $e) {
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'failed',
                'status_detail' => 'Validation failed: ' . $e->getMessage(),
                'failure_code' => 'VALIDATION_ERROR',
            ]);
        }
    }

    private function validateRows(string $contents, array $fieldMappings, string $validationRunId, string $sheetName, ?string $businessProfileId): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $headers = array_map('trim', fgetcsv($stream) ?: []);

        $totalRows = 0;
        $validRows = 0;
        $blockingErrors = 0;
        $warnings = 0;
        $rowErrors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($stream)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rowNumber++;
            $totalRows++;

            $rowData = [];
            foreach ($headers as $i => $header) {
                $rowData[$header] = $row[$i] ?? '';
            }

            $rowIsValid = true;

            if (isset($fieldMappings['amount']) && $fieldMappings['amount'] !== null) {
                $amountRaw = trim($rowData[$fieldMappings['amount']] ?? '');
                if ($amountRaw !== '') {
                    $cleaned = preg_replace('/[^0-9.\-]/', '', $amountRaw);
                    if (! is_numeric($cleaned) || $cleaned === '') {
                        $error = [
                            'id' => (string) Str::uuid(),
                            'upload_id' => $this->uploadId,
                            'company_id' => $this->companyId,
                            'validation_run_id' => $validationRunId,
                            'source_sheet_name' => $sheetName,
                            'source_row_number' => $rowNumber,
                            'canonical_field_key' => 'amount',
                            'severity' => 'blocking',
                            'error_code' => 'INVALID_AMOUNT',
                            'message' => "Amount '{$amountRaw}' is not a valid number",
                            'raw_value' => $amountRaw,
                            'created_at' => now(),
                        ];
                        if ($businessProfileId && Schema::hasColumn('upload_row_errors', 'business_profile_id')) {
                            $error['business_profile_id'] = $businessProfileId;
                        }
                        $rowErrors[] = $error;
                        $blockingErrors++;
                        $rowIsValid = false;
                    }
                }
            }

            if (isset($fieldMappings['date']) && $fieldMappings['date'] !== null) {
                $dateRaw = trim($rowData[$fieldMappings['date']] ?? '');
                if ($dateRaw !== '' && strtotime($dateRaw) === false) {
                    $error = [
                        'id' => (string) Str::uuid(),
                        'upload_id' => $this->uploadId,
                        'company_id' => $this->companyId,
                        'validation_run_id' => $validationRunId,
                        'source_sheet_name' => $sheetName,
                        'source_row_number' => $rowNumber,
                        'canonical_field_key' => 'date',
                        'severity' => 'warning',
                        'error_code' => 'UNPARSEABLE_DATE',
                        'message' => "Date '{$dateRaw}' could not be parsed",
                        'raw_value' => $dateRaw,
                        'created_at' => now(),
                    ];
                    if ($businessProfileId && Schema::hasColumn('upload_row_errors', 'business_profile_id')) {
                        $error['business_profile_id'] = $businessProfileId;
                    }
                    $rowErrors[] = $error;
                    $warnings++;
                }
            }

            if ($rowIsValid) {
                $validRows++;
            }
        }

        fclose($stream);

        return [$totalRows, $validRows, $blockingErrors, $warnings, $rowErrors];
    }
}
