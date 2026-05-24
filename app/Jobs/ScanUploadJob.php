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

class ScanUploadJob implements ShouldQueue
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
                'status' => 'scanning',
                'status_detail' => 'Scanning file contents...',
            ]);

            $disk = $upload->quarantine_bucket ?: config('filesystems.default', 'local');
            $contents = Storage::disk($disk)->get($upload->quarantine_key);

            if ($contents === null) {
                throw new Exception('File not found in storage at ' . $upload->quarantine_key);
            }

            $extension = strtolower($upload->file_extension ?: pathinfo($upload->filename, PATHINFO_EXTENSION));

            if ($extension === 'xlsx') {
                $sheetInventory = [['name' => 'Sheet1', 'rowCount' => 0, 'headers' => []]];
                $workbookMetadata = ['detectedContentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                $parserWarnings = [['code' => 'XLSX_NOT_PARSED', 'message' => 'XLSX parsing is not yet supported. Please export your data as CSV and re-upload.']];
                $samplePreview = [];
            } else {
                [$sheetInventory, $samplePreview, $parserWarnings] = $this->parseCsv($contents);
                $workbookMetadata = ['detectedContentType' => 'text/csv'];
            }

            DB::table('upload_inspections')->insert([
                'id' => (string) Str::uuid(),
                'upload_id' => $this->uploadId,
                'company_id' => $this->companyId,
                'workbook_metadata' => json_encode($workbookMetadata),
                'sheet_inventory' => json_encode($sheetInventory),
                'parser_warnings' => json_encode($parserWarnings),
                'sample_preview' => json_encode($samplePreview),
                'created_at' => now(),
            ]);

            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'inspected',
                'status_detail' => 'File inspected. Ready for mapping.',
                'scan_status' => 'clean',
                'inspected_at' => now(),
            ]);
        } catch (Exception $e) {
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'failed',
                'status_detail' => 'Inspection failed: ' . $e->getMessage(),
                'failure_code' => 'INSPECTION_ERROR',
            ]);
        }
    }

    private function parseCsv(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $headers = fgetcsv($stream) ?: [];
        $headers = array_map('trim', $headers);
        $headers = array_values(array_filter($headers, fn ($h) => $h !== ''));

        $rowCount = 0;
        $sampleRows = [];
        $parserWarnings = [];

        while (($row = fgetcsv($stream)) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }
            $rowCount++;

            if ($rowCount <= 3) {
                $mapped = [];
                foreach ($headers as $i => $header) {
                    $mapped[$header] = $row[$i] ?? '';
                }
                $sampleRows[] = $mapped;
            }
        }

        fclose($stream);

        if (count($headers) === 0) {
            $parserWarnings[] = ['code' => 'EMPTY_HEADERS', 'message' => 'No column headers detected in the first row.'];
        }

        $sheetInventory = [[
            'name' => 'Sheet1',
            'rowCount' => $rowCount,
            'headers' => $headers,
        ]];

        return [$sheetInventory, $sampleRows, $parserWarnings];
    }
}
