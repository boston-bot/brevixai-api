<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ReconciliationDiscrepancy;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\AlertRecommendationService;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AlertRecommendationServiceTest extends TestCase
{
    private AlertRecommendationService $recommendationService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevix_agent.base_url' => 'http://agent.test',
            'services.brevix_agent.api_key' => 'test-agent-key',
            'services.brevix_agent.timeout' => 10,
        ]);

        $this->createSchema();
        $this->seedBaseData();

        $this->recommendationService = app(AlertRecommendationService::class);
    }

    public function test_no_alert_recommendations_for_low_risk_company(): void
    {
        $result = $this->recommendationService->getAlertRecommendations(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(Phase1AgentFraudScenarioSeeder::COMPANY_ID, $result['company_id']);
        $this->assertSame([], $result['recommended_alerts']);
    }

    public function test_high_vendor_risk_creates_recommendation_only(): void
    {
        $this->seedVendorRiskTransactions(firstDate: '2026-05-11', secondDate: '2026-05-12');

        $result = $this->recommendationService->getAlertRecommendations(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertCount(1, $result['recommended_alerts']);

        $recommendation = $result['recommended_alerts'][0];
        $this->assertSame('vendor_risk_review', $recommendation['alert_type']);
        $this->assertSame('high', $recommendation['severity']);
        $this->assertSame('vendor_risk', $recommendation['source_risk_domain']);
        $this->assertContains('threshold_splitting', $recommendation['source_rule_ids']);
        $this->assertTrue($recommendation['requires_human_review']);
        $this->assertFalse($recommendation['can_auto_create']);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_reconciliation_mismatch_creates_recommendation_only(): void
    {
        $this->seedReconciliationMismatch();

        $result = $this->recommendationService->getAlertRecommendations(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertCount(1, $result['recommended_alerts']);

        $recommendation = $result['recommended_alerts'][0];
        $this->assertSame('reconciliation_risk_review', $recommendation['alert_type']);
        $this->assertSame('medium', $recommendation['severity']);
        $this->assertSame('reconciliation_risk', $recommendation['source_risk_domain']);
        $this->assertContains('bank_ledger_mismatch', $recommendation['source_rule_ids']);
        $this->assertContains('unmatched_deposits', $recommendation['source_rule_ids']);
        $this->assertTrue($recommendation['requires_human_review']);
        $this->assertFalse($recommendation['can_auto_create']);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_entity_relationship_issue_creates_recommendation_only(): void
    {
        $this->seedEmployeeVendorOverlap();

        $result = $this->recommendationService->getAlertRecommendations(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertCount(1, $result['recommended_alerts']);

        $recommendation = $result['recommended_alerts'][0];
        $this->assertSame('entity_relationship_review', $recommendation['alert_type']);
        $this->assertSame('medium', $recommendation['severity']);
        $this->assertSame('entity_relationship_risk', $recommendation['source_risk_domain']);
        $this->assertContains('employee_vendor_overlap', $recommendation['source_rule_ids']);
        $this->assertTrue($recommendation['requires_human_review']);
        $this->assertFalse($recommendation['can_auto_create']);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_critical_aggregate_risk_creates_multiple_recommendations(): void
    {
        $this->seedVendorRiskTransactions(firstDate: '2026-05-10', secondDate: '2026-05-12');
        $this->seedReconciliationMismatch();
        $this->seedEmployeeVendorOverlap();

        $result = $this->recommendationService->getAlertRecommendations(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $alertTypes = array_column($result['recommended_alerts'], 'alert_type');

        $this->assertGreaterThanOrEqual(4, count($result['recommended_alerts']));
        $this->assertContains('critical_aggregate_risk_review', $alertTypes);
        $this->assertContains('vendor_risk_review', $alertTypes);
        $this->assertContains('reconciliation_risk_review', $alertTypes);
        $this->assertContains('entity_relationship_review', $alertTypes);
    }

    public function test_all_recommendations_require_human_review(): void
    {
        $this->seedCriticalRiskScenario();

        $result = $this->recommendationService->getAlertRecommendations(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertNotEmpty($result['recommended_alerts']);
        foreach ($result['recommended_alerts'] as $recommendation) {
            $this->assertTrue($recommendation['requires_human_review']);
        }
    }

    public function test_can_auto_create_is_always_false(): void
    {
        $this->seedCriticalRiskScenario();

        $result = $this->recommendationService->getAlertRecommendations(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertNotEmpty($result['recommended_alerts']);
        foreach ($result['recommended_alerts'] as $recommendation) {
            $this->assertFalse($recommendation['can_auto_create']);
        }
    }

    public function test_backward_compatible_risk_summary_shape(): void
    {
        $this->seedVendorRiskTransactions(firstDate: '2026-05-11', secondDate: '2026-05-12');

        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/companies/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/risk-summary?period=2026-05');

        $response->assertOk()
            ->assertJsonStructure([
                'company_id',
                'risk_score',
                'risk_level',
                'period',
                'top_drivers',
                'stats' => [
                    'totalTransactions',
                    'flaggedAlerts',
                    'reconciliationMismatches',
                ],
                'alert_breakdown',
                'aggregate_summary',
                'alert_recommendations' => [
                    'company_id',
                    'recommended_alerts' => [
                        '*' => [
                            'alert_type',
                            'severity',
                            'title',
                            'summary',
                            'evidence',
                            'source_risk_domain',
                            'source_rule_ids',
                            'confidence_score',
                            'requires_human_review',
                            'can_auto_create',
                        ],
                    ],
                ],
            ]);

        $this->assertSame(Phase1AgentFraudScenarioSeeder::COMPANY_ID, $response->json('alert_recommendations.company_id'));
    }

    public function test_api_endpoint_auth_and_structure(): void
    {
        $this->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/alert-recommendations')
            ->assertStatus(401);

        $this->seedVendorRiskTransactions(firstDate: '2026-05-11', secondDate: '2026-05-12');

        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/alert-recommendations');

        $response->assertOk()
            ->assertJsonStructure([
                'company_id',
                'recommended_alerts' => [
                    '*' => [
                        'alert_type',
                        'severity',
                        'title',
                        'summary',
                        'evidence',
                        'source_risk_domain',
                        'source_rule_ids',
                        'confidence_score',
                        'requires_human_review',
                        'can_auto_create',
                    ],
                ],
            ]);
    }

    private function seedCriticalRiskScenario(): void
    {
        $this->seedVendorRiskTransactions(firstDate: '2026-05-10', secondDate: '2026-05-12');
        $this->seedReconciliationMismatch();
        $this->seedEmployeeVendorOverlap();
    }

    private function seedVendorRiskTransactions(string $firstDate, string $secondDate): void
    {
        $tx1 = new Transaction;
        $tx1->id = '88888888-3001-4999-9999-999999999999';
        $tx1->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx1->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx1->vendor_customer = 'Northstar Consulting';
        $tx1->amount = 4500.00;
        $tx1->date = $firstDate;
        $tx1->save();

        $tx2 = new Transaction;
        $tx2->id = '88888888-3002-4999-9999-999999999999';
        $tx2->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx2->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx2->vendor_customer = 'Northstar Consulting';
        $tx2->amount = 4800.00;
        $tx2->date = $secondDate;
        $tx2->save();
    }

    private function seedReconciliationMismatch(): void
    {
        $discrepancy = new ReconciliationDiscrepancy;
        $discrepancy->id = '88888888-4001-4999-9999-999999999999';
        $discrepancy->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $discrepancy->run_id = '88888888-4002-4999-9999-999999999999';
        $discrepancy->amount = 1200.00;
        $discrepancy->category = 'missing_from_books';
        $discrepancy->reason_code = 'bank_transaction_without_ledger_match';
        $discrepancy->risk_level = 'high';
        $discrepancy->recommended_action = 'review_bank_side_item';
        $discrepancy->recommendation_explanation = 'Bank-side transaction has no matching ledger entry.';
        $discrepancy->status = 'new';
        $discrepancy->metadata = [];
        $discrepancy->save();
    }

    private function seedEmployeeVendorOverlap(): void
    {
        $tx = new Transaction;
        $tx->id = '88888888-5001-4999-9999-999999999999';
        $tx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx->vendor_customer = 'Test User Supplies';
        $tx->amount = 500.00;
        $tx->date = '2026-05-13';
        $tx->save();
    }

    private function seedBaseData(): void
    {
        $company = new Company;
        $company->id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $company->name = 'Acme Corp';
        $company->save();

        $user = new User;
        $user->id = Phase1AgentFraudScenarioSeeder::USER_ID;
        $user->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $user->email = 'admin@acme.com';
        $user->password_hash = 'secret';
        $user->first_name = 'Test';
        $user->last_name = 'User';
        $user->save();

        $upload = new Upload;
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
            'alert_recommendations',
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

        Schema::create('alert_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->text('source_risk_domain');
            $table->text('alert_type');
            $table->text('severity');
            $table->text('title');
            $table->text('summary');
            $table->json('evidence');
            $table->json('source_rule_ids');
            $table->decimal('confidence_score', 5, 4)->default(0);
            $table->text('status')->default('pending_review');
            $table->foreignUuid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
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
