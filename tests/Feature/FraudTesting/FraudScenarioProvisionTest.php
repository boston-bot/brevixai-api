<?php

namespace Tests\Feature\FraudTesting;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FraudScenarioProvisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fraud_testing.api_key' => 'test-fraud-token']);
        $this->createAppTables();
        $this->createFraudTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'fraud_mock_transactions', 'fraud_mock_parties', 'fraud_mock_companies',
            'fraud_investigation_questions', 'fraud_document_requests',
            'fraud_expected_findings', 'fraud_expected_indicators',
            'fraud_scenario_extractions', 'fraud_scenario_submissions',
            'transactions', 'uploads', 'business_profile_memberships',
            'business_profiles', 'workspace_memberships', 'subscriptions', 'users', 'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    // ─── Auth ────────────────────────────────────────────────────────────────

    public function test_provision_requires_token(): void
    {
        $id = $this->insertScenario();
        $this->postJson("/api/internal/fraud-testing/scenarios/{$id}/provision-workspace")
            ->assertUnauthorized();
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_provision_returns_404_for_unknown_scenario(): void
    {
        $this->withToken('test-fraud-token')
            ->postJson('/api/internal/fraud-testing/scenarios/' . Str::uuid() . '/provision-workspace')
            ->assertNotFound();
    }

    public function test_provision_returns_422_when_mock_data_not_ready(): void
    {
        $id = $this->insertScenario(['mock_data_status' => 'pending']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$id}/provision-workspace")
            ->assertUnprocessable()
            ->assertJsonPath('error', fn ($v) => str_contains($v, 'pending'));
    }

    public function test_provision_returns_422_when_no_mock_company(): void
    {
        $id = $this->insertScenario(['mock_data_status' => 'completed']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$id}/provision-workspace")
            ->assertUnprocessable()
            ->assertJsonPath('error', fn ($v) => str_contains($v, 'mock company'));
    }

    // ─── Happy path ──────────────────────────────────────────────────────────

    public function test_provision_creates_workspace_and_returns_credentials(): void
    {
        [$scenarioId, $mockCompanyId, $partyId] = $this->insertScenarioWithMockData();

        $response = $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$scenarioId}/provision-workspace")
            ->assertCreated();

        $data = $response->json();
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('password', $data);
        $this->assertArrayHasKey('workspace_id', $data);
        $this->assertArrayHasKey('transaction_count', $data);
        $this->assertEquals(2, $data['transaction_count']);
        $this->assertEquals($scenarioId, $data['scenario_id']);
    }

    public function test_provision_creates_company_record(): void
    {
        [$scenarioId] = $this->insertScenarioWithMockData();

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$scenarioId}/provision-workspace")
            ->assertCreated();

        $this->assertDatabaseHas('companies', ['name' => 'Acme Corp']);
    }

    public function test_provision_creates_user_with_valid_credentials(): void
    {
        [$scenarioId] = $this->insertScenarioWithMockData();

        $response = $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$scenarioId}/provision-workspace")
            ->assertCreated();

        $email = $response->json('email');
        $password = $response->json('password');

        $this->assertDatabaseHas('users', ['email' => $email]);

        // Verify credentials work for login
        $loginResponse = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        $loginResponse->assertOk()->assertJsonStructure(['token']);
    }

    public function test_provision_seeds_transactions(): void
    {
        [$scenarioId, $mockCompanyId, $partyId] = $this->insertScenarioWithMockData();

        $response = $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$scenarioId}/provision-workspace")
            ->assertCreated();

        $workspaceId = $response->json('workspace_id');

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseHas('transactions', [
            'company_id' => $workspaceId,
            'txn_id' => 'TXN-001',
            'vendor_customer' => 'John Smith',
            'anomaly_flag' => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'company_id' => $workspaceId,
            'txn_id' => 'TXN-002',
            'vendor_customer' => 'Jane Doe',
            'anomaly_flag' => 0,
        ]);
    }

    public function test_provision_each_call_creates_fresh_workspace(): void
    {
        [$scenarioId] = $this->insertScenarioWithMockData();

        $r1 = $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$scenarioId}/provision-workspace")
            ->assertCreated();

        $r2 = $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$scenarioId}/provision-workspace")
            ->assertCreated();

        $this->assertNotEquals($r1->json('workspace_id'), $r2->json('workspace_id'));
        $this->assertNotEquals($r1->json('email'), $r2->json('email'));
        $this->assertDatabaseCount('companies', 2);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function insertScenario(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('fraud_scenario_submissions')->insert(array_merge([
            'id' => $id,
            'title' => 'Ghost Employee Test',
            'narrative' => 'A payroll manager created a fictitious employee.',
            'status' => 'processed',
            'extraction_status' => 'completed',
            'mock_data_status' => 'pending',
            'review_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
        return $id;
    }

    /** @return array{string, string, string} [scenarioId, mockCompanyId, partyId] */
    private function insertScenarioWithMockData(): array
    {
        $scenarioId = $this->insertScenario(['mock_data_status' => 'completed']);

        $mockCompanyId = (string) Str::uuid();
        DB::table('fraud_mock_companies')->insert([
            'id' => $mockCompanyId,
            'scenario_submission_id' => $scenarioId,
            'company_name' => 'Acme Corp',
            'industry' => 'Manufacturing',
            'entity_type' => 'LLC',
            'profile_payload' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $partyId1 = (string) Str::uuid();
        $partyId2 = (string) Str::uuid();
        DB::table('fraud_mock_parties')->insert([
            [
                'id' => $partyId1,
                'scenario_submission_id' => $scenarioId,
                'mock_company_id' => $mockCompanyId,
                'party_type' => 'employee',
                'party_name' => 'John Smith',
                'is_fraud_actor' => 1,
                'is_related_party' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $partyId2,
                'scenario_submission_id' => $scenarioId,
                'mock_company_id' => $mockCompanyId,
                'party_type' => 'vendor',
                'party_name' => 'Jane Doe',
                'is_fraud_actor' => 0,
                'is_related_party' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('fraud_mock_transactions')->insert([
            [
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $scenarioId,
                'mock_company_id' => $mockCompanyId,
                'external_transaction_id' => 'TXN-001',
                'transaction_type' => 'expense',
                'transaction_date' => '2024-01-15',
                'amount' => 1500.00,
                'party_id' => $partyId1,
                'account_category' => 'payroll',
                'description' => 'Ghost payroll payment',
                'is_fraudulent' => 1,
                'fraud_pattern' => 'ghost_employee',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $scenarioId,
                'mock_company_id' => $mockCompanyId,
                'external_transaction_id' => 'TXN-002',
                'transaction_type' => 'expense',
                'transaction_date' => '2024-01-20',
                'amount' => 500.00,
                'party_id' => $partyId2,
                'account_category' => 'supplies',
                'description' => 'Office supplies',
                'is_fraudulent' => 0,
                'fraud_pattern' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [$scenarioId, $mockCompanyId, $partyId1];
    }

    private function createAppTables(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('has_completed_onboarding')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('company_id')->primary();
            $table->string('tier')->default('free');
            $table->string('status')->default('active');
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->timestamp('current_period_end')->nullable();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('role');
            $table->string('scope')->nullable();
            $table->uuid('granted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('business_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('business_profile_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_profile_id');
            $table->uuid('user_id');
            $table->string('role');
            $table->timestamps();
        });

        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('uploaded_by');
            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('status')->default('processing');
            $table->string('import_type')->nullable();
            $table->integer('row_count')->default(0);
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('txn_id')->nullable();
            $table->date('date')->nullable();
            $table->string('vendor_customer')->nullable();
            $table->string('type')->nullable();
            $table->string('category')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('memo')->nullable();
            $table->boolean('anomaly_flag')->default(false);
            $table->string('anomaly_reason')->nullable();
            $table->string('validation_status')->nullable();
            $table->string('row_content_hash')->nullable();
        });

        // Sanctum tokens table
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function createFraudTables(): void
    {
        Schema::create('fraud_scenario_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id')->nullable();
            $table->string('external_scenario_id')->nullable();
            $table->string('title');
            $table->text('narrative');
            $table->string('source')->nullable();
            $table->string('severity')->nullable();
            $table->string('status')->default('imported');
            $table->string('extraction_status')->default('pending');
            $table->string('mock_data_status')->default('pending');
            $table->string('review_status')->default('unreviewed');
            $table->integer('row_number')->nullable();
            $table->text('raw_row')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_scenario_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->text('structured_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_expected_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('indicator_key');
            $table->string('indicator_name');
            $table->timestamps();
        });

        Schema::create('fraud_expected_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('finding_key');
            $table->string('finding_title');
            $table->text('finding_description');
            $table->timestamps();
        });

        Schema::create('fraud_document_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('document_name');
            $table->timestamps();
        });

        Schema::create('fraud_investigation_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->text('question');
            $table->timestamps();
        });

        Schema::create('fraud_mock_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('company_name');
            $table->string('industry')->nullable();
            $table->string('entity_type')->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->integer('employee_count')->nullable();
            $table->integer('vendor_count')->nullable();
            $table->integer('customer_count')->nullable();
            $table->integer('months_of_activity')->nullable();
            $table->text('profile_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_mock_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->uuid('mock_company_id')->nullable();
            $table->string('external_party_id')->nullable();
            $table->string('party_type');
            $table->string('party_name');
            $table->string('role')->nullable();
            $table->boolean('is_fraud_actor')->default(false);
            $table->boolean('is_related_party')->default(false);
            $table->text('attributes')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_mock_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->uuid('mock_company_id')->nullable();
            $table->string('external_transaction_id')->nullable();
            $table->string('transaction_type');
            $table->date('transaction_date')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->uuid('party_id')->nullable();
            $table->string('account_category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_fraudulent')->default(false);
            $table->string('fraud_pattern')->nullable();
            $table->string('expected_brevix_signal')->nullable();
            $table->text('payload')->nullable();
            $table->timestamps();
        });
    }
}
