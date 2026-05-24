<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RexChatControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.llm.provider' => 'openai',
            'services.llm.api_key' => 'test-openai-key',
            'services.llm.base_url' => 'https://api.openai.com/v1',
            'services.llm.model' => 'chat-test-model',
            'services.llm.router_model' => 'router-test-model',
            'services.llm.timeout' => 10,
            'services.brevix_agent.base_url' => 'http://agent.test',
            'services.brevix_agent.api_key' => 'test-agent-key',
            'services.brevix_agent.timeout' => 10,
        ]);

        Schema::dropIfExists('agent_action_approvals');
        Schema::dropIfExists('agent_steps');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('rex_pending_actions');
        Schema::dropIfExists('chat_usage_daily');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role')->default('owner');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary();
            $table->string('tier');
            $table->string('status');
        });

        Schema::create('chat_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('user_id');
            $table->string('title')->default('New Chat with Rex');
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id');
            $table->foreignUuid('company_id');
            $table->string('role');
            $table->text('content');
            $table->json('structured_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('chat_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id');
            $table->date('date');
            $table->integer('message_count')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'date']);
        });

        Schema::create('rex_pending_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id');
            $table->foreignUuid('company_id');
            $table->string('action_type');
            $table->json('preview')->default('{}');
            $table->string('status')->default('pending');
            $table->foreignUuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
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

    public function test_rex_session_chat_uses_deterministic_router_to_start_agent_run(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id,
            'tier' => 'growth',
            'status' => 'active',
        ]);

        $session = ChatSession::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'title' => 'Risk review',
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'mode' => 'agent',
                            'route' => null,
                            'reason' => 'fraud_risk_review',
                        ]),
                    ],
                ]],
            ]),
            'http://agent.test/agent/run' => Http::response([
                'intent' => 'fraud_pattern_search',
                'message' => 'One pattern may be worth reviewing.',
                'findings' => [[
                    'title' => 'Possible unusual vendor activity',
                    'severity' => 'medium',
                    'confidence' => 0.72,
                    'summary' => 'Vendor spend changed materially.',
                    'evidence' => [],
                ]],
                'recommended_actions' => [[
                    'type' => 'create_alert',
                    'label' => 'Create alert',
                    'requires_approval' => false,
                    'payload' => ['title' => 'Draft alert'],
                ]],
                'steps' => [[
                    'step_name' => 'risk_analysis',
                    'step_type' => 'tool_call',
                    'status' => 'completed',
                    'output_payload' => ['tool' => 'aggregate_risk_summary'],
                ]],
                'model_provider' => 'openai',
                'model_name' => 'chat-test-model',
                'usage' => [
                    'tokens_input' => 100,
                    'tokens_output' => 50,
                    'cost_estimate' => 0.001,
                ],
            ]),
        ]);

        $response = $this->postJson("/api/chat/sessions/{$session->id}/messages", [
            'content' => 'Are there any suspicious vendors this month?',
        ]);

        $response->assertOk();
        $stream = $response->streamedContent();

        $this->assertStringContainsString('"type":"route.selected"', $stream);
        $this->assertStringContainsString('"route":"agent.risk_review"', $stream);
        $this->assertStringContainsString('"type":"artifact.upsert"', $stream);
        $this->assertStringContainsString('One pattern may be worth reviewing.', $stream);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/chat/completions');

        Http::assertSent(fn ($request): bool => $request->url() === 'http://agent.test/agent/run'
            && $request->hasHeader('Authorization', 'Bearer test-agent-key')
            && $request['company_id'] === $company->id
            && $request['user_id'] === $user->id
            && $request['conversation_id'] === $session->id
            && $request['requested_action'] === 'risk_review');

        $this->assertDatabaseHas('agent_runs', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'conversation_id' => $session->id,
            'status' => 'completed',
            'intent' => 'fraud_pattern_search',
        ]);
        $this->assertDatabaseHas('agent_action_approvals', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'action_type' => 'create_alert',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'session_id' => $session->id,
            'company_id' => $company->id,
            'role' => 'assistant',
            'content' => 'One pattern may be worth reviewing.',
        ]);
    }

    /** @return array{0: Company, 1: User} */
    private function createCompanyUser(): array
    {
        $company = new Company(['name' => 'Company A']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }
}
