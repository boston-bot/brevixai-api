<?php

namespace Tests\Feature;

use App\Models\AgentActionApproval;
use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Services\Agents\AgentRiskAnalysisService;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase1AgentFraudScenarioTest extends TestCase
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
        $this->seed(Phase1AgentFraudScenarioSeeder::class);
    }

    public function test_internal_agent_risk_tool_exposes_seeded_fraud_findings(): void
    {
        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', Phase1AgentFraudScenarioSeeder::USER_ID)
            ->getJson('/api/internal/agent-tools/companies/'.Phase1AgentFraudScenarioSeeder::COMPANY_ID.'/risk-summary?period=2026-05');

        $response->assertOk()
            ->assertJsonPath('period', '2026-05')
            ->assertJsonCount(6, 'top_drivers');

        $drivers = collect($response->json('top_drivers'));

        foreach ([
            'duplicate_invoice',
            'split_payment_threshold',
            'new_vendor_immediate_payment',
            'round_dollar_payments',
            'vendor_concentration',
            'reconciliation_mismatch',
        ] as $ruleKey) {
            $driver = $drivers->firstWhere('rule_key', $ruleKey);
            $this->assertNotNull($driver, "Missing seeded driver {$ruleKey}");
            $this->assertNotEmpty($driver['severity']);
            $this->assertNotEmpty($driver['evidence']);
        }
    }

    public function test_ai_analyze_endpoint_exposes_findings_and_does_not_create_alerts(): void
    {
        $company = Company::findOrFail(Phase1AgentFraudScenarioSeeder::COMPANY_ID);
        $user = User::findOrFail(Phase1AgentFraudScenarioSeeder::USER_ID);
        Sanctum::actingAs($user);

        $toolPayload = app(AgentRiskAnalysisService::class)
            ->riskSummary($company->id, '2026-05');

        $findings = collect($toolPayload['top_drivers'])->map(fn (array $driver): array => [
            'title' => $driver['driver'],
            'severity' => $driver['severity'],
            'confidence' => 0.82,
            'summary' => $driver['description'],
            'evidence' => $driver['evidence'],
        ])->values()->all();

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'Brevix found six seeded patterns worth reviewing. This does not prove fraud.',
                'findings' => $findings,
                'recommended_actions' => [
                    [
                        'type' => 'create_alert',
                        'label' => 'Create alert',
                        'requires_approval' => false,
                        'payload' => ['title' => 'Draft review alert'],
                    ],
                ],
                'steps' => [
                    [
                        'step_name' => 'router',
                        'step_type' => 'graph_node',
                        'status' => 'completed',
                        'output_payload' => ['intent' => 'fraud_pattern_search'],
                    ],
                    [
                        'step_name' => 'fraud_analyzer',
                        'step_type' => 'tool_call',
                        'status' => 'completed',
                        'output_payload' => ['tool' => 'risk_summary', 'finding_count' => 6],
                    ],
                    [
                        'step_name' => 'action_gate',
                        'step_type' => 'graph_node',
                        'status' => 'completed',
                        'output_payload' => ['executed_actions' => 0],
                    ],
                ],
                'errors' => [],
            ]),
        ]);

        $alertCountBefore = Alert::where('company_id', $company->id)->count();

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Are there any suspicious vendors this month?',
            'page_context' => ['selected_period' => '2026-05'],
        ]);

        $response->assertOk()
            ->assertJsonPath('intent', 'fraud_pattern_search')
            ->assertJsonCount(6, 'findings')
            ->assertJsonPath('recommended_actions.0.type', 'create_alert')
            ->assertJsonPath('recommended_actions.0.requires_approval', true)
            ->assertJsonPath('can_create_alert', true)
            ->assertJsonPath('requires_review', true);

        $this->assertNotEmpty($response->json('findings.0.evidence'));
        $this->assertNotEmpty($response->json('findings.0.severity'));
        $this->assertSame($alertCountBefore, Alert::where('company_id', $company->id)->count());
        $this->assertSame(1, AgentActionApproval::where('company_id', $company->id)->where('status', 'pending')->count());
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

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('user_id');
            $table->string('conversation_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('intent')->nullable();
            $table->text('input_message');
            $table->text('final_response')->nullable();
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->unsignedInteger('tokens_input')->nullable();
            $table->unsignedInteger('tokens_output')->nullable();
            $table->decimal('cost_estimate', 12, 6)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id');
            $table->string('step_name');
            $table->string('step_type');
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->string('status')->default('started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_action_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id');
            $table->foreignUuid('company_id');
            $table->foreignUuid('user_id');
            $table->string('action_type');
            $table->json('action_payload');
            $table->string('status')->default('pending');
            $table->foreignUuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }
}
