<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Exception;

class ValidateUploadJob implements ShouldQueue
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
                'status' => 'validating',
                'status_detail' => 'Validating data against mappings...',
            ]);

            // Simulate row-by-row validation
            sleep(2);

            $mappingId = $upload->latest_mapping_version_id;

            $validationRunId = DB::table('upload_validation_runs')->insertGetId([
                'upload_id' => $this->uploadId,
                'company_id' => $this->companyId,
                'mapping_version_id' => $mappingId,
                'status' => 'validated',
                'total_row_count' => 100,
                'valid_row_count' => 100,
                'completed_at' => now(),
                'created_at' => now(),
            ]);

            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'validated',
                'status_detail' => 'Validation complete. Ready to promote.',
                'latest_validation_run_id' => $validationRunId,
                'validated_at' => now(),
            ]);

        } catch (Exception $e) {
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'status' => 'failed',
                'status_detail' => 'Validation failed: ' . $e->getMessage(),
                'failure_code' => 'VALIDATION_ERROR',
            ]);
        }
    }
}
