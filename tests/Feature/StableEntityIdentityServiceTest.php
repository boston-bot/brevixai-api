<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Agents\StableEntityIdentityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StableEntityIdentityServiceTest extends TestCase
{
    private const COMPANY_ID = '99999999-1111-4111-8111-999999999999';
    private const PROFILE_ID = '99999999-2222-4222-8222-999999999999';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('entity_identity_aliases');
        Schema::dropIfExists('entity_identities');
        Schema::dropIfExists('business_profiles');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Company::create([
            'id' => self::COMPANY_ID,
            'name' => 'Acme Corp',
        ]);
    }

    public function test_falls_back_to_legacy_hash_before_identity_tables_exist(): void
    {
        $service = app(StableEntityIdentityService::class);

        $id = $service->vendorId(self::COMPANY_ID, 'Northstar Consulting');

        $this->assertSame(
            md5(self::COMPANY_ID.'|vendor|northstar consulting'),
            $id
        );
    }

    public function test_returns_stable_uuid_and_records_legacy_alias_when_tables_exist(): void
    {
        $this->createIdentityTables();
        $service = app(StableEntityIdentityService::class);

        $first = $service->vendorId(self::COMPANY_ID, 'Northstar Consulting', self::PROFILE_ID);
        $second = $service->vendorId(self::COMPANY_ID, ' northstar   consulting ', self::PROFILE_ID);
        $legacyHash = md5(self::COMPANY_ID.'|vendor|northstar consulting');

        $this->assertTrue(Str::isUuid($first));
        $this->assertSame($first, $second);
        $this->assertNotSame($legacyHash, $first);

        $this->assertDatabaseHas('entity_identities', [
            'id' => $first,
            'company_id' => self::COMPANY_ID,
            'business_profile_id' => self::PROFILE_ID,
            'entity_type' => 'vendor',
            'canonical_key' => 'vendor:northstar consulting',
            'legacy_identity_hash' => $legacyHash,
        ]);

        $this->assertDatabaseHas('entity_identity_aliases', [
            'entity_identity_id' => $first,
            'company_id' => self::COMPANY_ID,
            'entity_type' => 'vendor',
            'alias_type' => 'legacy_hash',
            'normalized_alias' => $legacyHash,
        ]);
    }

    public function test_resolves_transaction_scoped_entities_with_legacy_aliases(): void
    {
        $this->createIdentityTables();
        $service = app(StableEntityIdentityService::class);
        $transactionId = '99999999-3333-4333-8333-999999999999';

        $approverId = $service->approverId(self::COMPANY_ID, $transactionId, businessProfileId: self::PROFILE_ID);
        $documentId = $service->documentId(self::COMPANY_ID, $transactionId, businessProfileId: self::PROFILE_ID);
        $bankAccountId = $service->bankAccountId(self::COMPANY_ID, businessProfileId: self::PROFILE_ID);

        $this->assertTrue(Str::isUuid($approverId));
        $this->assertTrue(Str::isUuid($documentId));
        $this->assertTrue(Str::isUuid($bankAccountId));

        $this->assertSame(
            [
                'approver' => md5(self::COMPANY_ID.'|approver|'.$transactionId),
                'bank_account' => md5(self::COMPANY_ID.'|bank_account|default'),
                'document' => md5(self::COMPANY_ID.'|document|'.$transactionId),
            ],
            DB::table('entity_identities')
                ->where('company_id', self::COMPANY_ID)
                ->whereIn('entity_type', ['approver', 'document', 'bank_account'])
                ->orderBy('entity_type')
                ->pluck('legacy_identity_hash', 'entity_type')
                ->all()
        );
    }

    private function createIdentityTables(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        DB::table('business_profiles')->insert([
            'id' => self::PROFILE_ID,
            'company_id' => self::COMPANY_ID,
            'name' => 'Acme Corp',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'scope_key', 'entity_type', 'canonical_key'], 'entity_identities_scope_type_key_unique');
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
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'scope_key', 'entity_type', 'alias_type', 'normalized_alias'], 'entity_aliases_lookup_unique');
        });
    }
}
