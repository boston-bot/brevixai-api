<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class PromoteUploadJob implements ShouldQueue
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
                'status' => 'promoting',
                'status_detail' => 'Importing data into transactions...',
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
                throw new Exception('XLSX promotion is not yet supported. Please use CSV format.', 422);
            }

            $disk = $upload->quarantine_bucket ?: config('filesystems.default', 'local');
            $contents = Storage::disk($disk)->get($upload->quarantine_key);

            if ($contents === null) {
                throw new Exception('File not found in storage');
            }

            $importBatchId = (string) Str::uuid();
            $sheetName = $mappingRow->source_sheet_name ?? 'Sheet1';

            $importedCount = $this->importRows($contents, $fieldMappings, $importBatchId, $sheetName);

            DB::table('import_batches')->insert([
                'id' => $importBatchId,
                'upload_id' => $this->uploadId,
                'company_id' => $this->companyId,
                'mapping_version_id' => $upload->latest_mapping_version_id,
                'validation_run_id' => $upload->latest_validation_run_id,
                'trusted_target_domain' => $upload->import_type ?? 'transaction_ledger',
                'imported_row_count' => $importedCount,
                'promoted_by' => $this->userId,
                'promoted_at' => now(),
            ]);

            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'promoted',
                'status_detail' => "Data successfully imported. {$importedCount} transactions created.",
                'row_count' => $importedCount,
                'promoted_at' => now(),
            ]);
        } catch (Exception $e) {
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'failed',
                'status_detail' => 'Promotion failed: ' . $e->getMessage(),
                'failure_code' => 'PROMOTION_ERROR',
            ]);
        }
    }

    private function importRows(string $contents, array $fieldMappings, string $importBatchId, string $sheetName): int
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $headers = array_map('trim', fgetcsv($stream) ?: []);

        $rows = [];
        $rowNumber = 1;

        while (($row = fgetcsv($stream)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rowNumber++;

            $rowData = [];
            foreach ($headers as $i => $header) {
                $rowData[$header] = $row[$i] ?? '';
            }

            $mapped = [];
            foreach ($fieldMappings as $targetField => $sourceColumn) {
                if ($sourceColumn !== null && $sourceColumn !== '') {
                    $mapped[$targetField] = $rowData[$sourceColumn] ?? null;
                }
            }

            if (isset($mapped['amount'])) {
                $cleaned = preg_replace('/[^0-9.\-]/', '', $mapped['amount'] ?? '');
                $mapped['amount'] = is_numeric($cleaned) ? (float) $cleaned : null;
            }

            if (isset($mapped['date'])) {
                $ts = strtotime($mapped['date'] ?? '');
                $mapped['date'] = $ts !== false ? date('Y-m-d', $ts) : null;
            }

            $rawJson = json_encode($rowData);
            $contentHash = hash('sha256', $this->uploadId . ':' . $rowNumber . ':' . $rawJson);

            $rows[] = array_merge($mapped, [
                'id' => (string) Str::uuid(),
                'upload_id' => $this->uploadId,
                'company_id' => $this->companyId,
                'import_batch_id' => $importBatchId,
                'source_sheet_name' => $sheetName,
                'source_row_number' => $rowNumber,
                'raw_row' => $rawJson,
                'row_content_hash' => $contentHash,
                'validation_status' => 'promoted',
            ]);
        }

        fclose($stream);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }

        return count($rows);
    }
}
