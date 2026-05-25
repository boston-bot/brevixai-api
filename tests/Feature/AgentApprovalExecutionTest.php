<?php

namespace Tests\Feature;

use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentApprovalExecutionTest extends TestCase
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
            $table->json('reason_codes')->default('[]');
            $table->text('source_system')->nullable();
            $table->uuid('source_recommendation_id')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->json('evidence_refs')->default('[]');
            $table->json('comparison_window')->nullable();
            $table->text('status')->default('open');
            $table->integer('priority_score')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_approve_creates_alert_and_sets_executed_at(): void
    {
        [$company, $user, $approval] = $this->createPendingApproval();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/agent-approvals/{$approval->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('result.resource_type', 'alert');

        $this->assertNotNull($response->json('executed_at'));
        $this->assertNotNull($response->json('result.resource_id'));
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', [
            'company_id' => $company->id,
            'rule_key' => 'vendor_overlap',
            'severity' => 'critical',
            'title' => 'Suspicious Vendor Activity',
        ]);
        $this->assertDatabaseHas('agent_action_approvals', [
            'id' => $approval->id,
            'status' => 'approved',
            'approved_by' => $user->id,
        ]);
    }

    public function test_approve_writes_failed_at_on_execution_failure(): void
    {
        [$company, $user, $approval] = $this->createPendingApproval();
        Sanctum::actingAs($user);

        Schema::drop('alerts'); // force execution failure inside the transaction

        $this->postJson("/api/agent-approvals/{$approval->id}/approve")
            ->assertStatus(422);

        $fresh = AgentActionApproval::find($approval->id);
        $this->assertNotNull($fresh->failed_at);
        $this->assertNotNull($fresh->error_message);
        $this->assertSame('failed', $fresh->status);
        $this->assertDatabaseMissing('agent_action_approvals', [
            'id' => $approval->id,
            'status' => 'approved',
        ]);
    }

    public function test_approve_returns_404_for_cross_company_approval(): void
    {
        [, , $approval] = $this->createPendingApproval('Company A');
        [, $otherUser] = $this->createCompanyUser('Company B');

        Sanctum::actingAs($otherUser);

        $this->postJson("/api/agent-approvals/{$approval->id}/approve")
            ->assertNotFound();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_approve_returns_409_for_already_approved(): void
    {
        [$company, $user, $approval] = $this->createPendingApproval();
        Sanctum::actingAs($user);

        $this->postJson("/api/agent-approvals/{$approval->id}/approve")->assertOk();
        $this->postJson("/api/agent-approvals/{$approval->id}/approve")->assertStatus(409);

        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_reject_sets_rejected_fields_and_creates_no_alert(): void
    {
        [$company, $user, $approval] = $this->createPendingApproval();

        Sanctum::actingAs($user);

        $this->postJson("/api/agent-approvals/{$approval->id}/reject")
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseHas('agent_action_approvals', [
            'id' => $approval->id,
            'status' => 'rejected',
            'rejected_by' => $user->id,
        ]);
    }

    public function test_reject_returns_404_for_cross_company_approval(): void
    {
        [, , $approval] = $this->createPendingApproval('Company A');
        [, $otherUser] = $this->createCompanyUser('Company B');

        Sanctum::actingAs($otherUser);

        $this->postJson("/api/agent-approvals/{$approval->id}/reject")
            ->assertNotFound();
    }

    public function test_reject_returns_409_for_already_rejected(): void
    {
        [$company, $user, $approval] = $this->createPendingApproval();
        Sanctum::actingAs($user);

        $this->postJson("/api/agent-approvals/{$approval->id}/reject")->assertOk();
        $this->postJson("/api/agent-approvals/{$approval->id}/reject")->assertStatus(409);
    }

    public function test_approve_returns_409_after_rejection(): void
    {
        [$company, $user, $approval] = $this->createPendingApproval();
        Sanctum::actingAs($user);

        $this->postJson("/api/agent-approvals/{$approval->id}/reject")->assertOk();
        $this->postJson("/api/agent-approvals/{$approval->id}/approve")->assertStatus(409);

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_approve_returns_422_for_unsupported_action_type(): void
    {
        [$company, $user, $approval] = $this->createPendingApproval(actionType: 'delete_company');

        Sanctum::actingAs($user);

        $this->postJson("/api/agent-approvals/{$approval->id}/approve")
            ->assertStatus(422);

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_approve_requires_authentication(): void
    {
        [, , $approval] = $this->createPendingApproval();

        $this->postJson("/api/agent-approvals/{$approval->id}/approve")
            ->assertUnauthorized();
    }

    public function test_reject_requires_authentication(): void
    {
        [, , $approval] = $this->createPendingApproval();

        $this->postJson("/api/agent-approvals/{$approval->id}/reject")
            ->assertUnauthorized();
    }

    /** @return array{0: Company, 1: User, 2: AgentActionApproval} */
    private function createPendingApproval(
        string $companyName = 'Test Co',
        string $actionType = 'create_alert',
    ): array {
        [$company, $user] = $this->createCompanyUser($companyName);

        $run = new AgentRun([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'input_message' => 'check fraud',
        ]);
        $run->id = (string) Str::uuid();
        $run->save();

        $approval = new AgentActionApproval([
            'agent_run_id' => $run->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'action_type' => $actionType,
            'action_payload' => [
                'rule_key' => 'vendor_overlap',
                'severity' => 'critical',
                'title' => 'Suspicious Vendor Activity',
                'detail' => 'Employee name matched vendor in ledger.',
            ],
            'status' => 'pending',
        ]);
        $approval->save();

        return [$company, $user, $approval];
    }

    /** @return array{0: Company, 1: User} */
    private function createCompanyUser(string $name = 'Test Co'): array
    {
        $company = new Company(['name' => $name]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }
}
