<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Exception;

class ScanUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        if (!$upload) {
            return;
        }

        try {
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'scanning',
                'status_detail' => 'Scanning file contents...',
            ]);

            // Simulate file download, AV scan, and schema inspection
            sleep(2);

            $inspectionId = DB::table('upload_inspections')->insertGetId([
                'upload_id' => $this->uploadId,
                'company_id' => $this->companyId,
                'workbook_metadata' => json_encode(['detectedContentType' => 'text/csv']),
                'sheet_inventory' => json_encode([['name' => 'Sheet1', 'rowCount' => 100]]),
                'created_at' => now()
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
}
