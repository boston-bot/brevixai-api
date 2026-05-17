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
                        'requires_approval' => true,
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
}
