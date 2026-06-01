<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ReconciliationDiscrepancy;
use App\Models\ReconciliationResult;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\ReconciliationRiskScoringService;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReconciliationRiskScoringTest extends TestCase
{
    private ReconciliationRiskScoringService $scoringService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevix_agent.base_url' => 'http://agent.test',
            'services.brevix_agent.api_key' => 'test-agent-key',
            'services.brevix_agent.timeout' => 10,
        ]);

        $this->scoringService = app(ReconciliationRiskScoringService::class);

        // Prepare schema and seed standard shell data
        $this->createSchema();
        $this->seedBaseData();
    }

    /**
     * Test clean reconciliation scenario yields zero risk score.
     */
    public function test_clean_reconciliation(): void
    {
        $result = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(0, $result['reconciliation_risk_score']);
        $this->assertSame('low', $result['risk_level']);
        $this->assertEmpty($result['triggered_rules']);
        $this->assertEmpty($result['supporting_evidence']);
        $this->assertStringContainsString('clean', $result['recommended_next_action']);
    }

    /**
     * Test stale unreconciled items trigger correct rules and weights.
     */
    public function test_stale_unreconciled_items(): void
    {
        // Insert a discrepancy marked as stale_unreconciled
        $d = new ReconciliationDiscrepancy;
        $d->id = '88888888-1111-4888-8888-888888888881';
        $d->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $d->run_id = Phase1AgentFraudScenarioSeeder::RECON_RUN_ID;
        $d->amount = 450.00;
        $d->category = 'stale_unreconciled';
        $d->reason_code = 'stale_unreconciled_item';
        $d->status = 'new';
        $d->risk_level = 'medium';
        $d->recommended_action = 'review';
        $d->recommendation_explanation = 'details';
        $d->metadata = [];
        $d->save();

        $result = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(15, $result['reconciliation_risk_score']);
        $this->assertContains('stale_unreconciled', array_column($result['triggered_rules'], 'rule_key'));
        $this->assertNotEmpty($result['supporting_evidence']['stale_unreconciled']);
    }

    /**
     * Test duplicate ledger entries trigger correct rules and weights.
     */
    public function test_duplicate_ledger_entries(): void
    {
        // Insert duplicate ledger entry discrepancy
        $d = new ReconciliationDiscrepancy;
        $d->id = '88888888-2222-4888-8888-888888888882';
        $d->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $d->run_id = Phase1AgentFraudScenarioSeeder::RECON_RUN_ID;
        $d->amount = 1200.00;
        $d->category = 'duplicate_ledger';
        $d->reason_code = 'duplicate_ledger_entry';
        $d->status = 'new';
        $d->risk_level = 'medium';
        $d->recommended_action = 'review';
        $d->recommendation_explanation = 'details';
        $d->metadata = [];
        $d->save();

        $result = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(15, $result['reconciliation_risk_score']);
        $this->assertContains('duplicate_ledger', array_column($result['triggered_rules'], 'rule_key'));
        $this->assertNotEmpty($result['supporting_evidence']['duplicate_ledger']);
    }

    /**
     * Test unmatched deposit mismatch behavior.
     */
    public function test_deposit_mismatch(): void
    {
        // Insert unmatched deposit bank transaction
        $bankTx = new Transaction;
        $bankTx->id = '88888888-0001-4888-8888-888888888880';
        $bankTx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $bankTx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $bankTx->txn_id = 'BANK_DEP_1';
        $bankTx->date = '2026-05-10';
        $bankTx->vendor_customer = 'Paying Client';
        $bankTx->type = 'deposit';
        $bankTx->amount = 5000.00; // Positive deposit
        $bankTx->save();

        // Create missing_from_books discrepancy linked to the bank deposit
        $d = new ReconciliationDiscrepancy;
        $d->id = '88888888-3333-4888-8888-888888888883';
        $d->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $d->run_id = Phase1AgentFraudScenarioSeeder::RECON_RUN_ID;
        $d->bank_txn_id = $bankTx->id;
        $d->amount = 5000.00;
        $d->category = 'missing_from_books';
        $d->reason_code = 'bank_transaction_without_ledger_match';
        $d->status = 'new';
        $d->risk_level = 'high';
        $d->recommended_action = 'review';
        $d->recommendation_explanation = 'details';
        $d->metadata = [];
        $d->save();

        $result = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        // Triggers bank_ledger_mismatch (15) + unmatched_deposits (20) = 35
        $this->assertSame(35, $result['reconciliation_risk_score']);
        $triggeredKeys = array_column($result['triggered_rules'], 'rule_key');
        $this->assertContains('bank_ledger_mismatch', $triggeredKeys);
        $this->assertContains('unmatched_deposits', $triggeredKeys);
        $this->assertNotContains('unmatched_withdrawals', $triggeredKeys);
    }

    /**
     * Test unmatched withdrawal mismatch behavior.
     */
    public function test_withdrawal_mismatch(): void
    {
        // Insert unmatched withdrawal bank transaction
        $bankTx = new Transaction;
        $bankTx->id = '88888888-0002-4888-8888-888888888880';
        $bankTx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $bankTx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $bankTx->txn_id = 'BANK_WDR_1';
        $bankTx->date = '2026-05-12';
        $bankTx->vendor_customer = 'Unknown Outflow';
        $bankTx->type = 'expense';
        $bankTx->amount = -2500.00; // Negative expense
        $bankTx->save();

        // Create missing_from_books discrepancy linked to the bank withdrawal
        $d = new ReconciliationDiscrepancy;
        $d->id = '88888888-4444-4888-8888-888888888884';
        $d->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $d->run_id = Phase1AgentFraudScenarioSeeder::RECON_RUN_ID;
        $d->bank_txn_id = $bankTx->id;
        $d->amount = -2500.00;
        $d->category = 'missing_from_books';
        $d->reason_code = 'bank_transaction_without_ledger_match';
        $d->status = 'new';
        $d->risk_level = 'high';
        $d->recommended_action = 'review';
        $d->recommendation_explanation = 'details';
        $d->metadata = [];
        $d->save();

        $result = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        // Triggers bank_ledger_mismatch (15) + unmatched_withdrawals (20) = 35
        $this->assertSame(35, $result['reconciliation_risk_score']);
        $triggeredKeys = array_column($result['triggered_rules'], 'rule_key');
        $this->assertContains('bank_ledger_mismatch', $triggeredKeys);
        $this->assertContains('unmatched_withdrawals', $triggeredKeys);
        $this->assertNotContains('unmatched_deposits', $triggeredKeys);
    }

    /**
     * Test suspicious manual adjustments detection.
     */
    public function test_suspicious_manual_adjustment(): void
    {
        // Insert a transaction with suspicious manual adjustment keyword in memo
        $adjTx = new Transaction;
        $adjTx->id = '88888888-0003-4888-8888-888888888880';
        $adjTx->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $adjTx->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $adjTx->txn_id = 'ADJ_TX_1';
        $adjTx->date = '2026-05-14';
        $adjTx->vendor_customer = 'Internal Cash';
        $adjTx->type = 'journal_entry';
        $adjTx->amount = -1500.00;
        $adjTx->memo = 'forced reconciliation write-off'; // Suspicious manual adjustment
        $adjTx->save();

        $result = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertSame(15, $result['reconciliation_risk_score']);
        $this->assertContains('suspicious_manual_adjustment', array_column($result['triggered_rules'], 'rule_key'));
        $this->assertNotEmpty($result['supporting_evidence']['suspicious_manual_adjustment']);
    }

    /**
     * Test API endpoint authentication and response structure consistency.
     */
    public function test_reconciliation_risk_api_endpoint_structure_and_auth(): void
    {
        // 1. Assert auth protection (requires token)
        $unauth = $this->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/reconciliation-risk');
        $unauth->assertStatus(401);

        // 2. Access with valid token and fetch reconciliation risk
        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/company/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/reconciliation-risk');

        $response->assertOk();
        $response->assertJsonStructure([
            'company_id',
            'reconciliation_risk_score',
            'risk_level',
            'triggered_rules',
            'rule_weights',
            'supporting_evidence',
            'recommended_next_action',
        ]);
    }

    /**
     * Test stable scoring consistency (multiple requests return identical scoring data).
     */
    public function test_stable_scoring_consistency(): void
    {
        // Insert a mismatch
        $d = new ReconciliationDiscrepancy;
        $d->id = '88888888-5555-4888-8888-888888888885';
        $d->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $d->run_id = Phase1AgentFraudScenarioSeeder::RECON_RUN_ID;
        $d->amount = 300.00;
        $d->category = 'duplicate_ledger';
        $d->reason_code = 'duplicate_ledger_entry';
        $d->status = 'new';
        $d->risk_level = 'medium';
        $d->recommended_action = 'review';
        $d->recommendation_explanation = 'details';
        $d->metadata = [];
        $d->save();

        $run1 = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);
        $run2 = $this->scoringService->scoreReconciliation(Phase1AgentFraudScenarioSeeder::COMPANY_ID);

        $this->assertEquals($run1, $run2);
    }

    private function seedBaseData(): void
    {
        // Seed Company
        $company = new Company;
        $company->id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $company->name = 'Acme Corp';
        $company->save();

        // Seed User
        $user = new User;
        $user->id = Phase1AgentFraudScenarioSeeder::USER_ID;
        $user->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $user->email = 'admin@acme.com';
        $user->password_hash = 'secret';
        $user->save();

        // Seed Upload
        $upload = new Upload;
        $upload->id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $upload->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $upload->uploaded_by = Phase1AgentFraudScenarioSeeder::USER_ID;
        $upload->filename = 'ledger.xlsx';
        $upload->status = 'completed';
        $upload->save();

        // Seed Reconciliation Run
        $recon = new ReconciliationResult;
        $recon->id = Phase1AgentFraudScenarioSeeder::RECON_RUN_ID;
        $recon->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $recon->period_start = '2026-05-01';
        $recon->period_end = '2026-05-31';
        $recon->total_mismatches = 0;
        $recon->total_impact = 0;
        $recon->status = 'completed';
        $recon->results = [];
        $recon->save();
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
    }
}
