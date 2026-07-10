<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StableEntityIdentityService
{
    private const TABLE = 'entity_identities';
    private const ALIAS_TABLE = 'entity_identity_aliases';

    public function vendorId(string $companyId, ?string $vendorName, ?string $businessProfileId = null): ?string
    {
        $name = trim((string) $vendorName);
        if ($name === '') {
            return null;
        }

        return $this->resolve(
            companyId: $companyId,
            businessProfileId: $businessProfileId,
            entityType: 'vendor',
            sourceKey: $name,
            displayName: $name,
            legacyType: 'vendor',
            legacyKey: strtolower($name),
            aliases: ['name' => $name],
        );
    }

    public function approverId(
        string $companyId,
        string $sourceKey,
        ?string $displayName = null,
        ?string $businessProfileId = null
    ): string {
        $key = trim($sourceKey);

        return $this->resolve(
            companyId: $companyId,
            businessProfileId: $businessProfileId,
            entityType: 'approver',
            sourceKey: $key,
            displayName: $displayName ?: 'Transaction approver',
            legacyType: 'approver',
            legacyKey: $key,
        );
    }

    public function documentId(
        string $companyId,
        string $sourceKey,
        ?string $displayName = null,
        ?string $businessProfileId = null
    ): string {
        $key = trim($sourceKey);

        return $this->resolve(
            companyId: $companyId,
            businessProfileId: $businessProfileId,
            entityType: 'document',
            sourceKey: $key,
            displayName: $displayName ?: 'Transaction source document',
            legacyType: 'document',
            legacyKey: $key,
        );
    }

    public function bankAccountId(
        string $companyId,
        string $sourceKey = 'default',
        ?string $displayName = null,
        ?string $businessProfileId = null
    ): string {
        $key = trim($sourceKey) ?: 'default';

        return $this->resolve(
            companyId: $companyId,
            businessProfileId: $businessProfileId,
            entityType: 'bank_account',
            sourceKey: $key,
            displayName: $displayName ?: 'Default bank account',
            legacyType: 'bank_account',
            legacyKey: $key,
        );
    }

    public function employeeId(
        string $companyId,
        string $sourceKey,
        ?string $displayName = null,
        ?string $businessProfileId = null
    ): string {
        $key = trim($sourceKey);

        return $this->resolve(
            companyId: $companyId,
            businessProfileId: $businessProfileId,
            entityType: 'employee',
            sourceKey: $key,
            displayName: $displayName ?: $key,
            legacyType: 'employee',
            legacyKey: strtolower($key),
        );
    }

    public function legacyHash(string $companyId, string $legacyType, string $legacyKey): string
    {
        return md5($companyId.'|'.$legacyType.'|'.$legacyKey);
    }

    /**
     * Returns a stable UUID once the identity tables exist. During rolling deploys
     * and isolated tests without the migration, falls back to the legacy hash.
     *
     * @param  array<string, string>  $aliases
     */
    private function resolve(
        string $companyId,
        ?string $businessProfileId,
        string $entityType,
        string $sourceKey,
        string $displayName,
        string $legacyType,
        string $legacyKey,
        array $aliases = [],
    ): string {
        $legacyHash = $this->legacyHash($companyId, $legacyType, $legacyKey);

        if (! $this->identityTablesExist()) {
            return $legacyHash;
        }

        $scopeKey = $this->scopeKey($businessProfileId);
        $canonicalKey = $this->canonicalKey($entityType, $sourceKey);
        $now = now();

        $entityId = DB::table(self::ALIAS_TABLE)
            ->where('company_id', $companyId)
            ->where('scope_key', $scopeKey)
            ->where('entity_type', $entityType)
            ->where('alias_type', 'legacy_hash')
            ->where('normalized_alias', $legacyHash)
            ->value('entity_identity_id');

        if (! $entityId) {
            $entityId = DB::table(self::TABLE)
                ->where('company_id', $companyId)
                ->where('scope_key', $scopeKey)
                ->where('entity_type', $entityType)
                ->where('canonical_key', $canonicalKey)
                ->value('id');
        }

        if (! $entityId) {
            $candidateId = (string) Str::uuid();
            DB::table(self::TABLE)->insertOrIgnore([
                'id' => $candidateId,
                'company_id' => $companyId,
                'business_profile_id' => $businessProfileId,
                'scope_key' => $scopeKey,
                'entity_type' => $entityType,
                'canonical_key' => $canonicalKey,
                'display_name' => $displayName,
                'legacy_identity_hash' => $legacyHash,
                'metadata' => json_encode([]),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $entityId = DB::table(self::TABLE)
                ->where('company_id', $companyId)
                ->where('scope_key', $scopeKey)
                ->where('entity_type', $entityType)
                ->where('canonical_key', $canonicalKey)
                ->value('id') ?: $candidateId;
        } else {
            DB::table(self::TABLE)
                ->where('id', $entityId)
                ->update([
                    'display_name' => $displayName,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $this->ensureAlias($entityId, $companyId, $businessProfileId, $scopeKey, $entityType, 'legacy_hash', $legacyHash, $legacyHash);
        $this->ensureAlias($entityId, $companyId, $businessProfileId, $scopeKey, $entityType, 'source_key', $sourceKey, $canonicalKey);
        foreach ($aliases as $aliasType => $aliasValue) {
            $this->ensureAlias(
                $entityId,
                $companyId,
                $businessProfileId,
                $scopeKey,
                $entityType,
                $aliasType,
                $aliasValue,
                $this->normalizeAlias($aliasValue),
            );
        }

        return (string) $entityId;
    }

    private function ensureAlias(
        string $entityId,
        string $companyId,
        ?string $businessProfileId,
        string $scopeKey,
        string $entityType,
        string $aliasType,
        string $aliasValue,
        string $normalizedAlias,
    ): void {
        $aliasValue = trim($aliasValue);
        $normalizedAlias = trim($normalizedAlias);
        if ($aliasValue === '' || $normalizedAlias === '') {
            return;
        }

        $now = now();
        DB::table(self::ALIAS_TABLE)->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_identity_id' => $entityId,
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'scope_key' => $scopeKey,
            'entity_type' => $entityType,
            'alias_type' => $aliasType,
            'alias_value' => $aliasValue,
            'normalized_alias' => $normalizedAlias,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table(self::ALIAS_TABLE)
            ->where('company_id', $companyId)
            ->where('scope_key', $scopeKey)
            ->where('entity_type', $entityType)
            ->where('alias_type', $aliasType)
            ->where('normalized_alias', $normalizedAlias)
            ->where('entity_identity_id', $entityId)
            ->update([
                'alias_value' => $aliasValue,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function identityTablesExist(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasTable(self::ALIAS_TABLE);
    }

    private function canonicalKey(string $entityType, string $sourceKey): string
    {
        return $entityType.':'.$this->normalizeAlias($sourceKey);
    }

    private function normalizeAlias(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?: '';
    }

    private function scopeKey(?string $businessProfileId): string
    {
        return $businessProfileId ?: 'company';
    }
}
