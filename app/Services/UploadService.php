<?php

namespace App\Services;

use App\Jobs\PromoteUploadJob;
use App\Jobs\ScanUploadJob;
use App\Jobs\ValidateUploadJob;
use App\Models\Upload;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UploadService
{
    private UploadStorageService $storage;
    private PlanPolicyService $planPolicy;

    public const SUPPORTED_IMPORT_TYPES = [
        'transaction_ledger', 'ap_invoice_register', 'ar_aging',
    ];

    public function __construct(UploadStorageService $storage, PlanPolicyService $planPolicy)
    {
        $this->storage = $storage;
        $this->planPolicy = $planPolicy;
    }

    public function createSession(string $companyId, string $userId, array $data, ?string $businessProfileId = null): array
    {
        $originalFilename = $data['originalFilename'];
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (! in_array($extension, ['csv', 'xlsx'])) {
            throw new Exception('Only .csv and .xlsx files are supported', 400);
        }

        $claimedContentType = $data['claimedContentType'] ?? 'application/octet-stream';
        $fileSizeBytes = $data['fileSizeBytes'] ?? null;
        $this->planPolicy->ensureUploadFileSizeAllowed($companyId, $fileSizeBytes !== null ? (int) $fileSizeBytes : null);

        $attributes = [
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
        ];

        if ($businessProfileId && Schema::hasColumn('uploads', 'business_profile_id')) {
            $attributes['business_profile_id'] = $businessProfileId;
        }

        $upload = Upload::create($attributes);

        $contextPath = $businessProfileId ?: $companyId;
        $quarantineKey = "quarantine/{$contextPath}/{$upload->id}_{$originalFilename}";
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
                'maxFileSizeBytes' => $this->planPolicy->uploadMaxFileSizeBytes($companyId),
                'acceptedExtensions' => ['.csv', '.xlsx'],
            ],
        ];
    }

    public function completeDirectUpload(string $companyId, string $userId, string $uploadId, ?string $businessProfileId = null): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId, $businessProfileId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }
        if (! $upload->quarantine_key) {
            throw new Exception('Upload is missing a quarantine object key', 400);
        }

        $stat = $this->storage->statStoredObject($upload->quarantine_key);
        $size = $stat['size'] ?? ($upload->file_size_bytes ?: 0);
        $this->planPolicy->ensureUploadFileSizeAllowed($companyId, (int) $size);

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

    public function getDetail(string $companyId, string $uploadId, ?string $businessProfileId = null): ?array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId, $businessProfileId);
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

    public function list(string $companyId, ?string $businessProfileId = null): array
    {
        $query = Upload::where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('uploads', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        return $query->orderBy('created_at', 'desc')
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

    public function delete(string $companyId, string $userId, string $uploadId, ?string $businessProfileId = null): bool
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId, $businessProfileId);
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

    public function getPreview(string $companyId, string $uploadId, ?string $businessProfileId = null): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId, $businessProfileId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }

        $inspection = DB::table('upload_inspections')
            ->where('upload_id', $uploadId)
            ->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('upload_inspections', 'business_profile_id')) {
            $inspection->where('business_profile_id', $businessProfileId);
        }
        $inspection = $inspection->orderBy('created_at', 'desc')->first();
        if (! $inspection) {
            throw new Exception('Upload inspection is not ready yet', 409);
        }

        $sheets = json_decode($inspection->sheet_inventory, true) ?? [];
        $firstSheet = $sheets[0] ?? [];
        $headers = $firstSheet['headers'] ?? [];

        [$fieldMappings, $confidenceHints] = $this->suggestMappings($headers, $upload->import_type);

        return [
            'uploadId' => $uploadId,
            'importType' => $upload->import_type,
            'inspection' => [
                'detectedContentType' => json_decode($inspection->workbook_metadata)->detectedContentType ?? 'application/octet-stream',
                'sheets' => $sheets,
                'parserWarnings' => json_decode($inspection->parser_warnings, true) ?? [],
                'samplePreview' => json_decode($inspection->sample_preview, true) ?? [],
            ],
            'mappingSuggestion' => [
                'sourceSheetName' => $firstSheet['name'] ?? null,
                'headerRowIndex' => 1,
                'fieldMappings' => $fieldMappings,
                'confidenceHints' => $confidenceHints,
            ],
        ];
    }

    private function suggestMappings(array $headers, ?string $importType): array
    {
        $aliases = [
            'date' => ['date', 'transaction date', 'txn date', 'posted date', 'posting date', 'value date'],
            'vendor_customer' => ['vendor', 'vendor name', 'customer', 'payee', 'description', 'merchant', 'name'],
            'amount' => ['amount', 'total', 'total amount', 'debit', 'credit', 'value', 'sum'],
            'type' => ['type', 'transaction type', 'txn type'],
            'category' => ['category', 'account', 'account name', 'class'],
            'payment_method' => ['payment method', 'method', 'payment type', 'pay method'],
            'department' => ['department', 'dept', 'division', 'cost center'],
            'invoice_ref' => ['invoice', 'invoice number', 'invoice ref', 'inv num', 'reference', 'ref'],
            'memo' => ['memo', 'notes', 'note', 'comment', 'remarks'],
            'txn_id' => ['id', 'transaction id', 'txn id', 'transaction number', 'txn number', 'check number'],
        ];

        $lowerHeaders = array_map('strtolower', $headers);
        $fieldMappings = [];
        $confidenceHints = [];

        foreach ($aliases as $targetField => $fieldAliases) {
            $matched = null;
            $confidence = 'none';

            foreach ($headers as $i => $header) {
                $lower = $lowerHeaders[$i];
                if (in_array($lower, $fieldAliases, true)) {
                    $matched = $header;
                    $confidence = 'high';
                    break;
                }
            }

            if (! $matched) {
                foreach ($headers as $i => $header) {
                    $lower = $lowerHeaders[$i];
                    foreach ($fieldAliases as $alias) {
                        if (str_contains($lower, $alias) || str_contains($alias, $lower)) {
                            $matched = $header;
                            $confidence = 'medium';
                            break 2;
                        }
                    }
                }
            }

            $fieldMappings[$targetField] = $matched;
            if ($matched) {
                $confidenceHints[$targetField] = ['confidence' => $confidence];
            }
        }

        return [$fieldMappings, $confidenceHints];
    }

    public function saveMapping(string $companyId, string $userId, string $uploadId, array $data, ?string $businessProfileId = null): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId, $businessProfileId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }

        $nextVersion = DB::table('upload_mapping_versions')->where('upload_id', $uploadId)->max('version_number') + 1;

        $mapping = [
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'version_number' => $nextVersion,
            'import_type' => $upload->import_type,
            'source_sheet_name' => $data['sourceSheetName'],
            'field_mappings' => json_encode($data['fieldMappings']),
            'confirmed_by' => $userId,
            'created_at' => now(),
        ];
        if ($businessProfileId && Schema::hasColumn('upload_mapping_versions', 'business_profile_id')) {
            $mapping['business_profile_id'] = $businessProfileId;
        }

        $mappingId = DB::table('upload_mapping_versions')->insertGetId($mapping);

        $upload->update([
            'latest_mapping_version_id' => $mappingId,
            'status' => 'awaiting_mapping',
            'status_detail' => 'Mapping saved. Ready for validation.',
            'failure_code' => null,
        ]);

        return ['id' => $mappingId, 'versionNumber' => $nextVersion];
    }

    public function queueValidation(string $companyId, string $userId, string $uploadId, ?string $businessProfileId = null): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId, $businessProfileId);
        if (! $upload) {
            throw new Exception('Upload not found', 404);
        }
        if (! $upload->latest_mapping_version_id) {
            throw new Exception('Upload mapping is missing', 409);
        }

        ValidateUploadJob::dispatch($uploadId, $companyId, $userId);

        return ['status' => 'validation_pending', 'statusDetail' => 'Validation has been queued.'];
    }

    public function queuePromotion(string $companyId, string $userId, string $uploadId, ?string $businessProfileId = null): array
    {
        $upload = $this->findCompanyUpload($companyId, $uploadId, $businessProfileId);
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

    private function findCompanyUpload(string $companyId, string $uploadId, ?string $businessProfileId = null): ?Upload
    {
        $query = Upload::where('id', $uploadId)
            ->where('company_id', $companyId);

        if ($businessProfileId && Schema::hasColumn('uploads', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        return $query->first();
    }
}
