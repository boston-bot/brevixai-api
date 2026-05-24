<?php

namespace App\Services;

use App\Jobs\PromoteUploadJob;
use App\Jobs\ScanUploadJob;
use App\Jobs\ValidateUploadJob;
use App\Models\Upload;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UploadService
{
    private UploadStorageService $storage;

    public const SUPPORTED_IMPORT_TYPES = [
        'transaction_ledger', 'ap_invoice_register', 'ar_aging',
    ];

    public function __construct(UploadStorageService $storage)
    {
        $this->storage = $storage;
    }

    public function createSession(string $companyId, string $userId, array $data): array
    {
        $originalFilename = $data['originalFilename'];
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (! in_array($extension, ['csv', 'xlsx'])) {
            throw new Exception('Only .csv and .xlsx files are supported', 400);
        }

        $claimedContentType = $data['claimedContentType'] ?? 'application/octet-stream';
        $fileSizeBytes = $data['fileSizeBytes'] ?? null;

        $upload = Upload::create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'uploaded_by' => $userId,
            'import_type' => $data['importType'],
            'filename' => $originalFilename,
            'original_filename' => $originalFilename,
            'claimed_content_type' => $claimedContentType,
            'file_extension' => $extension,
            'file_size' => $fileSizeBytes,
            'file_size_bytes' => $fileSizeBytes,
            'status' => 'created',
            'status_detail' => 'Upload session created.',
            'quarantine_bucket' => config('filesystems.default', 'local'),
        ]);

        $quarantineKey = "quarantine/{$companyId}/{$upload->id}_{$originalFilename}";
        $storageFilename = "{$upload->id}.{$extension}";

        $upload->update([
            'quarantine_key' => $quarantineKey,
            'storage_filename' => $storageFilename,
        ]);

        $uploadUrl = $this->storage->createPresignedUploadUrl($quarantineKey);

        $this->writeAuditLog($companyId, $userId, 'UPLOAD_CREATED', $upload->id, [
            'importType' => $data['importType'],
            'quarantineKey' => $quarantineKey,
            'claimedContentType' => $claimedContentType,
        ]);

        return [
            'uploadId' => $upload->id,
            'importType' => $data['importType'],
            'uploadUrl' => $uploadUrl,
            'uploadMethod' => 'PUT',
            'uploadHeaders' => ['Content-Type' => $claimedContentType],
            'quarantineKey' => $quarantineKey,
            'acceptedConstraints' => [
                'maxFileSizeBytes' => 104857600, // 100MB
                'acceptedExtensions' => ['.csv', '.xlsx'],
            ],
        ];
    }

    public function completeDirectUpload(string $companyId, string $userId, string $uploadId): array
    {
        $upload = Upload::where('id', $uploadId)->where('company_id', $companyId)->first();
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }
        if (! $upload->quarantine_key) {
            throw new Exception('Upload is missing a quarantine object key', 400);
        }

        $stat = $this->storage->statStoredObject($upload->quarantine_key);
        $size = $stat['size'] ?? ($upload->file_size_bytes ?: 0);

        $upload->update([
            'status' => 'uploaded_to_quarantine',
            'status_detail' => 'Upload completed and is waiting for quarantine scan.',
            'uploaded_at' => now(),
            'file_size' => $size,
            'file_size_bytes' => $size,
        ]);

        $this->writeAuditLog($companyId, $userId, 'UPLOAD_COMPLETED', $upload->id, [
            'quarantineKey' => $upload->quarantine_key,
            'fileSizeBytes' => $size,
        ]);

        ScanUploadJob::dispatch($upload->id, $companyId, $userId);

        return [
            'uploadId' => $upload->id,
            'status' => 'uploaded_to_quarantine',
            'statusDetail' => 'Upload completed. Quarantine scan has been queued.',
        ];
    }

    public function getDetail(string $companyId, string $uploadId): ?array
    {
        $upload = Upload::where('id', $uploadId)->where('company_id', $companyId)->first();
        if (! $upload) {
            return null;
        }

        // Fetch related inspection, mapping, validation, and batch (stubbed for brevity as per architecture constraints,
        // typically joining on upload_inspections, upload_mapping_versions, etc.)

        return [
            'id' => $upload->id,
            'importType' => $upload->import_type,
            'filename' => $upload->original_filename ?: $upload->filename,
            'fileSizeBytes' => $upload->file_size_bytes ?: ($upload->file_size ?: 0),
            'status' => $upload->status,
            'statusDetail' => $upload->status_detail,
            'failureCode' => $upload->failure_code,
            'scanStatus' => $upload->scan_status,
            'quarantineKey' => $upload->quarantine_key,
            'createdAt' => $upload->created_at,
            'uploadedAt' => $upload->uploaded_at,
            'scannedAt' => $upload->scanned_at,
            'inspectedAt' => $upload->inspected_at,
            'validatedAt' => $upload->validated_at,
            'promotedAt' => $upload->promoted_at,
            'latestMappingVersion' => null, // Placeholder for mappings table
            'latestValidationRun' => null, // Placeholder for validation runs table
            'latestImportBatch' => null, // Placeholder for import batches table
        ];
    }

    public function list(string $companyId): array
    {
        return Upload::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($upload) {
                return [
                    'id' => $upload->id,
                    'importType' => $upload->import_type,
                    'filename' => $upload->original_filename ?: $upload->filename,
                    'fileSizeBytes' => (int) ($upload->file_size_bytes ?: ($upload->file_size ?: 0)),
                    'status' => $upload->status,
                    'statusDetail' => $upload->status_detail,
                    'rowCount' => (int) ($upload->row_count ?: 0),
                    'createdAt' => $upload->created_at,
                    'uploadedAt' => $upload->uploaded_at,
                    'promotedAt' => $upload->promoted_at,
                ];
            })->toArray();
    }

    public function delete(string $companyId, string $userId, string $uploadId): bool
    {
        $upload = Upload::where('id', $uploadId)->where('company_id', $companyId)->first();
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }

        if ($upload->quarantine_key) {
            try {
                $this->storage->removeStoredObject($upload->quarantine_key);
            } catch (\Throwable $e) {
                // Log and ignore storage failure
            }
        }

        $upload->delete();

        // Cleanup orphaned alerts that had transaction evidence but no underlying transaction anymore
        DB::statement("
            DELETE FROM alerts
            WHERE company_id = ?
            AND evidence IS NOT NULL
            AND evidence->'transactionIds' IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM transactions t
                WHERE t.company_id = ?
                AND t.id::text = ANY(
                    SELECT jsonb_array_elements_text(evidence->'transactionIds')
                )
            )
        ", [$companyId, $companyId]);

        $this->writeAuditLog($companyId, $userId, 'UPLOAD_DELETED', $uploadId, [
            'quarantineKey' => $upload->quarantine_key,
        ]);

        return true;
    }

    public function getPreview(string $companyId, string $uploadId): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }

        $inspection = DB::table('upload_inspections')
            ->where('upload_id', $uploadId)
            ->where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->first();
        if (! $inspection) {
            throw new Exception('Upload inspection is not ready yet', 409);
        }

        return [
            'uploadId' => $uploadId,
            'importType' => $upload->import_type,
            'inspection' => [
                'detectedContentType' => json_decode($inspection->workbook_metadata)->detectedContentType ?? 'application/octet-stream',
                'sheets' => json_decode($inspection->sheet_inventory),
                'parserWarnings' => json_decode($inspection->parser_warnings),
            ],
        ];
    }

    public function saveMapping(string $companyId, string $userId, string $uploadId, array $data): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }

        $nextVersion = DB::table('upload_mapping_versions')->where('upload_id', $uploadId)->max('version_number') + 1;

        $mappingId = DB::table('upload_mapping_versions')->insertGetId([
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'version_number' => $nextVersion,
            'import_type' => $upload->import_type,
            'source_sheet_name' => $data['sourceSheetName'],
            'field_mappings' => json_encode($data['fieldMappings']),
            'confirmed_by' => $userId,
            'created_at' => now(),
        ]);

        $upload->update([
            'latest_mapping_version_id' => $mappingId,
            'status' => 'awaiting_mapping',
            'status_detail' => 'Mapping saved. Ready for validation.',
            'failure_code' => null,
        ]);

        return ['id' => $mappingId, 'versionNumber' => $nextVersion];
    }

    public function queueValidation(string $companyId, string $userId, string $uploadId): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }
        if (! $upload->latest_mapping_version_id) {
            throw new Exception('Upload mapping is missing', 409);
        }

        ValidateUploadJob::dispatch($uploadId, $companyId, $userId);

        return ['status' => 'validation_pending', 'statusDetail' => 'Validation has been queued.'];
    }

    public function queuePromotion(string $companyId, string $userId, string $uploadId): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }
        if ($upload->status !== 'validated') {
            throw new Exception('Upload must be validated before promotion', 409);
        }

        PromoteUploadJob::dispatch($uploadId, $companyId, $userId);

        return ['status' => 'promotion_pending', 'statusDetail' => 'Promotion has been queued.'];
    }

    private function writeAuditLog(string $companyId, string $userId, string $eventType, string $uploadId, array $payload = []): void
    {
        // Minimal stub
    }

    private function findCompanyUpload(string $companyId, string $uploadId): ?Upload
    {
        return Upload::where('id', $uploadId)
            ->where('company_id', $companyId)
            ->first();
    }
}
