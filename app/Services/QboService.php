<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Exception;

class QboService
{
    private const OAUTH_STATE_PREFIX = 'oauth_state:';
    private const OAUTH_STATE_TTL_SECONDS = 600;
    private const QBO_PAGE_SIZE = 1000;
    private const STALE_SYNC_MINUTES = 30;
    private const QBO_ENTITIES = [
        'Purchase',
        'Bill',
        'BillPayment',
        'VendorCredit',
        'Invoice',
        'SalesReceipt',
        'Payment',
        'Deposit',
        'RefundReceipt',
        'CreditMemo',
        'JournalEntry',
    ];

    public function createOAuthStateNonce(string $companyId): string
    {
        $nonce = bin2hex(random_bytes(32));
        Cache::put(self::OAUTH_STATE_PREFIX . $nonce, $companyId, self::OAUTH_STATE_TTL_SECONDS);
        return $nonce;
    }

    public function consumeOAuthStateNonce(string $nonce): ?string
    {
        $key = self::OAUTH_STATE_PREFIX . $nonce;
        $companyId = Cache::get($key);
        if ($companyId) {
            Cache::forget($key);
        }
        return $companyId;
    }

    public function getCredentials(string $companyId): array
    {
        $row = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNull('realm_id')
            ->first();

        $clientId = env('QB_CLIENT_ID');
        $clientSecret = env('QB_CLIENT_SECRET');
        $env = env('QB_ENVIRONMENT', 'sandbox');

        if ($row && $row->client_id_enc) {
            try {
                $clientId = decrypt($row->client_id_enc);
                $clientSecret = decrypt($row->client_secret_enc);
                $env = $row->environment ?? $env;
            } catch (\Throwable $e) {
                // fallback to env
            }
        }

        if (!$clientId) {
            throw new Exception("QuickBooks credentials not configured for this company.");
        }

        return [$clientId, $clientSecret, $env];
    }

