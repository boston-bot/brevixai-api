<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\Agents\VendorRiskScoringService;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorRiskScoringTest extends TestCase
{
    private VendorRiskScoringService $scoringService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevix_agent.base_url' => 'http://agent.test',
            'services.brevix_agent.api_key' => 'test-agent-key',
            'services.brevix_agent.timeout' => 10,
        ]);

        $this->scoringService = app(VendorRiskScoringService::class);

        // Prepare schema and seed data
        $this->createSchema();
        $this->seed(Phase1AgentFraudScenarioSeeder::class);
    }

    /**
     * Test high concentration risk triggers for Mega Vendor LLC.
     */
    public function test_high_concentration_risk_vendor(): void
    {
        $result = $this->scoringService->scoreVendor(
            Phase1AgentFraudScenarioSeeder::COMPANY_ID,
            'Mega Vendor LLC'
        );

        $this->assertSame('Mega Vendor LLC', $result['vendor_name']);
        $this->assertContains('vendor_concentration', array_column($result['triggered_rules'], 'rule_key'));
        $this->assertGreaterThanOrEqual(20, $result['vendor_risk_score']);
        $this->assertNotEmpty($result['supporting_evidence']['vendor_concentration']);
        $this->assertGreaterThan(25.0, $result['supporting_evidence']['vendor_concentration']['concentration_percentage']);
    }

    /**
     * Test threshold splitting triggers for Northstar Consulting.
     */
    public function test_threshold_splitting_vendor(): void
    {
        $result = $this->scoringService->scoreVendor(
            Phase1AgentFraudScenarioSeeder::COMPANY_ID,
            'Northstar Consulting'
        );

        $this->assertSame('Northstar Consulting', $result['vendor_name']);
        $this->assertContains('threshold_splitting', array_column($result['triggered_rules'], 'rule_key'));
        $this->assertGreaterThanOrEqual(20, $result['vendor_risk_score']);
        $this->assertNotEmpty($result['supporting_evidence']['threshold_splitting']);
    }

    /**
     * Test round-dollar behavior triggers for Roundhouse Services.
     */
    public function test_round_dollar_payment_patterns(): void
    {
        $result = $this->scoringService->scoreVendor(
            Phase1AgentFraudScenarioSeeder::COMPANY_ID,
            'Roundhouse Services'
        );

        $this->assertSame('Roundhouse Services', $result['vendor_name']);
        $this->assertContains('round_dollar', array_column($result['triggered_rules'], 'rule_key'));
        $this->assertGreaterThanOrEqual(15, $result['vendor_risk_score']);
        $this->assertNotEmpty($result['supporting_evidence']['round_dollar']);
        $this->assertEquals(100.0, $result['supporting_evidence']['round_dollar']['round_dollar_percentage']);
    }

    /**
     * Test duplicate/similar vendor name matching.
     */
    public function test_duplicate_vendor_similarity(): void
    {
        // Add a mock similar vendor transaction to trigger the similarity rule by assigning properties directly
        $txn = new Transaction;
        $txn->id = '66666666-9999-4666-8666-666666666666';
        $txn->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $txn->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $txn->txn_id = 'SIMILAR_VNDR';
        $txn->date = '2026-05-12';
        $txn->vendor_customer = 'Acme Supply'; // Highly similar to 'Acme Supplies'
        $txn->type = 'expense';
        $txn->category = 'Operations';
        $txn->amount = 500.00;
        $txn->save();

        $result = $this->scoringService->scoreVendor(
            Phase1AgentFraudScenarioSeeder::COMPANY_ID,
            'Acme Supplies'
        );

        $this->assertSame('Acme Supplies', $result['vendor_name']);
        $this->assertContains('similar_vendor_name', array_column($result['triggered_rules'], 'rule_key'));
        $this->assertGreaterThanOrEqual(15, $result['vendor_risk_score']);
        $this->assertNotEmpty($result['supporting_evidence']['similar_vendor_name']);
        $this->assertSame('Acme Supply', $result['supporting_evidence']['similar_vendor_name']['similar_vendors'][0]['name']);
    }

    /**
     * Test clean vendor triggers minimal risk and has low risk status.
     */
    public function test_low_risk_vendor_no_false_positives(): void
    {
        $result = $this->scoringService->scoreVendor(
            Phase1AgentFraudScenarioSeeder::COMPANY_ID,
            'Clean Vendor'
        );

        $this->assertSame('Clean Vendor', $result['vendor_name']);

        // Clean Vendor is first seen on 2026-05-15, which is the latest date in the entire ledger,
        // so it will naturally trigger 'new_vendor' risk rule deterministically (and correctly).
        // However, it should NOT trigger other fraud patterns (like duplicate, splitting, concentration, or round dollar).
        $triggeredKeys = array_column($result['triggered_rules'], 'rule_key');
        $this->assertNotContains('vendor_concentration', $triggeredKeys);
        $this->assertNotContains('threshold_splitting', $triggeredKeys);
        $this->assertNotContains('round_dollar', $triggeredKeys);
        $this->assertNotContains('similar_vendor_name', $triggeredKeys);

        // Score should be low risk
        $this->assertLessThanOrEqual(40, $result['vendor_risk_score']);
        $this->assertSame('low', $result['risk_level']);
    }

    /**
     * Test API endpoint authentication and response structure consistency.
     */
    public function test_vendor_risk_api_endpoint_structure_and_auth(): void
    {
        // 1. Assert auth protection (requires token)
        $unauth = $this->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/vendor-risk');
        $unauth->assertStatus(401); // Unauthorized by agent.tool middleware if token is missing

        // 2. Access with valid token and fetch all vendors
        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/vendor-risk');

        $response->assertOk();
        $response->assertJsonStructure([
            'vendors' => [
                '*' => [
                    'vendor_name',
                    'vendor_risk_score',
                    'risk_level',
                    'triggered_rules',
                    'rule_weights',
                    'supporting_evidence',
                    'recommended_next_action',
                ],
            ],
        ]);

        // 3. Access with specific vendor name
        $singleResponse = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/vendor-risk?vendor=Roundhouse Services');

        $singleResponse->assertOk();
        $singleResponse->assertJsonStructure([
            'vendor_name',
            'vendor_risk_score',
            'risk_level',
            'triggered_rules',
            'rule_weights',
            'supporting_evidence',
            'recommended_next_action',
        ]);

        $this->assertSame('Roundhouse Services', $singleResponse->json('vendor_name'));
    }

    /**
     * Test stable scoring consistency (multiple requests return identical scoring data).
     */
    public function test_stable_scoring_consistency(): void
    {
        $run1 = $this->scoringService->scoreVendor(
            Phase1AgentFraudScenarioSeeder::COMPANY_ID,
            'Acme Supplies'
        );

        $run2 = $this->scoringService->scoreVendor(
            Phase1AgentFraudScenarioSeeder::COMPANY_ID,
            'Acme Supplies'
        );

        $this->assertEquals($run1, $run2);
    }

    private function createSchema(): void
    {
        foreach ([
            'agent_action_approvals',
            'agent_steps',
            'agent_runs',
            'alerts',
            'reconciliation_discrepancies',
            'reconciliation_results',
            'transactions',
            'uploads',
            'business_profile_memberships',
            'workspace_memberships',
            'business_profiles',
            'users',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('website')->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('has_completed_onboarding')->default(false);
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

        Schema::create('uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('uploaded_by');
            $table->text('filename');
            $table->integer('file_size')->nullable();
            $table->text('status')->default('processing');
            $table->json('sheets_parsed')->nullable();
            $table->integer('row_count')->default(0);
            $table->text('import_type')->nullable();
            $table->text('original_filename')->nullable();
            $table->text('storage_filename')->nullable();
            $table->text('claimed_content_type')->nullable();
            $table->text('file_extension')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->text('sha256')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('upload_id');
            $table->foreignUuid('company_id');
            $table->text('txn_id')->nullable();
            $table->date('date')->nullable();
            $table->text('department')->nullable();
            $table->text('vendor_customer')->nullable();
            $table->text('type')->nullable();
            $table->text('category')->nullable();
            $table->text('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('invoice_ref')->nullable();
            $table->text('memo')->nullable();
            $table->boolean('anomaly_flag')->default(false);
            $table->text('anomaly_reason')->nullable();
            $table->json('raw_row')->nullable();
            $table->uuid('import_batch_id')->nullable();
            $table->text('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->text('validation_status')->nullable();
            $table->json('parse_warnings')->nullable();
            $table->text('row_content_hash')->nullable();
        });

        Schema::create('reconciliation_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->timestamp('run_at')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('total_mismatches')->default(0);
            $table->decimal('total_impact', 15, 2)->default(0);
            $table->text('status')->default('completed');
            $table->json('results');
        });

        Schema::create('reconciliation_discrepancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('run_id');
            $table->uuid('bank_txn_id')->nullable();
            $table->uuid('ledger_txn_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('category');
            $table->text('reason_code');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->text('risk_level');
            $table->text('recommended_action');
            $table->text('recommendation_explanation');
            $table->text('status')->default('new');
            $table->text('resolution_notes')->nullable();
            $table->json('metadata');
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->uuid('group_id')->nullable();
            $table->text('rule_key');
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->text('status')->default('open');
            $table->integer('priority_score')->default(50);
            $table->foreignUuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }
}
