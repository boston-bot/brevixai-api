<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\EntityRelationshipRiskScoringService;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EntityRelationshipRiskScoringTest extends TestCase
{
    private EntityRelationshipRiskScoringService $scoringService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevix_agent.base_url' => 'http://agent.test',
            'services.brevix_agent.api_key' => 'test-agent-key',
            'services.brevix_agent.timeout' => 10,
        ]);

        $this->scoringService = app(EntityRelationshipRiskScoringService::class);

        $this->createSchema();
        $this->seedBaseData();
    }

    /**
     * Test clean entity relationships scenario yields zero risk score.
     */
    public function test_clean_entity_relationships(): void
    {
        // Add a single unique clean transaction to populate DB
        $tx = new Transaction();
        $tx->id = '99999999-0001-4999-9999-999999999999';
        $tx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx->vendor_customer = 'Unique Clean Vendor Ltd';
        $tx->amount = 1200.00;
        $tx->save();

        $result = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(0, $result['entity_relationship_risk_score']);
        $this->assertSame('low', $result['risk_level']);
        $this->assertEmpty($result['triggered_rules']);
        $this->assertEmpty($result['related_entities']);
    }

    /**
     * Test employee/vendor overlap detection.
     */
    public function test_employee_vendor_overlap(): void
    {
        // Add a transaction where the vendor matches the employee name ("Test User")
        $tx = new Transaction();
        $tx->id = '99999999-0002-4999-9999-999999999999';
        $tx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx->vendor_customer = 'Test User Supplies';
        $tx->amount = 2500.00;
        $tx->save();

        $result = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(20, $result['entity_relationship_risk_score']);
        $triggeredKeys = array_column($result['triggered_rules'], 'rule_key');
        $this->assertContains('employee_vendor_overlap', $triggeredKeys);
        
        $overlaps = $result['supporting_evidence']['employee_vendor_overlap']['overlaps'];
        $this->assertNotEmpty($overlaps);
        $this->assertSame('Test User', $overlaps[0]['employee_name']);
        $this->assertContains('Test User Supplies', $overlaps[0]['matched_vendors']);
    }

    /**
     * Test shared bank account across vendors.
     */
    public function test_shared_bank_account(): void
    {
        // Create an alert indicating shared bank account
        $alert = new Alert();
        $alert->id = '99999999-0003-4999-9999-999999999999';
        $alert->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $alert->rule_key = 'shared_bank_account';
        $alert->severity = 'warning';
        $alert->title = 'Shared Bank Account Anomaly';
        $alert->detail = 'Vendor A and Vendor B share identical bank account routing details.';
        $alert->evidence = ['metadata' => ['related_vendors' => ['Vendor A', 'Vendor B']]];
        $alert->status = 'open';
        $alert->save();

        $result = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(20, $result['entity_relationship_risk_score']);
        $triggeredKeys = array_column($result['triggered_rules'], 'rule_key');
        $this->assertContains('shared_bank_account', $triggeredKeys);

        $related = $result['related_entities'];
        $this->assertNotEmpty($related);
        $this->assertSame('shared_banking', $related[0]['type']);
        $this->assertEquals(['Vendor A', 'Vendor B'], $related[0]['entities']);
    }

    /**
     * Test duplicate vendor identity clusters.
     */
    public function test_duplicate_vendor_cluster(): void
    {
        // Seed two closely spelling-similar vendor transactions
        $tx1 = new Transaction();
        $tx1->id = '99999999-0004-4999-9999-999999999999';
        $tx1->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx1->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx1->vendor_customer = 'Northstar Consulting';
        $tx1->amount = 3000.00;
        $tx1->save();

        $tx2 = new Transaction();
        $tx2->id = '99999999-0005-4999-9999-999999999999';
        $tx2->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx2->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx2->vendor_customer = 'Northstr Consulting'; // Levenshtein distance 1
        $tx2->amount = 3000.00;
        $tx2->save();

        $result = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        // Triggers duplicate_vendor_cluster (15) + unusual_concentration (10, since 100% of spend is in cluster) = 25
        $this->assertSame(25, $result['entity_relationship_risk_score']);
        $triggeredKeys = array_column($result['triggered_rules'], 'rule_key');
        $this->assertContains('duplicate_vendor_cluster', $triggeredKeys);
        $this->assertContains('unusual_concentration', $triggeredKeys);
    }

    /**
     * Test shared address indicator.
     */
    public function test_shared_address(): void
    {
        // Seed shared address alert
        $alert = new Alert();
        $alert->id = '99999999-0006-4999-9999-999999999999';
        $alert->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $alert->rule_key = 'shared_address';
        $alert->severity = 'warning';
        $alert->title = 'Shared Physical Address';
        $alert->detail = 'Multiple accounts registered under identical suite address.';
        $alert->evidence = ['metadata' => ['related_entities' => ['Vendor A', 'Employee X']]];
        $alert->status = 'open';
        $alert->save();

        $result = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(15, $result['entity_relationship_risk_score']);
        $triggeredKeys = array_column($result['triggered_rules'], 'rule_key');
        $this->assertContains('shared_address', $triggeredKeys);
    }

    /**
     * Test no false positive on common public SaaS/utilities data.
     */
    public function test_no_false_positive_on_common_public_data(): void
    {
        // Seed two common public data vendor names with similar spellings
        $tx1 = new Transaction();
        $tx1->id = '99999999-0007-4999-9999-999999999999';
        $tx1->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx1->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx1->vendor_customer = 'Google Suite';
        $tx1->amount = 150.00;
        $tx1->save();

        $tx2 = new Transaction();
        $tx2->id = '99999999-0008-4999-9999-999999999999';
        $tx2->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx2->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx2->vendor_customer = 'Google Cloud'; // Levenshtein dist < 5, but contains google, so skipped
        $tx2->amount = 2000.00;
        $tx2->save();

        $result = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(0, $result['entity_relationship_risk_score']);
        $this->assertEmpty($result['triggered_rules']);
    }

    /**
     * Test stable scoring consistency across requests.
     */
    public function test_stable_scoring_consistency(): void
    {
        $tx = new Transaction();
        $tx->id = '99999999-0009-4999-9999-999999999999';
        $tx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx->vendor_customer = 'Test User Office Supplies';
        $tx->amount = 500.00;
        $tx->save();

        $run1 = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);
        $run2 = $this->scoringService->scoreEntityRelationships(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertEquals($run1, $run2);
    }

    /**
     * Test API endpoint authentication and response payload structure.
     */
    public function test_api_endpoint_auth_and_structure(): void
    {
        // 1. Assert unauthorized without token
        $this->getJson('/api/internal/agent-tools/company/' . Phase1AgentFraudScenarioSeeder::COMPANY_ID . '/entity-relationship-risk')
            ->assertStatus(401);

        // 2. Access with valid token and assert structure
        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/company/' . Phase1AgentFraudScenarioSeeder::COMPANY_ID . '/entity-relationship-risk');

        $response->assertOk()
            ->assertJsonStructure([
                'company_id',
                'entity_relationship_risk_score',
                'risk_level',
                'triggered_rules',
                'rule_weights',
                'supporting_evidence',
                'related_entities',
                'recommended_next_action'
            ]);
    }

    private function seedBaseData(): void
    {
        // Seed Company
        $company = new Company();
        $company->id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $company->name = 'Acme Corp';
        $company->save();

        // Seed User
        $user = new User();
        $user->id = Phase1AgentFraudScenarioSeeder::USER_ID;
        $user->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $user->email = 'admin@acme.com';
        $user->password_hash = 'secret';
        $user->first_name = 'Test';
        $user->last_name = 'User';
        $user->save();

        // Seed Upload
        $upload = new Upload();
        $upload->id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $upload->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $upload->uploaded_by = Phase1AgentFraudScenarioSeeder::USER_ID;
        $upload->filename = 'ledger.xlsx';
        $upload->status = 'completed';
        $upload->save();
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
