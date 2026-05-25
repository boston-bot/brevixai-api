<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataSourceRegistryService
{
    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     sources: list<array<string, mixed>>
     * }
     */
    public function forContext(string $companyId, ?string $businessProfileId = null): array
    {
        $sources = array_merge(
            $this->fileUploadSources($companyId, $businessProfileId),
            $this->quickBooksSources($companyId, $businessProfileId),
            $this->gnuCashSources($companyId, $businessProfileId),
        );

        return [
            'summary' => [
                'sourceCount' => count($sources),
                'hasFinancialData' => $this->hasFinancialData($sources),
                'hasDocumentEvidence' => $this->hasDocumentEvidence($sources),
                'lastSourceReceivedAt' => $this->latestReceivedAt($sources),
            ],
            'sources' => $sources,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fileUploadSources(string $companyId, ?string $businessProfileId): array
    {
        if (! Schema::hasTable('uploads')) {
            return [];
        }

        $query = DB::table('uploads')->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('uploads', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        $orderColumn = Schema::hasColumn('uploads', 'created_at') ? 'created_at' : 'id';

        return $query
            ->orderByDesc($orderColumn)
            ->limit(50)
            ->get()
            ->map(function (object $upload): array {
                $statusCategory = $this->statusCategory((string) ($upload->status ?? 'received'));
                $receivedAt = $upload->promoted_at ?? $upload->validated_at ?? $upload->uploaded_at ?? $upload->created_at ?? null;

                return [
                    'sourceType' => 'file_upload',
                    'sourceId' => (string) ($upload->id ?? ''),
                    'label' => (string) (($upload->original_filename ?? null) ?: ($upload->filename ?? 'File upload')),
                    'status' => (string) ($upload->status ?? 'received'),
                    'statusCategory' => $statusCategory,
                    'receivedAt' => $receivedAt ? (string) $receivedAt : null,
                    'metadata' => [
                        'importType' => $upload->import_type ?? null,
                        'rowCount' => (int) ($upload->row_count ?? 0),
                        'statusDetail' => $upload->status_detail ?? null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickBooksSources(string $companyId, ?string $businessProfileId): array
    {
        $sources = [];

        if (Schema::hasTable('integrations')) {
            $query = DB::table('integrations')
                ->where('company_id', $companyId)
                ->where('provider', 'quickbooks');
            if ($businessProfileId && Schema::hasColumn('integrations', 'business_profile_id')) {
                $query->where('business_profile_id', $businessProfileId);
            }

            foreach ($query->orderByDesc('updated_at')->limit(10)->get() as $integration) {
                $transactionCount = $this->quickBooksTransactionCount($companyId, $businessProfileId, $integration->realm_id ?? null);
                $syncStatus = (string) ($integration->sync_status ?? 'connected');
                $statusCategory = $transactionCount > 0 ? 'received' : $this->connectedWithoutDataCategory($syncStatus);

                $sources[] = [
                    'sourceType' => 'quickbooks',
                    'sourceId' => (string) (($integration->realm_id ?? null) ?: ($integration->id ?? 'quickbooks')),
                    'label' => 'QuickBooks Online',
                    'status' => $syncStatus,
                    'statusCategory' => $statusCategory,
                    'receivedAt' => isset($integration->updated_at) ? (string) $integration->updated_at : null,
                    'metadata' => [
                        'realmId' => $integration->realm_id ?? null,
                        'transactionCount' => $transactionCount,
                        'syncProgress' => isset($integration->sync_progress) ? (int) $integration->sync_progress : null,
                    ],
                ];
            }
        }

        if ($sources === [] && Schema::hasTable('qbo_integrations')) {
            $query = DB::table('qbo_integrations')->where('company_id', $companyId);
            if ($businessProfileId && Schema::hasColumn('qbo_integrations', 'business_profile_id')) {
                $query->where('business_profile_id', $businessProfileId);
            }

            foreach ($query->orderByDesc('updated_at')->limit(10)->get() as $integration) {
                $transactionCount = $this->quickBooksTransactionCount($companyId, $businessProfileId, $integration->realm_id ?? null);
                $status = (string) ($integration->sync_status ?? $integration->status ?? 'connected');

                $sources[] = [
                    'sourceType' => 'quickbooks',
                    'sourceId' => (string) (($integration->realm_id ?? null) ?: ($integration->id ?? 'quickbooks')),
                    'label' => 'QuickBooks Online',
                    'status' => $status,
                    'statusCategory' => $transactionCount > 0 ? 'received' : $this->connectedWithoutDataCategory($status),
                    'receivedAt' => (string) ($integration->last_sync_at ?? $integration->updated_at ?? null),
                    'metadata' => [
                        'realmId' => $integration->realm_id ?? null,
                        'transactionCount' => $transactionCount,
                    ],
                ];
            }
        }

        return $sources;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gnuCashSources(string $companyId, ?string $businessProfileId): array
    {
        if (! Schema::hasTable('gnucash_imports')) {
            return [];
        }

        $importsAreProfileScoped = Schema::hasColumn('gnucash_imports', 'business_profile_id');
        $query = DB::table('gnucash_imports')->where('company_id', $companyId);
        if ($businessProfileId && $importsAreProfileScoped) {
            $query->where('business_profile_id', $businessProfileId);
        }

        return $query
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (object $import) use ($companyId, $businessProfileId, $importsAreProfileScoped): ?array {
                $transactionCount = $businessProfileId && ! $importsAreProfileScoped
                    ? $this->gnuCashTransactionCount($companyId, $businessProfileId, $import->id ?? null)
                    : (int) ($import->transaction_count ?? $this->gnuCashTransactionCount($companyId, $businessProfileId, $import->id ?? null));

                if ($businessProfileId && ! $importsAreProfileScoped && $transactionCount === 0) {
                    return null;
                }

                $status = (string) ($import->status ?? 'completed');

                return [
                    'sourceType' => 'gnucash',
                    'sourceId' => (string) ($import->id ?? 'gnucash'),
                    'label' => (string) (($import->filename ?? null) ?: 'GnuCash Import'),
                    'status' => $status,
                    'statusCategory' => $transactionCount > 0 ? 'received' : $this->statusCategory($status),
                    'receivedAt' => isset($import->created_at) ? (string) $import->created_at : null,
                    'metadata' => [
                        'fileFormat' => $import->file_format ?? null,
                        'transactionCount' => $transactionCount,
                        'dateRangeStart' => $import->date_range_start ?? null,
                        'dateRangeEnd' => $import->date_range_end ?? null,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function quickBooksTransactionCount(string $companyId, ?string $businessProfileId, mixed $realmId): int
    {
        if (! Schema::hasTable('qbo_transactions')) {
            return 0;
        }

        $query = DB::table('qbo_transactions')->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('qbo_transactions', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }
        if (is_string($realmId) && $realmId !== '' && Schema::hasColumn('qbo_transactions', 'realm_id')) {
            $query->where('realm_id', $realmId);
        }

        return (int) $query->count();
    }

    private function gnuCashTransactionCount(string $companyId, ?string $businessProfileId, mixed $importId): int
    {
        if (! Schema::hasTable('gnucash_transactions')) {
            return 0;
        }

        $query = DB::table('gnucash_transactions')->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('gnucash_transactions', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }
        if (is_string($importId) && $importId !== '' && Schema::hasColumn('gnucash_transactions', 'import_id')) {
            $query->where('import_id', $importId);
        }

        return (int) $query->count();
    }

    private function statusCategory(string $status): string
    {
        return match ($status) {
            'promoted', 'validated', 'completed', 'complete', 'synced', 'idle', 'connected', 'active' => 'received',
            'created', 'pending', 'processing', 'uploaded_to_quarantine', 'scanning', 'validating', 'syncing', 'queued' => 'processing',
            'failed', 'error', 'rejected' => 'failed',
            default => 'received',
        };
    }

    private function connectedWithoutDataCategory(string $status): string
    {
        if (in_array($status, ['failed', 'error', 'rejected'], true)) {
            return 'failed';
        }

        return 'processing';
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function hasFinancialData(array $sources): bool
    {
        foreach ($sources as $source) {
            if (($source['statusCategory'] ?? null) !== 'received') {
                continue;
            }

            if (in_array($source['sourceType'] ?? null, ['file_upload', 'quickbooks', 'gnucash'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function hasDocumentEvidence(array $sources): bool
    {
        foreach ($sources as $source) {
            if (($source['sourceType'] ?? null) === 'document_upload' && ($source['statusCategory'] ?? null) === 'received') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function latestReceivedAt(array $sources): ?string
    {
        $timestamps = array_filter(array_map(
            fn (array $source): ?string => ($source['statusCategory'] ?? null) === 'received'
                ? ($source['receivedAt'] ?? null)
                : null,
            $sources,
        ));

        if ($timestamps === []) {
            return null;
        }

        rsort($timestamps);

        return $timestamps[0];
    }
}
