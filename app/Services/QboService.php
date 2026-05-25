<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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

    public function createOAuthStateNonce(string $companyId, ?string $businessProfileId = null): string
    {
        $nonce = bin2hex(random_bytes(32));
        Cache::put(self::OAUTH_STATE_PREFIX . $nonce, [
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
        ], self::OAUTH_STATE_TTL_SECONDS);
        return $nonce;
    }

    public function consumeOAuthStateNonce(string $nonce): ?string
    {
        return $this->consumeOAuthStateNoncePayload($nonce)['company_id'] ?? null;
    }

    /** @return array{company_id: string|null, business_profile_id: string|null} */
    public function consumeOAuthStateNoncePayload(string $nonce): array
    {
        $key = self::OAUTH_STATE_PREFIX . $nonce;
        $payload = Cache::get($key);
        if ($payload) {
            Cache::forget($key);
        }

        if (is_string($payload)) {
            return ['company_id' => $payload, 'business_profile_id' => null];
        }

        if (is_array($payload)) {
            return [
                'company_id' => is_string($payload['company_id'] ?? null) ? $payload['company_id'] : null,
                'business_profile_id' => is_string($payload['business_profile_id'] ?? null) ? $payload['business_profile_id'] : null,
            ];
        }

        return ['company_id' => null, 'business_profile_id' => null];
    }

    public function getCredentials(string $companyId, ?string $businessProfileId = null): array
    {
        $row = null;
        if ($businessProfileId && $this->hasBusinessProfileColumn('integrations')) {
            $row = DB::table('integrations')
                ->where('company_id', $companyId)
                ->where('business_profile_id', $businessProfileId)
                ->where('provider', 'quickbooks')
                ->whereNull('realm_id')
                ->first();
        }

        $row ??= DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNull('realm_id')
            ->when($this->hasBusinessProfileColumn('integrations'), fn ($query) => $query->whereNull('business_profile_id'))
            ->first();

        if (! $row || ! $row->client_id_enc || ! $row->client_secret_enc) {
            throw new Exception('QuickBooks credentials not configured for this company.');
        }

        try {
            $clientId = decrypt($row->client_id_enc);
            $clientSecret = decrypt($row->client_secret_enc);
            $env = $row->environment ?: 'sandbox';
        } catch (\Throwable $e) {
            throw new Exception('QuickBooks credentials are invalid for this company.');
        }

        if (! $clientId || ! $clientSecret) {
            throw new Exception('QuickBooks credentials not configured for this company.');
        }

        return [$clientId, $clientSecret, $env];
    }

    public function generateAuthUri(string $companyId, ?string $businessProfileId = null): string
    {
        [$clientId, $clientSecret, $env] = $this->getCredentials($companyId, $businessProfileId);
        $stateNonce = $this->createOAuthStateNonce($companyId, $businessProfileId);

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

    public function exchangeTokens(string $companyId, string $realmId, string $code, string $redirectUri, ?string $businessProfileId = null): void
    {
        [$clientId, $clientSecret, $env] = $this->getCredentials($companyId, $businessProfileId);

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

        $keys = ['company_id' => $companyId, 'provider' => 'quickbooks', 'realm_id' => $realmId];
        $values = [
            'access_token_enc' => $accessEnc,
            'refresh_token_enc' => $refreshEnc,
            'token_expires_at' => $expiresAt,
            'environment' => $env,
            'sync_status' => 'idle',
            'sync_progress' => 0,
            'sync_error' => null,
            'updated_at' => now(),
        ];

        if ($this->hasBusinessProfileColumn('integrations')) {
            $keys['business_profile_id'] = $businessProfileId;
            $values['business_profile_id'] = $businessProfileId;
        }

        DB::table('integrations')->updateOrInsert($keys, $values);
    }

    public function getStatus(string $companyId, ?string $businessProfileId = null): array
    {
        $this->markStaleSyncsFailed($companyId, $businessProfileId);

        $master = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNull('realm_id')
            ->when($businessProfileId && $this->hasBusinessProfileColumn('integrations'), fn ($query) => $query->where('business_profile_id', $businessProfileId))
            ->first();
        if (! $master && $businessProfileId && $this->hasBusinessProfileColumn('integrations')) {
            $master = DB::table('integrations')
                ->where('company_id', $companyId)
                ->where('provider', 'quickbooks')
                ->whereNull('realm_id')
                ->whereNull('business_profile_id')
                ->first();
        }

        $rowsQuery = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNotNull('realm_id');
        $this->scopeBusinessProfile($rowsQuery, 'integrations', $businessProfileId);
        $rows = $rowsQuery->get();

        $connectedRealmIds = $rows->pluck('realm_id')->filter()->values()->all();
        $orphanQuery = DB::table('qbo_transactions')
            ->select('realm_id')
            ->distinct()
            ->where('company_id', $companyId);
        $this->scopeBusinessProfile($orphanQuery, 'qbo_transactions', $businessProfileId);
        $orphanedRows = $orphanQuery
            ->where(function ($query) use ($connectedRealmIds): void {
                $query->whereNull('realm_id');
                if (count($connectedRealmIds) > 0) {
                    $query->orWhereNotIn('realm_id', $connectedRealmIds);
                } else {
                    $query->orWhereNotNull('realm_id');
                }
            })
            ->get();

        $integrations = [];
        foreach ($rows as $row) {
            $hasCredentials = (bool) ($master?->client_id_enc) || (bool) ($row->client_id_enc ?? null);
            
            $integrations[] = [
                'provider' => $row->provider,
                'realm_id' => $row->realm_id,
                'updated_at' => $row->updated_at,
                'is_connected' => (bool) ($row->access_token_enc ?? null),
                'has_credentials' => $hasCredentials,
                'environment' => $row->environment ?: $master?->environment,
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

    public function sync(string $companyId, string $realmId, ?string $businessProfileId = null): array
    {
        $integrationQuery = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId);
        $this->scopeBusinessProfile($integrationQuery, 'integrations', $businessProfileId);
        $integration = $integrationQuery->first();

        if (!$integration || !$integration->access_token_enc) {
            throw new Exception('Cannot sync a disconnected company.', 400);
        }

        if ($integration->sync_status === 'syncing' && $integration->updated_at && Carbon::parse($integration->updated_at)->greaterThan(now()->subMinutes(2))) {
            throw new Exception('A synchronization is already in progress.', 409);
        }

        $syncQuery = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId);
        $this->scopeBusinessProfile($syncQuery, 'integrations', $businessProfileId);
        $syncQuery->update([
                'sync_status' => 'syncing',
                'sync_progress' => 50,
                'sync_error' => null,
                'updated_at' => now(),
            ]);

        try {
            $integration = $this->refreshAccessTokenIfNeeded($companyId, $realmId, $integration, $businessProfileId);
            $accessToken = decrypt($integration->access_token_enc);
            $environment = $integration->environment ?: env('QB_ENVIRONMENT', 'sandbox');
            $importedCount = 0;

            foreach (self::QBO_ENTITIES as $index => $entity) {
                $records = $this->fetchAllEntityRecords($realmId, $entity, $accessToken, $environment);

                foreach ($records as $record) {
                    $mapped = $this->mapQboRecord($companyId, $realmId, $entity, $record, $businessProfileId);
                    if ($mapped) {
                        $this->upsertQboTransaction($mapped);
                        if ($entity === 'Invoice') {
                            $this->upsertQboInvoice($companyId, $realmId, $record, $businessProfileId);
                        }
                        $importedCount++;
                    }
                }

                $this->updateSyncState(
                    $companyId,
                    $realmId,
                    'syncing',
                    (int) floor((($index + 1) / count(self::QBO_ENTITIES)) * 95),
                    null,
                    $businessProfileId
                );
            }

            $this->updateSyncState($companyId, $realmId, 'idle', 100, null, $businessProfileId);
            $this->flushRiskScoreCache($companyId);

            return [
                'message' => 'QuickBooks sync completed',
                'sync_status' => 'idle',
                'sync_progress' => 100,
                'imported_count' => $importedCount,
            ];
        } catch (\Throwable $e) {
            $this->updateSyncState($companyId, $realmId, 'failed', 0, $e->getMessage(), $businessProfileId);
            throw new Exception('QuickBooks sync failed: ' . $e->getMessage(), 502);
        }
    }

    private function refreshAccessTokenIfNeeded(string $companyId, string $realmId, object $integration, ?string $businessProfileId = null): object
    {
        if (!$integration->token_expires_at || Carbon::parse($integration->token_expires_at)->greaterThan(now()->addMinute())) {
            return $integration;
        }

        if (!$integration->refresh_token_enc) {
            throw new Exception('QuickBooks refresh token is missing.');
        }

        [$clientId, $clientSecret] = $this->getCredentials($companyId, $businessProfileId);
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

        $updateQuery = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId);
        $this->scopeBusinessProfile($updateQuery, 'integrations', $businessProfileId);
        $updateQuery->update([
                'access_token_enc' => encrypt($tokens['access_token']),
                'refresh_token_enc' => encrypt($tokens['refresh_token'] ?? decrypt($integration->refresh_token_enc)),
                'token_expires_at' => now()->addSeconds($tokens['expires_in'])->subMinutes(5),
                'updated_at' => now(),
            ]);

        $query = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId);
        $this->scopeBusinessProfile($query, 'integrations', $businessProfileId);

        return $query->first();
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

    private function mapQboRecord(string $companyId, string $realmId, string $entity, array $record, ?string $businessProfileId = null): ?array
    {
        $qboId = $record['Id'] ?? null;
        if (!$qboId) {
            return null;
        }

        $transactionDate = $record['TxnDate'] ?? $record['MetaData']['CreateTime'] ?? null;
        $amount = $this->extractAmount($entity, $record);

        $mapped = [
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

        if ($businessProfileId && $this->hasBusinessProfileColumn('qbo_transactions')) {
            $mapped['business_profile_id'] = $businessProfileId;
        }

        return $mapped;
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
        if (! $this->hasBusinessProfileColumn('qbo_transactions')) {
            unset($transaction['business_profile_id']);
        }

        $keys = [
            'company_id' => $transaction['company_id'],
            'realm_id' => $transaction['realm_id'],
            'qbo_id' => $transaction['qbo_id'],
        ];

        if ($this->hasBusinessProfileColumn('qbo_transactions') && array_key_exists('business_profile_id', $transaction)) {
            $keys['business_profile_id'] = $transaction['business_profile_id'];
        }

        DB::table('qbo_transactions')->updateOrInsert($keys, $transaction);
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

            $this->upsertQboInvoice(
                $companyId,
                (string) $row->realm_id,
                $record,
                property_exists($row, 'business_profile_id') ? $row->business_profile_id : null
            );
            $count++;
        }

        return $count;
    }

    private function upsertQboInvoice(string $companyId, string $realmId, array $record, ?string $businessProfileId = null): void
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

        $keys = [
                'company_id' => $companyId,
                'row_content_hash' => "qbo:{$realmId}:invoice:{$qboId}",
        ];
        $values = [
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
        ];

        if ($businessProfileId && $this->hasBusinessProfileColumn('invoices')) {
            $keys['business_profile_id'] = $businessProfileId;
            $values['business_profile_id'] = $businessProfileId;
        }

        DB::table('invoices')->updateOrInsert($keys, $values);
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

    private function flushRiskScoreCache(string $companyId): void
    {
        Cache::forget("risk_score:vendor:{$companyId}");
        Cache::forget("risk_score:reconciliation:{$companyId}");
        Cache::forget("risk_score:entity_relationship:{$companyId}");
    }

    private function updateSyncState(string $companyId, string $realmId, string $status, int $progress, ?string $error = null, ?string $businessProfileId = null): void
    {
        $query = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId);
        $this->scopeBusinessProfile($query, 'integrations', $businessProfileId);

        $query->update([
                'sync_status' => $status,
                'sync_progress' => $progress,
                'sync_error' => $error,
                'updated_at' => now(),
            ]);
    }

    private function markStaleSyncsFailed(string $companyId, ?string $businessProfileId = null): void
    {
        $query = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('sync_status', 'syncing')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_SYNC_MINUTES));
        $this->scopeBusinessProfile($query, 'integrations', $businessProfileId);

        $query->update([
                'sync_status' => 'failed',
                'sync_progress' => 0,
                'sync_error' => 'Sync did not complete within 30 minutes. Please try again.',
                'updated_at' => now(),
            ]);
    }

    public function disconnect(string $companyId, string $realmId, ?string $businessProfileId = null): void
    {
        $query = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->where('realm_id', $realmId);
        $this->scopeBusinessProfile($query, 'integrations', $businessProfileId);

        $query->update([
                'access_token_enc' => null,
                'refresh_token_enc' => null,
                'token_expires_at' => null,
                'updated_at' => now()
            ]);
    }

    public function purge(string $companyId, string $realmId, ?string $businessProfileId = null): void
    {
        if ($realmId === 'legacy') {
            $query = DB::table('qbo_transactions')->where('company_id', $companyId)->whereNull('realm_id');
            $this->scopeBusinessProfile($query, 'qbo_transactions', $businessProfileId);
            $query->delete();

            $invoiceQuery = DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('source', 'qbo')
                ->where('row_content_hash', 'like', 'qbo:%:invoice:%');
            $this->scopeBusinessProfile($invoiceQuery, 'invoices', $businessProfileId);
            $invoiceQuery->delete();
        } else {
            $integrationQuery = DB::table('integrations')->where('company_id', $companyId)->where('realm_id', $realmId);
            $this->scopeBusinessProfile($integrationQuery, 'integrations', $businessProfileId);
            $integration = $integrationQuery->first();
            if ($integration && $integration->sync_status === 'syncing') {
                throw new Exception("Cannot purge data while a sync is in progress.", 400);
            }
            $transactionQuery = DB::table('qbo_transactions')->where('company_id', $companyId)->where('realm_id', $realmId);
            $this->scopeBusinessProfile($transactionQuery, 'qbo_transactions', $businessProfileId);
            $transactionQuery->delete();

            $invoiceQuery = DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('source', 'qbo')
                ->where('row_content_hash', 'like', "qbo:{$realmId}:invoice:%");
            $this->scopeBusinessProfile($invoiceQuery, 'invoices', $businessProfileId);
            $invoiceQuery->delete();
        }

        // Only clear alerts when no transaction data remains for the company.
        // If other QB realms or file uploads still have data, the scoring engine
        // will rebuild alerts on its next run without losing coverage from surviving sources.
        $qboRemaining = DB::table('qbo_transactions')->where('company_id', $companyId);
        $this->scopeBusinessProfile($qboRemaining, 'qbo_transactions', $businessProfileId);
        $transactionsRemaining = DB::table('transactions')->where('company_id', $companyId);
        $this->scopeBusinessProfile($transactionsRemaining, 'transactions', $businessProfileId);
        $gnucashRemaining = DB::table('gnucash_transactions')->where('company_id', $companyId);
        $this->scopeBusinessProfile($gnucashRemaining, 'gnucash_transactions', $businessProfileId);

        $hasRemainingData = $qboRemaining->exists()
            || $transactionsRemaining->exists()
            || $gnucashRemaining->exists();

        if (! $hasRemainingData) {
            foreach (['alerts', 'alert_groups', 'alert_recommendations'] as $table) {
                $query = DB::table($table)->where('company_id', $companyId);
                $this->scopeBusinessProfile($query, $table, $businessProfileId);
                $query->delete();
            }
        }

        // We would dispatch a RulesEngine job here to rebuild alerts on remaining data
    }

    public function saveCredentials(string $companyId, array $data, ?string $businessProfileId = null): void
    {
        $clientIdEnc = encrypt($data['clientId']);
        $clientSecretEnc = encrypt($data['clientSecret']);

        $keys = ['company_id' => $companyId, 'provider' => 'quickbooks', 'realm_id' => null];
        $values = [
                'client_id_enc' => $clientIdEnc,
                'client_secret_enc' => $clientSecretEnc,
                'environment' => $data['environment'],
                'updated_at' => now(),
        ];
        if ($this->hasBusinessProfileColumn('integrations')) {
            $keys['business_profile_id'] = $businessProfileId;
            $values['business_profile_id'] = $businessProfileId;
        }

        DB::table('integrations')->updateOrInsert($keys, $values);
    }

    public function removeCredentials(string $companyId, ?string $businessProfileId = null): void
    {
        $connectedQuery = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNotNull('access_token_enc');
        $this->scopeBusinessProfile($connectedQuery, 'integrations', $businessProfileId);
        $connected = $connectedQuery->exists();

        if ($connected) {
            throw new Exception("Cannot remove credentials while QuickBooks is connected. Please disconnect first.", 400);
        }

        $query = DB::table('integrations')
            ->where('company_id', $companyId)
            ->where('provider', 'quickbooks')
            ->whereNull('realm_id');
        $this->scopeBusinessProfile($query, 'integrations', $businessProfileId);
        $query->delete();
    }

    private function hasBusinessProfileColumn(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'business_profile_id');
    }

    private function scopeBusinessProfile($query, string $table, ?string $businessProfileId): void
    {
        if ($businessProfileId && $this->hasBusinessProfileColumn($table)) {
            $query->where('business_profile_id', $businessProfileId);
        }
    }
}
