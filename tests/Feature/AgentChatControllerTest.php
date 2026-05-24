<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentChatControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevix_agent.base_url' => 'http://agent.test',
            'services.brevix_agent.api_key' => 'test-agent-key',
            'services.brevix_agent.timeout' => 10,
        ]);

        Schema::dropIfExists('agent_action_approvals');
        Schema::dropIfExists('agent_steps');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

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

    public function test_agent_chat_rejects_unauthenticated_request(): void
    {
        [$company] = $this->createCompanyUser('Company A');
        Http::fake();

        $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Check fraud risk.',
        ])->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_agent_chat_rejects_invalid_payload_before_calling_langgraph(): void
    {
        [, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);
        Http::fake();

        $this->postJson('/api/chat/agent/messages', [
            'company_id' => 'not-a-uuid',
            'message' => '',
            'requested_action' => 'create_alert',
            'date_range' => [
                'start_date' => '2026-05-31',
                'end_date' => '2026-05-01',
            ],
            'max_response_size' => 50000,
            'page_context' => ['selected_period' => '2026-99'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_id',
                'message',
                'requested_action',
                'date_range.start_date',
                'max_response_size',
                'page_context.selected_period',
            ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_agent_chat_rejects_cross_company_request_before_calling_langgraph(): void
    {
        [, $user] = $this->createCompanyUser('Company A');
        [$otherCompany] = $this->createCompanyUser('Company B');
        Sanctum::actingAs($user);
        Http::fake();

        $this->postJson('/api/chat/agent/messages', [
            'company_id' => $otherCompany->id,
            'message' => 'Check fraud risk.',
        ])->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_agent_chat_persists_run_steps_and_pending_action_without_execution(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'One pattern may be worth reviewing.',
                'findings' => [
                    [
                        'title' => 'Possible unusual activity',
                        'severity' => 'medium',
                        'confidence' => 0.7,
                        'summary' => 'Open alerts are driving the risk score.',
                        'evidence' => [],
                    ],
                ],
                'recommended_actions' => [
                    [
                        'type' => 'create_alert',
                        'label' => 'Create alert',
                        'requires_approval' => false,
                        'payload' => ['title' => 'Draft alert'],
                    ],
                ],
                'steps' => [
                    [
                        'step_name' => 'fraud_analyzer',
                        'step_type' => 'graph_node',
                        'status' => 'completed',
                        'output_payload' => ['tool' => 'risk-summary'],
                    ],
                ],
                'errors' => [],
            ]),
        ]);

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Are there any suspicious vendors this month?',
            'page_context' => ['selected_period' => '2026-05'],
        ]);

        $response->assertOk()
            ->assertJsonPath('intent', 'fraud_pattern_search')
            ->assertJsonPath('recommended_actions.0.requires_approval', true);

        $this->assertSame([
            'message',
            'intent',
            'findings',
            'recommended_actions',
            'can_create_alert',
            'requires_review',
            'trace_id',
            'investigative_synthesis',
        ], array_keys($response->json()));
        $this->assertTrue($response->json('can_create_alert'));
        $this->assertTrue($response->json('requires_review'));
        $this->assertNotEmpty($response->json('trace_id'));

        Http::assertSent(fn ($request): bool => $request->url() === 'http://agent.test/agent/run'
            && $request->hasHeader('Authorization', 'Bearer test-agent-key')
            && $request['company_id'] === $company->id
            && $request['user_id'] === $user->id);

        $this->assertDatabaseHas('agent_runs', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'intent' => 'fraud_pattern_search',
        ]);
        $this->assertDatabaseHas('agent_steps', ['step_name' => 'fraud_analyzer']);
        $this->assertDatabaseHas('agent_action_approvals', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'action_type' => 'create_alert',
            'status' => 'pending',
        ]);
    }

    public function test_agent_chat_advertises_optional_deterministic_tools(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'Risk review complete.',
                'findings' => [],
                'recommended_actions' => [],
                'steps' => [],
            ]),
        ]);

        $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Review aggregate risk.',
        ])->assertOk();

        Http::assertSent(function ($request) use ($company): bool {
            $tools = $request['optional_deterministic_tools'] ?? null;
            $policy = $request['tool_policy'] ?? null;

            if (! is_array($tools) || ! is_array($policy)) {
                return false;
            }

            $aggregateTool = $tools['aggregate_risk_summary'] ?? null;
            $alertTool = $tools['alert_recommendations'] ?? null;
            $contextTool = $tools['company_context'] ?? null;
            $riskTool = $tools['risk_summary'] ?? null;
            $vendorTool = $tools['vendor_risk'] ?? null;
            $caseTool = $tools['case_recommendations'] ?? null;

            if (
                ! is_array($aggregateTool)
                || ! is_array($alertTool)
                || ! is_array($contextTool)
                || ! is_array($riskTool)
                || ! is_array($vendorTool)
                || ! is_array($caseTool)
            ) {
                return false;
            }

            return count($tools) === 8
                && $contextTool['method'] === 'GET'
                && $contextTool['path'] === "/api/internal/agent-tools/companies/{$company->id}/context"
                && $contextTool['optional'] === false
                && $contextTool['deterministic'] === true
                && $riskTool['method'] === 'GET'
                && $riskTool['path'] === "/api/internal/agent-tools/companies/{$company->id}/risk-summary"
                && $riskTool['optional'] === false
                && $riskTool['deterministic'] === true
                && $vendorTool['path'] === "/api/internal/agent-tools/company/{$company->id}/vendor-risk"
                && $caseTool['path'] === "/api/internal/agent-tools/company/{$company->id}/case-recommendations"
                && $aggregateTool['method'] === 'GET'
                && $aggregateTool['path'] === "/api/internal/agent-tools/company/{$company->id}/aggregate-risk-summary"
                && $aggregateTool['optional'] === true
                && $aggregateTool['deterministic'] === true
                && $aggregateTool['score_authority'] === 'laravel'
                && $alertTool['method'] === 'GET'
                && $alertTool['path'] === "/api/internal/agent-tools/company/{$company->id}/alert-recommendations"
                && $alertTool['optional'] === true
                && $alertTool['deterministic'] === true
                && $alertTool['recommendation_authority'] === 'laravel'
                && ! array_key_exists('database_url', $aggregateTool)
                && ! array_key_exists('database_url', $alertTool)
                && $policy['database_access'] === 'forbidden'
                && $policy['autonomous_actions'] === 'forbidden'
                && $policy['score_recalculation'] === 'forbidden'
                && $policy['tool_surface'] === 'api/internal/agent-tools'
                && $policy['mutating_tools'] === 'forbidden';
        });
    }

    public function test_agent_chat_returns_safe_contract_when_agent_service_is_unavailable(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        Http::fake([
            'http://agent.test/agent/run' => Http::response(['error' => 'upstream down'], 503),
        ]);

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Are there any suspicious vendors this month?',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'I could not complete the risk review right now. No alerts or cases were created. Please try again or review the dashboard manually.')
            ->assertJsonPath('intent', 'agent_service_unavailable')
            ->assertJsonPath('findings', [])
            ->assertJsonPath('recommended_actions', [])
            ->assertJsonPath('can_create_alert', false)
            ->assertJsonPath('requires_review', false);

        $this->assertSame([
            'message',
            'intent',
            'findings',
            'recommended_actions',
            'can_create_alert',
            'requires_review',
            'trace_id',
            'investigative_synthesis',
        ], array_keys($response->json()));
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());

        $this->assertDatabaseHas('agent_runs', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => 'failed',
        ]);
    }

    public function test_agent_chat_passes_investigative_synthesis_when_agent_service_returns_it(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        $synthesis = [
            'investigative_summary' => 'Multiple correlated signals detected across vendor and reconciliation domains.',
            'correlated_findings' => [
                [
                    'pattern' => 'vendor_entity_overlap',
                    'title' => 'Vendor and entity overlap',
                    'summary' => 'Shared contact details found.',
                    'domains' => ['vendor_risk', 'entity_relationship_risk'],
                    'signals' => [],
                ],
            ],
            'reinforcing_signals' => [],
            'conflicting_signals' => [],
            'investigation_priority' => 'high',
            'recommended_investigation_focus' => ['Review vendor onboarding records'],
            'supporting_domains' => ['vendor_risk', 'reconciliation_risk'],
            'evidence_summary' => [],
        ];

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'Patterns worth reviewing were found.',
                'findings' => [],
                'recommended_actions' => [],
                'investigative_synthesis' => $synthesis,
                'steps' => [],
                'errors' => [],
            ]),
        ]);

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Are there any correlated fraud signals this month?',
        ]);

        $response->assertOk()
            ->assertJsonPath('investigative_synthesis.investigation_priority', 'high')
            ->assertJsonPath('investigative_synthesis.investigative_summary', $synthesis['investigative_summary'])
            ->assertJsonPath('investigative_synthesis.correlated_findings.0.pattern', 'vendor_entity_overlap');
    }

    public function test_agent_chat_returns_null_investigative_synthesis_when_agent_omits_it(): void
    {
        [$company, $user] = $this->createCompanyUser('Company A');
        Sanctum::actingAs($user);

        Http::fake([
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'No patterns found.',
                'findings' => [],
                'recommended_actions' => [],
                'steps' => [],
                'errors' => [],
            ]),
        ]);

        $response = $this->postJson('/api/chat/agent/messages', [
            'company_id' => $company->id,
            'message' => 'Review risk.',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('investigative_synthesis'));
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(string $companyName): array
    {
        $company = new Company(['name' => $companyName]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
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
