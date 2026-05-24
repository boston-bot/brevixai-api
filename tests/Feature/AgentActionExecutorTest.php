<?php

namespace Tests\Feature;

use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\Company;
use App\Models\User;
use App\Services\Agents\AgentActionExecutionResult;
use App\Services\Agents\AgentActionExecutorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit-style tests for AgentActionExecutorService.
 * Covers payload safety, typed results, audit evidence, and supported-type contract.
 */
class AgentActionExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('alerts');
        Schema::dropIfExists('agent_action_approvals');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('has_completed_onboarding')->default(false);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role')->default('owner');
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('conversation_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('intent')->nullable();
            $table->text('input_message');
            $table->text('final_response')->nullable();
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_action_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_run_id');
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('action_type');
            $table->json('action_payload');
            $table->string('status')->default('pending');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('group_id')->nullable();
            $table->uuid('alert_recommendation_id')->nullable();
            $table->text('rule_key');
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->text('status')->default('open');
            $table->integer('priority_score')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_supported_action_types_includes_create_alert(): void
    {
        $executor = new AgentActionExecutorService();
        $this->assertContains('create_alert', $executor->supportedActionTypes());
    }

    public function test_execute_throws_invalid_argument_for_unsupported_type(): void
    {
        [$company, $user, $approval] = $this->fixtures(actionType: 'delete_everything');

        $this->expectException(\InvalidArgumentException::class);
        (new AgentActionExecutorService())->execute($approval, $user);
    }

    public function test_create_alert_returns_typed_result_with_resource_id(): void
    {
        [$company, $user, $approval] = $this->fixtures();

        $result = (new AgentActionExecutorService())->execute($approval, $user);

        $this->assertInstanceOf(AgentActionExecutionResult::class, $result);
        $this->assertSame('alert', $result->resourceType);
        $this->assertNotEmpty($result->resourceId);
        $this->assertDatabaseHas('alerts', ['id' => $result->resourceId, 'company_id' => $company->id]);
    }

    public function test_create_alert_ignores_company_id_and_user_id_in_payload(): void
    {
        [$company, $user, $approval] = $this->fixtures(extraPayload: [
            'company_id' => 'payload-company-should-be-ignored',
            'user_id'    => 'payload-user-should-be-ignored',
        ]);

        (new AgentActionExecutorService())->execute($approval, $user);

        $this->assertDatabaseHas('alerts', ['company_id' => $company->id]);
        $this->assertDatabaseMissing('alerts', ['company_id' => 'payload-company-should-be-ignored']);
    }

    public function test_approval_record_contains_full_audit_trail_after_execution(): void
    {
        [$company, $user, $approval] = $this->fixtures();

        $result = (new AgentActionExecutorService())->execute($approval, $user);

        $fresh = AgentActionApproval::find($approval->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($user->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertNotNull($fresh->executed_at);

        // Result carries the resource identifiers so the controller can include them in the response.
        $this->assertSame('alert', $result->resourceType);
        $this->assertNotEmpty($result->resourceId);

        // The created alert ties the result back to the company.
        $this->assertDatabaseHas('alerts', [
            'id'         => $result->resourceId,
            'company_id' => $company->id,
        ]);
    }

    /** @return array{0: Company, 1: User, 2: AgentActionApproval} */
    private function fixtures(
        string $actionType = 'create_alert',
        array $extraPayload = [],
    ): array {
        $company = new Company(['name' => 'Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id'    => $company->id,
            'email'         => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'role'          => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        $run = new AgentRun([
            'company_id'    => $company->id,
            'user_id'       => $user->id,
            'status'        => 'completed',
            'input_message' => 'check fraud',
        ]);
        $run->id = (string) Str::uuid();
        $run->save();

        $payload = array_merge([
            'rule_key' => 'test_rule',
            'severity' => 'high',
            'title'    => 'Test Alert',
        ], $extraPayload);

        $approval = new AgentActionApproval([
            'agent_run_id'   => $run->id,
            'company_id'     => $company->id,
            'user_id'        => $user->id,
            'action_type'    => $actionType,
            'action_payload' => $payload,
            'status'         => 'pending',
        ]);
        $approval->save();

        return [$company, $user, $approval];
    }
}
