<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('qbo_transactions');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('owner');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('provider', 50);
            $table->string('realm_id', 255)->nullable();
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->text('client_id_enc')->nullable();
            $table->text('client_secret_enc')->nullable();
            $table->string('environment', 50)->nullable();
            $table->string('sync_status', 50)->nullable();
            $table->unsignedSmallInteger('sync_progress')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('qbo_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('qbo_id', 255)->nullable();
            $table->string('realm_id', 255)->nullable();
            $table->date('transaction_date')->nullable();
            $table->text('vendor_name')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('type')->nullable();
            $table->text('status')->default('active');
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }

    public function test_integration_status_returns_empty_list_without_connections(): void
    {
        [, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertJson(['integrations' => []]);
    }

    public function test_integration_status_returns_quickbooks_connections(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);

        $now = now();
        $integrationId = (string) Str::uuid();

        DB::table('integrations')->insert([
            'id' => $integrationId,
            'company_id' => $company->id,
            'provider' => 'quickbooks',
            'realm_id' => '12345',
            'access_token_enc' => encrypt('access-token'),
            'refresh_token_enc' => encrypt('refresh-token'),
            'environment' => 'sandbox',
            'sync_status' => 'idle',
            'sync_progress' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertJsonPath('integrations.0.provider', 'quickbooks')
            ->assertJsonPath('integrations.0.realm_id', '12345')
            ->assertJsonPath('integrations.0.is_connected', true)
            ->assertJsonPath('integrations.0.sync_status', 'idle');
    }

    public function test_qbo_sync_reaches_terminal_state(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);

        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/12345/query*' => function ($request) {
                $query = (string) $request->data()['query'];

                if (str_contains($query, 'FROM Purchase')) {
                    return Http::response([
                        'QueryResponse' => [
                            'Purchase' => [
                                [
                                    'Id' => '99',
                                    'TxnDate' => '2026-05-01',
                                    'EntityRef' => ['name' => 'Acme Supplies'],
                                    'TotalAmt' => 42.50,
                                ],
                            ],
                        ],
                    ]);
                }

                return Http::response(['QueryResponse' => []]);
            },
        ]);

        DB::table('integrations')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'provider' => 'quickbooks',
            'realm_id' => '12345',
            'access_token_enc' => encrypt('access-token'),
            'refresh_token_enc' => encrypt('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'environment' => 'sandbox',
            'sync_status' => 'idle',
            'sync_progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/integrations/qbo/sync', ['realmId' => '12345'])
            ->assertOk()
            ->assertJsonPath('sync_status', 'idle')
            ->assertJsonPath('sync_progress', 100)
            ->assertJsonPath('imported_count', 1);

        $this->assertDatabaseHas('integrations', [
            'company_id' => $company->id,
            'provider' => 'quickbooks',
            'realm_id' => '12345',
            'sync_status' => 'idle',
            'sync_progress' => 100,
        ]);

        $this->assertDatabaseHas('qbo_transactions', [
            'company_id' => $company->id,
            'realm_id' => '12345',
            'qbo_id' => 'Purchase:99',
            'vendor_name' => 'Acme Supplies',
            'amount' => 42.50,
            'type' => 'expense',
        ]);
    }

    public function test_integration_status_marks_stale_syncs_failed(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);

        DB::table('integrations')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'provider' => 'quickbooks',
            'realm_id' => '12345',
            'access_token_enc' => encrypt('access-token'),
            'refresh_token_enc' => encrypt('refresh-token'),
            'environment' => 'sandbox',
            'sync_status' => 'syncing',
            'sync_progress' => 0,
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(31),
        ]);

        $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertJsonPath('integrations.0.sync_status', 'failed')
            ->assertJsonPath('integrations.0.sync_error', 'Sync did not complete within 30 minutes. Please try again.');
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        $company = new Company([
            'name' => 'Brevix Test Co',
        ]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }
}
