<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ReconciliationDiscrepancy;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertRunEngineTest extends TestCase
{
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
    }

    public function test_run_requires_authentication(): void
    {
        $this->postJson('/api/alerts/run')->assertUnauthorized();
    }

    public function test_run_returns_zero_recommendations_for_clean_company(): void
    {
        $user = User::find(Phase1AgentFraudScenarioSeeder::USER_ID);
        Sanctum::actingAs($user);

        $this->postJson('/api/alerts/run')
            ->assertOk()
            ->assertJsonPath('recommendations_generated', 0)
            ->assertJsonPath('pending_review', 0)
            ->assertJsonStructure(['recommendations_generated', 'pending_review', 'recommendations']);
    }

    public function test_run_returns_recommendations_for_high_risk_company(): void
    {
        $this->seedVendorRiskTransactions();

        $user = User::find(Phase1AgentFraudScenarioSeeder::USER_ID);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/alerts/run')->assertOk();

        $this->assertGreaterThan(0, $response->json('recommendations_generated'));
        $this->assertIsArray($response->json('recommendations'));

        $recommendation = $response->json('recommendations.0');
        $this->assertArrayHasKey('alert_type', $recommendation);
        $this->assertArrayHasKey('severity', $recommendation);
        $this->assertArrayHasKey('status', $recommendation);
    }

    public function test_run_pending_review_count_matches_pending_status(): void
    {
        $this->seedVendorRiskTransactions();

        $user = User::find(Phase1AgentFraudScenarioSeeder::USER_ID);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/alerts/run')->assertOk();

        $pendingCount = collect($response->json('recommendations'))
            ->where('status', 'pending_review')
            ->count();

        $this->assertSame($pendingCount, $response->json('pending_review'));
    }

    private function seedVendorRiskTransactions(): void
    {
        $tx1 = new Transaction;
        $tx1->id = '88888888-3001-4999-9999-999999999999';
        $tx1->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx1->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx1->vendor_customer = 'Northstar Consulting';
        $tx1->amount = 4500.00;
        $tx1->date = '2026-05-11';
        $tx1->save();

        $tx2 = new Transaction;
        $tx2->id = '88888888-3002-4999-9999-999999999999';
        $tx2->upload_id = Phase1AgentFraudScenarioSeeder::UPLOAD_ID;
        $tx2->company_id = Phase1AgentFraudScenarioSeeder::COMPANY_ID;
        $tx2->vendor_customer = 'Northstar Consulting';
        $tx2->amount = 4800.00;
        $tx2->date = '2026-05-12';
        $tx2->save();
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
            'personal_access_tokens',
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

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
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

        Schema::create('reconciliation_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->timestamps();
        });

        Schema::create('agent_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id');
            $table->timestamps();
        });

        Schema::create('agent_action_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id');
            $table->timestamps();
        });
    }
}
