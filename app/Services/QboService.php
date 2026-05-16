<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Exception;

class QboService
{
    private const OAUTH_STATE_PREFIX = 'oauth_state:';
    private const OAUTH_STATE_TTL_SECONDS = 600;

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

        $redirectUri = config('app.url') . '/api/integrations/qbo/callback';

        $query = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => 'com.intuit.quickbooks.accounting openid profile email',
            'redirect_uri' => $redirectUri,
            'state' => $stateNonce,
        ]);

        return "{$baseUrl}?{$query}";
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
                'updated_at' => now(),
            ]
        );
    }

    public function getStatus(string $companyId): array
    {
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
        } else {
            $integration = DB::table('integrations')->where('company_id', $companyId)->where('realm_id', $realmId)->first();
            if ($integration && $integration->sync_status === 'syncing') {
                throw new Exception("Cannot purge data while a sync is in progress.", 400);
            }
            DB::table('qbo_transactions')->where('company_id', $companyId)->where('realm_id', $realmId)->delete();
        }

        DB::table('alerts')->where('company_id', $companyId)->delete();
        DB::table('alert_groups')->where('company_id', $companyId)->delete();
        
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
