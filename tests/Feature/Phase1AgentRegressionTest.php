<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\Company;
use App\Models\User;
use App\Services\Agents\AgentRiskAnalysisService;
use Database\Seeders\FraudScenarioSeeders\Phase1AgentFraudScenarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class Phase1AgentRegressionTest extends TestCase
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
    }

    /**
     * Requirement: Authenticated user can submit a chat agent message
     */
    public function test_authenticated_user_can_submit_chat_agent_message(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'Agent responded successfully.',
                'findings' => [],
                'recommended_actions' => [],
                'steps' => [
                    [
                        'step_name' => 'router',
                        'step_type' => 'graph_node',
                        'status' => 'completed',
                    ]
                ],
                'errors' => [],
            ]),
        ]);

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Hello Agent, please check my risk.',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'intent',
                'findings',
                'recommended_actions',
                'can_create_alert',
                'requires_review',
                'trace_id',
            ])
            ->assertJsonPath('intent', 'fraud_pattern_search')
            ->assertJsonPath('message', 'Agent responded successfully.');
    }

    /**
     * Requirement: Unauthorized company access is rejected
     */
    public function test_unauthorized_company_access_is_rejected(): void
    {
        [$companyA, $userA] = $this->createCompanyUser('Company A');
        [$companyB, $userB] = $this->createCompanyUser('Company B');

        // Unauthenticated user chat message is rejected with 401
        $this->postJson('/api/chat/agent/messages', [
            'company_id' => $companyA->id,
            'message' => 'Check risk.',
        ])->assertUnauthorized();

        // Authenticated user B attempting to access Company A's chat is rejected with 403
        Sanctum::actingAs($userB);
        $this->postJson('/api/chat/agent/messages', [
            'company_id' => $companyA->id,
            'message' => 'Check risk.',
        ])->assertForbidden();

        // Unauthorized internal agent tools call (missing key) is rejected with 401
        $this->getJson("/api/internal/agent-tools/companies/{$companyA->id}/context")
            ->assertUnauthorized();

        // Unauthorized internal agent tools call (mismatched user context) is rejected with 403
        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $userB->id) // User B does not belong to Company A
            ->getJson("/api/internal/agent-tools/companies/{$companyA->id}/context")
            ->assertForbidden()
            ->assertJson(['error' => 'User is not authorized for this company']);
    }

    /**
     * Requirement: Laravel calls the agent service
     */
    public function test_laravel_calls_the_agent_service(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'risk_review',
                'message' => 'Hello from agent service',
            ]),
        ]);

        $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Check everything.',
            'page_context' => ['selected_period' => '2026-05'],
        ])->assertOk();

        Http::assertSent(function ($request) use ($company, $user): bool {
            return $request->url() === 'http://agent.test/agent/run'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-agent-key')
                && $request['company_id'] === $company->id
                && $request['user_id'] === $user->id
                && $request['message'] === 'Check everything.'
                && $request['page_context']['selected_period'] === '2026-05';
        });
    }

    /**
     * Requirement: Agent service calls only protected internal Laravel tool endpoints
     */
    public function test_agent_service_calls_only_protected_internal_laravel_tool_endpoints(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');

        // Test Endpoint 1: context protection
        $this->getJson("/api/internal/agent-tools/companies/{$company->id}/context")
            ->assertStatus(401);

        $this->withToken('wrong-agent-key')
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/context")
            ->assertStatus(401);

        $this->withToken('test-agent-key')
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/context")
            ->assertStatus(403); // Lacks X-Brevix-User-Id

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/context")
            ->assertOk();

        // Flush headers so next calls are clean
        $this->flushHeaders();

        // Test Endpoint 2: risk-summary protection
        $this->getJson("/api/internal/agent-tools/companies/{$company->id}/risk-summary?period=2026-05")
            ->assertStatus(401);

        $this->withToken('wrong-agent-key')
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/risk-summary?period=2026-05")
            ->assertStatus(401);

        $this->flushHeaders();

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/risk-summary?period=2026-05")
            ->assertOk();
    }

    /**
     * Requirement: Seeded fraud findings are returned in the stable frontend contract
     */
    public function test_seeded_fraud_findings_are_returned_in_stable_frontend_contract(): void
    {
        // Seed the Phase 1 fraud findings
        $this->seed(Phase1AgentFraudScenarioSeeder::class);

        $company = Company::findOrFail(Phase1AgentFraudScenarioSeeder::COMPANY_ID);
        $user = User::findOrFail(Phase1AgentFraudScenarioSeeder::USER_ID);

        // Fetch risk summary findings via internal agent tool
        $toolResponse = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/risk-summary?period=2026-05");

        $toolResponse->assertOk()
            ->assertJsonPath('period', '2026-05')
            ->assertJsonCount(6, 'top_drivers');

        $drivers = collect($toolResponse->json('top_drivers'));
        $expectedRules = [
            'duplicate_invoice',
            'split_payment_threshold',
            'new_vendor_immediate_payment',
            'round_dollar_payments',
            'vendor_concentration',
            'reconciliation_mismatch',
        ];
        foreach ($expectedRules as $ruleKey) {
            $driver = $drivers->firstWhere('rule_key', $ruleKey);
            $this->assertNotNull($driver, "Missing seeded driver {$ruleKey}");
            $this->assertNotEmpty($driver['severity']);
            $this->assertNotEmpty($driver['evidence']);
        }

        // Mock the agent client returning these findings to the chat endpoint
        Sanctum::actingAs($user);

        $findings = $drivers->map(fn (array $driver): array => [
            'title' => $driver['driver'],
            'severity' => $driver['severity'],
            'confidence' => 0.85,
            'summary' => $driver['description'],
            'evidence' => $driver['evidence'],
        ])->all();

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'Found 6 seeded patterns.',
                'findings' => $findings,
                'recommended_actions' => [
                    [
                        'type' => 'create_alert',
                        'label' => 'Create alert',
                        'requires_approval' => false,
                        'payload' => ['title' => 'Draft alert'],
                    ]
                ],
                'steps' => [
                    [
                        'step_name' => 'fraud_analyzer',
                        'step_type' => 'tool_call',
                        'status' => 'completed',
                        'output_payload' => ['tool' => 'risk_summary'],
                    ]
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Are there any suspicious vendors this month?',
            'page_context' => ['selected_period' => '2026-05'],
        ]);

        // Verify the response matches the exact stable frontend contract
        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'intent',
                'findings',
                'recommended_actions',
                'can_create_alert',
                'requires_review',
                'trace_id',
            ]);

        $response->assertJsonCount(6, 'findings')
            ->assertJsonPath('intent', 'fraud_pattern_search');

        $this->assertNotEmpty($response->json('findings.0.evidence'));
        $this->assertNotEmpty($response->json('findings.0.severity'));
    }

    /**
     * Requirement: ActionGate never creates alerts automatically
     */
    public function test_action_gate_never_creates_alerts_automatically(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'Review needed.',
                'findings' => [],
                'recommended_actions' => [
                    [
                        'type' => 'create_alert',
                        'label' => 'Create alert',
                        'requires_approval' => false, // ActionGate must override this
                        'payload' => ['title' => 'Critical anomaly detected'],
                    ],
                ],
                'steps' => [],
            ]),
        ]);

        $alertCountBefore = Alert::where('company_id', $company->id)->count();

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Submit request.',
        ]);

        $response->assertOk();

        // Verify the ActionGate intercepted the action, forced requires_approval/requires_review to true
        $response->assertJsonPath('recommended_actions.0.requires_approval', true)
            ->assertJsonPath('requires_review', true)
            ->assertJsonPath('can_create_alert', true);

        // Verify no alert was created in the database automatically
        $this->assertSame($alertCountBefore, Alert::where('company_id', $company->id)->count());

        // Verify a pending action approval record was created in the database
        $this->assertDatabaseHas('agent_action_approvals', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'action_type' => 'create_alert',
            'status' => 'pending',
        ]);
    }

    /**
     * Requirement: Backend failures return safe frontend errors
     */
    public function test_backend_failures_return_safe_frontend_errors(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        // 1. Agent Client Service Failure (Upstream server down)
        Http::fake([
            'http://agent.test/agent/run' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Check fraud.',
        ]);

        // Verify response is 502 with stable contract keys and a safe message
        $response->assertStatus(502)
            ->assertJsonStructure([
                'message',
                'intent',
                'findings',
                'recommended_actions',
                'can_create_alert',
                'requires_review',
                'trace_id',
            ])
            ->assertJsonPath('message', 'I could not complete the risk review right now. No alerts or cases were created. Please try again or review the dashboard manually.')
            ->assertJsonPath('intent', 'agent_service_unavailable')
            ->assertJsonPath('findings', [])
            ->assertJsonPath('recommended_actions', [])
            ->assertJsonPath('can_create_alert', false)
            ->assertJsonPath('requires_review', false);

        // Verify no stack traces or raw exception details are leaked
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());

        // 2. Internal Agent Tool Service Failure
        $this->app->instance(AgentRiskAnalysisService::class, new class extends AgentRiskAnalysisService {
            public function riskSummary(string $companyId, ?string $period = null): array
            {
                throw new RuntimeException('Database connection lost or sensitive query failed.');
            }
        });

        $toolResponse = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/risk-summary?period=2026-05");

        // Verify safe error format and no trace leak
        $toolResponse->assertStatus(500)
            ->assertJson(['error' => 'Agent tool could not complete the request safely']);

        $this->assertArrayNotHasKey('exception', $toolResponse->json());
        $this->assertArrayNotHasKey('trace', $toolResponse->json());
    }

    /**
     * Helper to create Company and User
     *
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(string $companyName): array
    {
        $company = new Company(['name' => $companyName]);
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

    /**
     * Helper to establish the testing schema in in-memory SQLite
     */
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
