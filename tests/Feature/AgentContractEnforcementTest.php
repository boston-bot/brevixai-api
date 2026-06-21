<?php

namespace Tests\Feature;

use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\BusinessProfile;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Agents\BrevixAgentClient;
use App\Services\Agents\BrevixAgentRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentContractEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_tenant_and_profile_propagation_contract(): void
    {
        [$company, $user, $profile] = $this->createWorkspace();
        
        $payload = [
            'message' => 'Analyze vendor risk',
            'company_id' => $company->id,
            'business_profile_id' => $profile->id,
            'user_id' => $user->id,
            'requested_action' => 'risk_review',
        ];

        $clientMock = $this->createMock(BrevixAgentClient::class);
        $clientMock->expects($this->once())
            ->method('run')
            ->with($this->callback(function (array $request) use ($company, $profile) {
                return $request['company_id'] === $company->id
                    && $request['business_profile_id'] === $profile->id
                    && isset($request['tool_policy'])
                    && $request['tool_policy']['database_access'] === 'forbidden';
            }))
            ->willReturn([
                'message' => 'Done',
                'intent' => 'risk_review',
                'findings' => [],
                'steps' => [],
            ]);

        $this->app->instance(BrevixAgentClient::class, $clientMock);

        $runner = app(BrevixAgentRunner::class);
        $result = $runner->run($payload);

        $this->assertSame('Done', $result['message']);
        
        $this->assertDatabaseHas('agent_runs', [
            'company_id' => $company->id,
            'business_profile_id' => $profile->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_partial_tool_degradation_contract(): void
    {
        [$company, $user, $profile] = $this->createWorkspace();
        
        $payload = [
            'message' => 'Check vendor risk',
            'company_id' => $company->id,
            'business_profile_id' => $profile->id,
            'user_id' => $user->id,
            'requested_action' => 'risk_review',
        ];

        $clientMock = $this->createMock(BrevixAgentClient::class);
        $clientMock->method('run')->willReturn([
            'message' => 'Completed with degraded tool',
            'intent' => 'risk_review',
            'findings' => [],
            'degraded_tools' => [
                [
                    'tool' => 'vendor_risk',
                    'error_class' => 'Timeout',
                    'message' => 'Vendor API timed out',
                    'affected_confidence' => true,
                ]
            ],
            'steps' => [
                [
                    'step_name' => 'fetch_vendor_risk',
                    'step_type' => 'tool_call',
                    'status' => 'failed',
                    'input_payload' => ['tool' => 'vendor_risk'],
                    'output_payload' => ['error' => 'Timeout'],
                ]
            ],
        ]);

        $this->app->instance(BrevixAgentClient::class, $clientMock);

        $runner = app(BrevixAgentRunner::class);
        $result = $runner->run($payload);

        $this->assertCount(1, $result['degraded_tools']);
        $this->assertSame('vendor_risk', $result['degraded_tools'][0]['tool']);
        $this->assertSame('Timeout', $result['degraded_tools'][0]['error_class']);
        
        // Assert it does not fail the entire run.
        $this->assertDatabaseHas('agent_runs', [
            'status' => 'completed',
        ]);
    }

    public function test_rejected_and_approved_action_contract(): void
    {
        [$company, $user, $profile] = $this->createWorkspace();
        
        $payload = [
            'message' => 'Flag this',
            'company_id' => $company->id,
            'business_profile_id' => $profile->id,
            'user_id' => $user->id,
            'requested_action' => 'risk_review',
        ];

        $transactionId = (string) Str::uuid();
        DB::table('transactions')->insert([
            'id' => $transactionId,
            'company_id' => $company->id,
            'business_profile_id' => $profile->id,
            'amount' => 100,
        ]);

        $clientMock = $this->createMock(BrevixAgentClient::class);
        $clientMock->method('run')->willReturn([
            'message' => 'Action recommended',
            'intent' => 'risk_review',
            'findings' => [],
            'recommended_actions' => [
                [
                    'type' => 'flag_transaction',
                    'requires_approval' => true,
                    'payload' => ['transaction_id' => $transactionId],
                ]
            ],
            'steps' => [],
        ]);

        $this->app->instance(BrevixAgentClient::class, $clientMock);

        $runner = app(BrevixAgentRunner::class);
        $result = $runner->run($payload);

        $this->assertCount(1, $result['recommended_actions']);
        $approvalId = $result['recommended_actions'][0]['approval_id'];

        $this->assertDatabaseHas('agent_action_approvals', [
            'id' => $approvalId,
            'status' => 'pending',
            'action_type' => 'flag_transaction',
        ]);

        Sanctum::actingAs($user);

        // Test Rejection
        $this->postJson("/api/agent-approvals/{$approvalId}/reject", [], ['X-Brevix-Business-Profile-Id' => $profile->id])
            ->assertOk();

        $this->assertDatabaseHas('agent_action_approvals', [
            'id' => $approvalId,
            'status' => 'rejected',
        ]);

        // Reset to pending for approval test
        AgentActionApproval::where('id', $approvalId)->update(['status' => 'pending']);

        // Test Approval
        $this->postJson("/api/agent-approvals/{$approvalId}/approve", [], ['X-Brevix-Business-Profile-Id' => $profile->id])
            ->assertOk();

        $this->assertDatabaseHas('agent_action_approvals', [
            'id' => $approvalId,
            'status' => 'approved',
            'approved_by' => $user->id,
        ]);
        
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'anomaly_flag' => 1,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'transaction_reviews',
            'transactions',
            'agent_action_approvals',
            'agent_steps',
            'agent_runs',
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
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('owner');
            $table->timestamps();
        });

        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('workspace_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('role');
            $table->string('scope')->default('workspace');
            $table->uuid('granted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('user_id');
            $table->uuid('conversation_id')->nullable();
            $table->string('status')->default('running');
            $table->text('input_message')->nullable();
            $table->text('final_response')->nullable();
            $table->string('intent')->nullable();
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->integer('tokens_input')->nullable();
            $table->integer('tokens_output')->nullable();
            $table->decimal('cost_estimate', 10, 4)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_run_id');
            $table->string('step_name');
            $table->string('step_type');
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_action_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_run_id');
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('user_id');
            $table->string('action_type');
            $table->json('action_payload');
            $table->string('status')->default('pending');
            $table->uuid('approved_by')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->boolean('anomaly_flag')->default(false);
            $table->string('anomaly_reason')->nullable();
        });

        Schema::create('transaction_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('company_id');
            $table->uuid('transaction_id');
            $table->uuid('marked_by');
            $table->timestamps();
            
            $table->unique(['company_id', 'transaction_id']);
        });
    }

    /** @return array{0: Company, 1: User, 2: BusinessProfile} */
    private function createWorkspace(): array
    {
        $company = new Company(['name' => 'Contract Test Co']);
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

        WorkspaceMembership::create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'scope' => 'workspace',
            'granted_by' => $user->id,
        ]);

        $profile = new BusinessProfile([
            'company_id' => $company->id,
            'name' => 'Default',
            'is_default' => true,
            'status' => 'active',
        ]);
        $profile->id = (string) Str::uuid();
        $profile->save();

        return [$company, $user, $profile];
    }
}
