<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->string('scope_key', 80)->default('company');
            $table->string('entity_type', 40);
            $table->string('canonical_key', 512);
            $table->string('display_name', 512)->nullable();
            $table->string('legacy_identity_hash', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'scope_key', 'entity_type', 'canonical_key'], 'entity_identities_scope_type_key_unique');
            $table->index(['company_id', 'entity_type'], 'entity_identities_company_type_idx');
            $table->index(['company_id', 'legacy_identity_hash'], 'entity_identities_company_legacy_hash_idx');
        });

        Schema::create('entity_identity_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_identity_id')->constrained('entity_identities')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->string('scope_key', 80)->default('company');
            $table->string('entity_type', 40);
            $table->string('alias_type', 40);
            $table->string('alias_value', 512);
            $table->string('normalized_alias', 512);
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'scope_key', 'entity_type', 'alias_type', 'normalized_alias'], 'entity_aliases_lookup_unique');
            $table->index('entity_identity_id', 'entity_aliases_identity_idx');
            $table->index(['company_id', 'entity_type'], 'entity_aliases_company_type_idx');
        });

        $this->backfillFromTransactions();
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_identity_aliases');
        Schema::dropIfExists('entity_identities');
    }

    private function backfillFromTransactions(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $columns = ['id', 'company_id', 'vendor_customer'];
        if (Schema::hasColumn('transactions', 'business_profile_id')) {
            $columns[] = 'business_profile_id';
        }

        DB::table('transactions')
            ->select($columns)
            ->orderBy('id')
            ->chunk(500, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $businessProfileId = property_exists($transaction, 'business_profile_id')
                        ? $transaction->business_profile_id
                        : null;

                    $vendorName = trim((string) ($transaction->vendor_customer ?? ''));
                    if ($vendorName !== '') {
                        $this->upsertIdentity(
                            companyId: (string) $transaction->company_id,
                            businessProfileId: $businessProfileId,
                            entityType: 'vendor',
                            sourceKey: $vendorName,
                            displayName: $vendorName,
                            legacyType: 'vendor',
                            legacyKey: strtolower($vendorName),
                            aliases: ['name' => $vendorName],
                        );
                    }

                    $transactionId = (string) $transaction->id;
                    $this->upsertIdentity((string) $transaction->company_id, $businessProfileId, 'approver', $transactionId, 'Transaction approver', 'approver', $transactionId);
                    $this->upsertIdentity((string) $transaction->company_id, $businessProfileId, 'document', $transactionId, 'Transaction source document', 'document', $transactionId);
                    $this->upsertIdentity((string) $transaction->company_id, $businessProfileId, 'bank_account', 'default', 'Default bank account', 'bank_account', 'default');
                }
            });
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private function upsertIdentity(
        string $companyId,
        ?string $businessProfileId,
        string $entityType,
        string $sourceKey,
        string $displayName,
        string $legacyType,
        string $legacyKey,
        array $aliases = [],
    ): string {
        $scopeKey = $businessProfileId ?: 'company';
        $canonicalKey = $entityType.':'.$this->normalizeAlias($sourceKey);
        $legacyHash = md5($companyId.'|'.$legacyType.'|'.$legacyKey);
        $now = now();

        $candidateId = (string) Str::uuid();
        DB::table('entity_identities')->insertOrIgnore([
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

        $entityId = (string) DB::table('entity_identities')
            ->where('company_id', $companyId)
            ->where('scope_key', $scopeKey)
            ->where('entity_type', $entityType)
            ->where('canonical_key', $canonicalKey)
            ->value('id');

        $this->insertAlias($entityId, $companyId, $businessProfileId, $scopeKey, $entityType, 'legacy_hash', $legacyHash, $legacyHash);
        $this->insertAlias($entityId, $companyId, $businessProfileId, $scopeKey, $entityType, 'source_key', $sourceKey, $canonicalKey);
        foreach ($aliases as $aliasType => $aliasValue) {
            $this->insertAlias($entityId, $companyId, $businessProfileId, $scopeKey, $entityType, $aliasType, $aliasValue, $this->normalizeAlias($aliasValue));
        }

        return $entityId;
    }

    private function insertAlias(
        string $entityId,
        string $companyId,
        ?string $businessProfileId,
        string $scopeKey,
        string $entityType,
        string $aliasType,
        string $aliasValue,
        string $normalizedAlias,
    ): void {
        if ($aliasValue === '' || $normalizedAlias === '') {
            return;
        }

        $now = now();
        DB::table('entity_identity_aliases')->insertOrIgnore([
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
    }

    private function normalizeAlias(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?: '';
    }
};
