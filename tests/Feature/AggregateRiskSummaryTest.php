<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Company;
use App\Models\ReconciliationDiscrepancy;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\AggregateRiskSummaryService;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AggregateRiskSummaryTest extends TestCase
{
    private AggregateRiskSummaryService $aggregateService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevix_agent.base_url' => 'http://agent.test',
            'services.brevix_agent.api_key' => 'test-agent-key',
            'services.brevix_agent.timeout' => 10,
        ]);

        Cache::flush();
        $this->aggregateService = app(AggregateRiskSummaryService::class);

        $this->createSchema();
        $this->seedBaseData();
    }

    /**
     * Test clean low-risk company yields zero aggregate risk score.
     */
    public function test_low_risk_company(): void
    {
        $result = $this->aggregateService->getAggregateRiskSummary(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(0, $result['overall_risk_score']);
        $this->assertSame('low', $result['overall_risk_level']);
        $this->assertSame(0, $result['contributing_risk_domains']['vendor_risk']['score']);
        $this->assertSame(0, $result['contributing_risk_domains']['reconciliation_risk']['score']);
        $this->assertSame(0, $result['contributing_risk_domains']['entity_relationship_risk']['score']);
        $this->assertEmpty($result['highest_risk_findings']);
    }

    /**
     * Test mixed risk scenario where vendor and entity are medium risk, and reconciliation is clean.
     */
    public function test_mixed_risk_company(): void
    {
        // Medium Vendor Risk (triggers new_vendor (15) + concentration (20) + timing (10) + rapid payment (15) = 60)
        $tx = new Transaction;
        $tx->id = '88888888-0002-4999-9999-999999999999';
        $tx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx->vendor_customer = 'New Vendor Office Supplies';
        $tx->amount = 2500.00;
        $tx->date = '2026-05-16'; // Saturday
        $tx->save();

        // Medium Entity Relationship Risk (e.g. employee/vendor overlap = 20)
        $txOverlap = new Transaction;
        $txOverlap->id = '88888888-0003-4999-9999-999999999999';
        $txOverlap->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $txOverlap->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $txOverlap->vendor_customer = 'Test User Services'; // Overlaps with employee "Test User"
        $txOverlap->amount = 500.00;
        $txOverlap->save();

        $result = $this->aggregateService->getAggregateRiskSummary(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(60, $result['overall_risk_score']); // max(60, 0, 20)
        $this->assertSame('medium', $result['overall_risk_level']);
        $this->assertSame(60, $result['contributing_risk_domains']['vendor_risk']['score']);
        $this->assertSame(0, $result['contributing_risk_domains']['reconciliation_risk']['score']);
        $this->assertSame(20, $result['contributing_risk_domains']['entity_relationship_risk']['score']);
    }

    /**
     * Test high vendor risk combined with clean reconciliation.
     */
    public function test_high_vendor_plus_clean_reconciliation(): void
    {
        // Setup high vendor risk (triggers 95 overall)
        $tx1 = new Transaction;
        $tx1->id = '88888888-0004-4999-9999-999999999999';
        $tx1->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx1->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx1->vendor_customer = 'Northstar Consulting';
        $tx1->amount = 4500.00; // splitting threshold
        $tx1->date = '2026-05-10'; // Sunday
        $tx1->save();

        $tx2 = new Transaction;
        $tx2->id = '88888888-0005-4999-9999-999999999999';
        $tx2->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx2->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx2->vendor_customer = 'Northstar Consulting';
        $tx2->amount = 4800.00; // splitting threshold
        $tx2->date = '2026-05-12'; // within 5 days
        $tx2->save();

        $result = $this->aggregateService->getAggregateRiskSummary(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(95, $result['overall_risk_score']);
        $this->assertSame('critical', $result['overall_risk_level']);
        $this->assertSame(95, $result['contributing_risk_domains']['vendor_risk']['score']);
        $this->assertSame(0, $result['contributing_risk_domains']['reconciliation_risk']['score']);
    }

    /**
     * Test high entity relationship combined with medium reconciliation.
     */
    public function test_high_entity_relationship_plus_medium_reconciliation(): void
    {
        // Setup shared bank account alert (Entity relationship = 20)
        $alert = new Alert;
        $alert->id = '88888888-0006-4999-9999-999999999999';
        $alert->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $alert->rule_key = 'shared_bank_account';
        $alert->severity = 'warning';
        $alert->title = 'Shared Banking route';
        $alert->detail = 'Vendor shares bank route.';
        $alert->evidence = ['metadata' => ['related_vendors' => ['Vendor A']]];
        $alert->status = 'open';
        $alert->save();

        // Setup reconciliation discrepancy (stale unreconciled withdrawal = 15)
        $disc = new ReconciliationDiscrepancy;
        $disc->id = '88888888-0007-4999-9999-999999999999';
        $disc->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $disc->run_id = '88888888-0008-4999-9999-999999999999';
        $disc->amount = 1500.00;
        $disc->category = 'stale_unreconciled';
        $disc->reason_code = 'stale_unreconciled_item';
        $disc->risk_level = 'medium';
        $disc->recommended_action = 'Review stale balance.';
        $disc->recommendation_explanation = 'Detailed explanation';
        $disc->status = 'new';
        $disc->metadata = '{}';
        $disc->created_at = now()->subDays(45);
        $disc->save();

        $result = $this->aggregateService->getAggregateRiskSummary(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(20, $result['overall_risk_score']); // max(0, 15, 20)
        $this->assertSame(15, $result['contributing_risk_domains']['reconciliation_risk']['score']);
        $this->assertSame(20, $result['contributing_risk_domains']['entity_relationship_risk']['score']);
    }

    /**
     * Test critical score mapping.
     */
    public function test_critical_score_mapping(): void
    {
        // Seed multiple critical indicators across domains to yield overall score >= 90
        foreach ([
            'shared_bank_account',
            'shared_address',
            'shared_phone_email',
            'vendor_vendor_payment',
        ] as $index => $key) {
            $alert = new Alert;
            $alert->id = "88888888-100{$index}-4999-9999-999999999999";
            $alert->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
            $alert->rule_key = $key;
            $alert->severity = 'critical';
            $alert->title = 'Anomaly '.$key;
            $alert->detail = 'Detailed anomaly description';
            $alert->evidence = ['metadata' => ['related_vendors' => ['Vendor A'], 'related_entities' => ['Vendor B']]];
            $alert->status = 'open';
            $alert->save();
        }

        // Add two spelling-similar vendors to trigger cluster (15) and concentration (10) to reach 90+ overall
        $tx1 = new Transaction;
        $tx1->id = '88888888-2001-4999-9999-999999999999';
        $tx1->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx1->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx1->vendor_customer = 'Eastside Consulting';
        $tx1->amount = 1000.00;
        $tx1->save();

        $tx2 = new Transaction;
        $tx2->id = '88888888-2002-4999-9999-999999999999';
        $tx2->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx2->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx2->vendor_customer = 'Eastsde Consulting';
        $tx2->amount = 1500.00;
        $tx2->save();

        // Adding more alerts and a transaction to push entity relationship risk above the 90 threshold
        $alertExtra = new Alert;
        $alertExtra->id = '88888888-1009-4999-9999-999999999999';
        $alertExtra->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $alertExtra->rule_key = 'shared_bank_account';
        $alertExtra->severity = 'critical';
        $alertExtra->title = 'Another banking routes overlap';
        $alertExtra->detail = 'Overlapping credentials';
        $alertExtra->evidence = ['metadata' => ['related_vendors' => ['Vendor A']]];
        $alertExtra->status = 'open';
        $alertExtra->save();

        // Trigger Employee Vendor Overlap (20)
        $txOverlap = new Transaction;
        $txOverlap->id = '88888888-2003-4999-9999-999999999999';
        $txOverlap->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $txOverlap->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $txOverlap->vendor_customer = 'Test User Service Provider';
        $txOverlap->amount = 500.00;
        $txOverlap->save();

        $result = $this->aggregateService->getAggregateRiskSummary(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertGreaterThanOrEqual(90, $result['overall_risk_score']);
        $this->assertSame('critical', $result['overall_risk_level']);
    }

    /**
     * Test stable scoring consistency.
     */
    public function test_stable_scoring_consistency(): void
    {
        $run1 = $this->aggregateService->getAggregateRiskSummary(Phase1AgentFraudScenarioSeeder::COMPANY_ID);
        $run2 = $this->aggregateService->getAggregateRiskSummary(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertEquals($run1, $run2);
    }

    /**
     * Test backward-compatible risk-summary response shape.
     */
    public function test_backward_compatible_risk_summary_response_shape(): void
    {
        $alert = new Alert;
        $alert->id = '88888888-0009-4999-9999-999999999999';
        $alert->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $alert->rule_key = 'duplicate_invoice';
        $alert->severity = 'critical';
        $alert->title = 'Duplicate Invoice';
        $alert->detail = 'Duplicate invoice flagged.';
        $alert->evidence = ['transactionIds' => []];
        $alert->status = 'open';
        $alert->save();

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
                'aggregate_summary' => [
                    'company_id',
                    'overall_risk_score',
                    'overall_risk_level',
                    'contributing_risk_domains',
                    'highest_risk_findings',
                    'triggered_rules_summary',
                    'recommended_next_actions',
                    'supporting_evidence_summary',
                ],
            ]);
    }

    /**
     * Test API endpoint auth protection and structured response.
     */
    public function test_api_endpoint_auth_and_structure(): void
    {
        $this->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/aggregate-risk-summary')
            ->assertStatus(401);

        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/aggregate-risk-summary');

        $response->assertOk()
            ->assertJsonStructure([
                'company_id',
                'overall_risk_score',
                'overall_risk_level',
                'contributing_risk_domains',
                'highest_risk_findings',
                'triggered_rules_summary',
                'recommended_next_actions',
                'supporting_evidence_summary',
            ]);
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