    public function generateAuthUri(string $companyId): string
    {
        [$clientId, $clientSecret, $env] = $this->getCredentials($companyId);
        $stateNonce = $this->createOAuthStateNonce($companyId);

        $baseUrl = $env === 'sandbox' 
            ? 'https://appcenter.intuit.com/connect/oauth2' 
            : 'https://appcenter.intuit.com/connect/oauth2';

        $query = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => 'com.intuit.quickbooks.accounting openid profile email',
            'redirect_uri' => $this->redirectUri(),
            'state' => $stateNonce,
        ]);

        return "{$baseUrl}?{$query}";
    }

    public function redirectUri(): string
    {
        return (string) config('services.quickbooks.redirect_uri');
    }

    public function exchangeTokens(string $companyId, string $realmId, string $code, string $redirectUri): void
    {
        [$clientId, $clientSecret, $env] = $this->getCredentials($companyId);

        $tokenEndpoint = $env === 'sandbox'
            ? 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'
            : 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

        $authHeader = base64_encode("{$clientId}:{$clientSecret}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$authHeader}",
            'Accept' => 'application/json',
        ])->asForm()->post($tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            throw new Exception("Failed to exchange QBO authorization code: " . $response->body());
        }

        $tokens = $response->json();

        $accessEnc = encrypt($tokens['access_token']);
        $refreshEnc = encrypt($tokens['refresh_token']);
        $expiresAt = now()->addSeconds($tokens['expires_in'])->subMinutes(5);

        DB::table('integrations')->updateOrInsert(
            ['company_id' => $companyId, 'provider' => 'quickbooks', 'realm_id' => $realmId],
            [
                'access_token_enc' => $accessEnc,
                'refresh_token_enc' => $refreshEnc,
                'token_expires_at' => $expiresAt,
                'environment' => $env,
                'sync_status' => 'idle',
                'sync_progress' => 0,
                'sync_error' => null,
                'updated_at' => now(),
            ]
        );
    }

    public function getStatus(string $companyId): array
    {
        $this->markStaleSyncsFailed($companyId);

        $rows = DB::select("
            SELECT i.provider, i.realm_id, i.updated_at, 
                   (i.access_token_enc IS NOT NULL) as is_connected,
                   (m.client_id_enc IS NOT NULL) as has_master_credentials,
                   COALESCE(i.environment, m.environment) as environment,
                   COALESCE(i.client_id_enc, m.client_id_enc) as client_id_enc,
                   i.sync_status, i.sync_progress, i.sync_error
            FROM integrations i
            LEFT JOIN integrations m ON m.company_id = i.company_id 
               AND m.provider = 'quickbooks' AND m.realm_id IS NULL
            WHERE i.company_id = ? AND i.provider = 'quickbooks' AND i.realm_id IS NOT NULL
        ", [$companyId]);

        $orphanedRows = DB::select("
            SELECT DISTINCT realm_id 
            FROM qbo_transactions 
            WHERE company_id = ? 
            AND (
               realm_id IS NULL OR 
               realm_id NOT IN (
                   SELECT realm_id FROM integrations 
                   WHERE company_id = ? AND provider = 'quickbooks' AND realm_id IS NOT NULL
               )
            )
        ", [$companyId, $companyId]);

        $integrations = [];
        foreach ($rows as $row) {
            $hasCredentials = $row->has_master_credentials || (bool)$row->client_id_enc;
            
            $integrations[] = [
                'provider' => $row->provider,
                'realm_id' => $row->realm_id,
                'updated_at' => $row->updated_at,
                'is_connected' => (bool)$row->is_connected,
                'has_credentials' => $hasCredentials,
                'environment' => $row->environment,
                'sync_status' => $row->sync_status ?: 'idle',
                'sync_progress' => $row->sync_progress ?: 0,
                'sync_error' => $row->sync_error,
                'is_orphaned' => false,
                'is_legacy' => false
            ];
        }

        foreach ($orphanedRows as $row) {
            $isLegacy = is_null($row->realm_id);
            $integrations[] = [
                'provider' => 'quickbooks',
                'realm_id' => $isLegacy ? 'legacy' : $row->realm_id,
                'updated_at' => null,
                'is_connected' => false,
                'has_credentials' => false,
                'environment' => null,
                'sync_status' => 'idle',
                'sync_progress' => 0,
                'sync_error' => null,
                'is_orphaned' => true,
                'is_legacy' => $isLegacy
            ];
        }

        return $integrations;
    }

    public function sync(string $companyId, string $realmId): array
    {
        $integration = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId)
            ->first();

        if (!$integration || !$integration->access_token_enc) {
            throw new Exception('Cannot sync a disconnected company.', 400);
        }

        if ($integration->sync_status === 'syncing' && $integration->updated_at && Carbon::parse($integration->updated_at)->greaterThan(now()->subMinutes(2))) {
            throw new Exception('A synchronization is already in progress.', 409);
        }

        DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId)
            ->update([
                'sync_status' => 'syncing',
                'sync_progress' => 50,
                'sync_error' => null,
                'updated_at' => now(),
            ]);

        try {
            $integration = $this->refreshAccessTokenIfNeeded($companyId, $realmId, $integration);
            $accessToken = decrypt($integration->access_token_enc);
            $environment = $integration->environment ?: env('QB_ENVIRONMENT', 'sandbox');
            $importedCount = 0;

            foreach (self::QBO_ENTITIES as $index => $entity) {
                $records = $this->fetchAllEntityRecords($realmId, $entity, $accessToken, $environment);

                foreach ($records as $record) {
                    $mapped = $this->mapQboRecord($companyId, $realmId, $entity, $record);
                    if ($mapped) {
                        $this->upsertQboTransaction($mapped);
                        if ($entity === 'Invoice') {
                            $this->upsertQboInvoice($companyId, $realmId, $record);
                        }
                        $importedCount++;
                    }
                }

                $this->updateSyncState(
                    $companyId,
                    $realmId,
                    'syncing',
                    (int) floor((($index + 1) / count(self::QBO_ENTITIES)) * 95)
                );
            }

            $this->updateSyncState($companyId, $realmId, 'idle', 100);

            return [
                'message' => 'QuickBooks sync completed',
                'sync_status' => 'idle',
                'sync_progress' => 100,
                'imported_count' => $importedCount,
            ];
        } catch (\Throwable $e) {
            $this->updateSyncState($companyId, $realmId, 'failed', 0, $e->getMessage());
            throw new Exception('QuickBooks sync failed: ' . $e->getMessage(), 502);
        }
    }

    private function refreshAccessTokenIfNeeded(string $companyId, string $realmId, object $integration): object
    {
        if (!$integration->token_expires_at || Carbon::parse($integration->token_expires_at)->greaterThan(now()->addMinute())) {
            return $integration;
        }

        if (!$integration->refresh_token_enc) {
            throw new Exception('QuickBooks refresh token is missing.');
        }

        [$clientId, $clientSecret] = $this->getCredentials($companyId);
        $authHeader = base64_encode("{$clientId}:{$clientSecret}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$authHeader}",
            'Accept' => 'application/json',
        ])->asForm()->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
            'grant_type' => 'refresh_token',
            'refresh_token' => decrypt($integration->refresh_token_enc),
        ]);

        if (!$response->successful()) {
            throw new Exception('Failed to refresh QuickBooks token: ' . $response->body());
        }

        $tokens = $response->json();

        DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId)
            ->update([
                'access_token_enc' => encrypt($tokens['access_token']),
                'refresh_token_enc' => encrypt($tokens['refresh_token'] ?? decrypt($integration->refresh_token_enc)),
                'token_expires_at' => now()->addSeconds($tokens['expires_in'])->subMinutes(5),
                'updated_at' => now(),
            ]);

        return DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId)
            ->first();
    }

    private function fetchAllEntityRecords(string $realmId, string $entity, string $accessToken, string $environment): array
    {
        $records = [];
        $startPosition = 1;

        do {
            $page = $this->queryQbo($realmId, $entity, $startPosition, $accessToken, $environment);
            $records = array_merge($records, $page);
            $startPosition += self::QBO_PAGE_SIZE;
        } while (count($page) === self::QBO_PAGE_SIZE);

        return $records;
    }

    private function queryQbo(string $realmId, string $entity, int $startPosition, string $accessToken, string $environment): array
    {
        $baseUrl = rtrim($this->qboBaseUrl($environment), '/');
        $query = sprintf(
            'SELECT * FROM %s STARTPOSITION %d MAXRESULTS %d',
            $entity,
            $startPosition,
            self::QBO_PAGE_SIZE
        );

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get("{$baseUrl}/v3/company/{$realmId}/query", [
                'query' => $query,
                'minorversion' => config('services.quickbooks.minor_version'),
            ]);

        if (!$response->successful()) {
            throw new Exception("Failed to fetch {$entity} records from QuickBooks: " . $response->body());
        }

        $data = $response->json();

        return $data['QueryResponse'][$entity] ?? [];
    }

    private function qboBaseUrl(string $environment): string
    {
        return $environment === 'production'
            ? (string) config('services.quickbooks.production_base_url')
            : (string) config('services.quickbooks.sandbox_base_url');
    }

    private function mapQboRecord(string $companyId, string $realmId, string $entity, array $record): ?array
    {
        $qboId = $record['Id'] ?? null;
        if (!$qboId) {
            return null;
        }

        $transactionDate = $record['TxnDate'] ?? $record['MetaData']['CreateTime'] ?? null;
        $amount = $this->extractAmount($entity, $record);

        return [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'qbo_id' => "{$entity}:{$qboId}",
            'realm_id' => $realmId,
            'transaction_date' => $transactionDate ? Carbon::parse($transactionDate)->toDateString() : null,
            'vendor_name' => $this->extractCounterparty($record),
            'amount' => $amount,
            'type' => $this->mapTransactionType($entity),
            'status' => $record['status'] ?? 'active',
            'raw_payload' => json_encode(['entity' => $entity, 'record' => $record]),
            'synced_at' => now(),
        ];
    }

    private function extractAmount(string $entity, array $record): float
    {
        if (isset($record['TotalAmt'])) {
            return (float) $record['TotalAmt'];
        }

        if ($entity === 'JournalEntry' && isset($record['Line']) && is_array($record['Line'])) {
            return array_reduce(
                $record['Line'],
                fn(float $total, array $line): float => $total + (float) ($line['Amount'] ?? 0),
                0.0
            );
        }

        if (isset($record['Amount'])) {
            return (float) $record['Amount'];
        }

        return 0.0;
    }

    private function extractCounterparty(array $record): ?string
    {
        foreach (['VendorRef', 'CustomerRef', 'EntityRef', 'PayeeRef', 'DepartmentRef'] as $key) {
            if (!empty($record[$key]['name'])) {
                return $record[$key]['name'];
            }
        }

        return null;
    }

    private function mapTransactionType(string $entity): string
    {
        return match ($entity) {
            'Invoice', 'SalesReceipt', 'Payment', 'Deposit', 'CreditMemo' => 'revenue',
            'JournalEntry' => 'journal',
            default => 'expense',
        };
    }

    private function upsertQboTransaction(array $transaction): void
    {
        DB::table('qbo_transactions')->updateOrInsert(
            [
                'company_id' => $transaction['company_id'],
                'realm_id' => $transaction['realm_id'],
                'qbo_id' => $transaction['qbo_id'],
            ],
            $transaction
        );
    }

    public function backfillInvoicesFromStoredTransactions(string $companyId, ?string $realmId = null): int
    {
        $query = DB::table('qbo_transactions')
            ->where('company_id', $companyId)
            ->where('qbo_id', 'like', 'Invoice:%');

        if ($realmId) {
            $query->where('realm_id', $realmId);
        }

        $count = 0;
        foreach ($query->orderBy('transaction_date')->cursor() as $row) {
            $payload = is_string($row->raw_payload) ? json_decode($row->raw_payload, true) : (array) $row->raw_payload;
            $record = $payload['record'] ?? null;
            if (!is_array($record)) {
                continue;
            }

            $this->upsertQboInvoice($companyId, (string) $row->realm_id, $record);
            $count++;
        }

        return $count;
    }

    private function upsertQboInvoice(string $companyId, string $realmId, array $record): void
    {
        $qboId = $record['Id'] ?? null;
        if (!$qboId) {
            return;
        }

        $amount = (float)($record['TotalAmt'] ?? 0);
        $balance = array_key_exists('Balance', $record) ? (float)$record['Balance'] : $amount;
        $paidAmount = max(0.0, $amount - $balance);
        $invoiceDate = Carbon::parse($record['TxnDate'] ?? $record['MetaData']['CreateTime'] ?? now())->toDateString();
        $dueDate = Carbon::parse($record['DueDate'] ?? $invoiceDate)->toDateString();

        DB::table('invoices')->updateOrInsert(
            [
                'company_id' => $companyId,
                'row_content_hash' => "qbo:{$realmId}:invoice:{$qboId}",
            ],
            [
                'upload_id' => null,
                'customer_name' => $record['CustomerRef']['name'] ?? 'Unknown Customer',
                'invoice_number' => $record['DocNumber'] ?? $qboId,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'amount' => $amount,
                'paid_amount' => min($amount, $paidAmount),
                'status' => $this->mapInvoiceStatus($amount, $balance),
                'source' => 'qbo',
                'raw_row' => json_encode($record),
                'source_sheet_name' => 'QuickBooks Online',
                'source_row_number' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function mapInvoiceStatus(float $amount, float $balance): string
    {
        if ($balance <= 0.0) {
            return 'paid';
        }

        if ($balance < $amount) {
            return 'partial';
        }

        return 'open';
    }

    private function updateSyncState(string $companyId, string $realmId, string $status, int $progress, ?string $error = null): void
    {
        DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId)
            ->update([
                'sync_status' => $status,
                'sync_progress' => $progress,
                'sync_error' => $error,
                'updated_at' => now(),
            ]);
    }

    private function markStaleSyncsFailed(string $companyId): void
    {
        DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('sync_status', 'syncing')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_SYNC_MINUTES))
            ->update([
                'sync_status' => 'failed',
                'sync_progress' => 0,
                'sync_error' => 'Sync did not complete within 30 minutes. Please try again.',
                'updated_at' => now(),
            ]);
    }

    public function disconnect(string $companyId, string $realmId): void
    {
        DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId)
            ->update([
                'access_token_enc' => null,
                'refresh_token_enc' => null,
                'token_expires_at' => null,
                'updated_at' => now()
            ]);
    }

    public function purge(string $companyId, string $realmId): void
    {
        if ($realmId === 'legacy') {
            DB::table('qbo_transactions')->where('company_id', $companyId)->whereNull('realm_id')->delete();
            DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('source', 'qbo')
                ->where('row_content_hash', 'like', 'qbo:%:invoice:%')
                ->delete();
        } else {
            $integration = DB::table('integrations')->where('company_id', $companyId)->where('realm_id', $realmId)->first();
            if ($integration && $integration->sync_status === 'syncing') {
                throw new Exception("Cannot purge data while a sync is in progress.", 400);
            }
            DB::table('qbo_transactions')->where('company_id', $companyId)->where('realm_id', $realmId)->delete();
            DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('source', 'qbo')
                ->where('row_content_hash', 'like', "qbo:{$realmId}:invoice:%")
                ->delete();
        }

        // Only clear alerts when no transaction data remains for the company.
        // If other QB realms or file uploads still have data, the scoring engine
        // will rebuild alerts on its next run without losing coverage from surviving sources.
        $hasRemainingData = DB::table('qbo_transactions')->where('company_id', $companyId)->exists()
            || DB::table('transactions')->where('company_id', $companyId)->exists()
            || DB::table('gnucash_transactions')->where('company_id', $companyId)->exists();

        if (! $hasRemainingData) {
            DB::table('alerts')->where('company_id', $companyId)->delete();
            DB::table('alert_groups')->where('company_id', $companyId)->delete();
            DB::table('alert_recommendations')->where('company_id', $companyId)->delete();
        }

        // We would dispatch a RulesEngine job here to rebuild alerts on remaining data
    }

    public function saveCredentials(string $companyId, array $data): void
    {
        $clientIdEnc = encrypt($data['clientId']);
        $clientSecretEnc = encrypt($data['clientSecret']);

        DB::table('integrations')->updateOrInsert(
            ['company_id' => $companyId, 'provider' => 'quickbooks', 'realm_id' => null],
            [
                'client_id_enc' => $clientIdEnc,
                'client_secret_enc' => $clientSecretEnc,
                'environment' => $data['environment'],
                'updated_at' => now(),
            ]
        );
    }

    public function removeCredentials(string $companyId): void
    {
        $connected = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNotNull('access_token_enc')
            ->exists();

        if ($connected) {
            throw new Exception("Cannot remove credentials while QuickBooks is connected. Please disconnect first.", 400);
        }

        DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNull('realm_id')
            ->delete();
    }
}
