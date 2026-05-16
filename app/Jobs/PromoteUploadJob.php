<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Exception;

class PromoteUploadJob implements ShouldQueue
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
                'status' => 'promoting',
                'status_detail' => 'Importing data into transactions...',
            ]);

            // Simulate promoting rows to the transaction ledger
            sleep(2);

            $batchId = DB::table('import_batches')->insertGetId([
                'upload_id' => $this->uploadId,
                'company_id' => $this->companyId,
                'mapping_version_id' => $upload->latest_mapping_version_id,
                'validation_run_id' => $upload->latest_validation_run_id,
                'trusted_target_domain' => $upload->import_type ?? 'transaction_ledger',
                'imported_row_count' => 100,
                'promoted_by' => $this->userId,
                'promoted_at' => now(),
            ]);

            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'promoted',
                'status_detail' => 'Data successfully imported.',
                'row_count' => 100,
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
}
