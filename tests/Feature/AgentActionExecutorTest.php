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

        Schema::dropIfExists('retrieval_feedback');
        Schema::dropIfExists('investigation_playbooks');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('investigations');
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

        Schema::create('investigations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->text('title');
            $table->text('category')->default('unsure');
            $table->text('status')->default('open');
            $table->text('priority')->default('medium');
            $table->text('scope_statement')->nullable();
            $table->json('scope_limitations')->nullable();
            $table->uuid('created_by');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Mirrors 2026_06_14_183535_create_investigation_playbooks_tables.php
        // (retrieval_feedback.user_id is a uuid FK to users).
        Schema::create('investigation_playbooks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('category');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('retrieval_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->string('query_text');
            $table->integer('relevance_score');
            $table->text('user_feedback')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function test_supported_action_types_includes_create_alert(): void
    {
        $executor = new AgentActionExecutorService();
        $this->assertContains('create_alert', $executor->supportedActionTypes());
    }

    public function test_supported_action_types_includes_create_investigation(): void
    {
        $executor = new AgentActionExecutorService();
        $this->assertContains('create_investigation', $executor->supportedActionTypes());
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

    public function test_create_investigation_returns_canonical_investigation_result(): void
    {
        [$company, $user, $approval] = $this->fixtures(actionType: 'create_investigation', extraPayload: [
            'title' => 'Duplicate Payment Pattern',
            'category' => 'vendor_payment',
            'severity' => 'high',
            'summary' => 'Review repeated payments to the same vendor.',
        ]);

        $result = (new AgentActionExecutorService())->execute($approval, $user);

        $this->assertSame('investigation', $result->resourceType);
        $this->assertDatabaseHas('investigations', [
            'id' => $result->resourceId,
            'company_id' => $company->id,
            'title' => 'Duplicate Payment Pattern',
            'category' => 'vendor_payments',
            'priority' => 'high',
        ]);
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

    public function test_create_investigation_persists_playbook_provenance_capped_at_three(): void
    {
        $playbookId = $this->createPlaybook('Duplicate invoice review');

        [$company, $user, $approval] = $this->fixtures(actionType: 'create_investigation', extraPayload: [
            'title' => 'Playbook-Informed Investigation',
            'retrieval_query' => 'duplicate vendor invoices split payments',
            'playbook_refs' => [
                $this->playbookRef((string) $playbookId, 'Duplicate invoice review'),
                $this->playbookRef('9001', 'Ghost employee review'),
                $this->playbookRef('9002', 'Shell vendor review'),
                $this->playbookRef('9003', 'Fourth ref beyond the cap'),
            ],
        ]);

        $result = (new AgentActionExecutorService())->execute($approval, $user);

        $investigation = \App\Models\Investigation::find($result->resourceId);
        $metadata = $investigation->metadata;

        $this->assertSame('duplicate vendor invoices split payments', $metadata['retrieval_query']);
        $this->assertCount(3, $metadata['playbook_refs']);
        $this->assertSame((string) $playbookId, $metadata['playbook_refs'][0]['playbook_id']);
        $this->assertSame('Shell vendor review', $metadata['playbook_refs'][2]['title']);
    }

    public function test_create_investigation_records_outcome_feedback_only_for_existing_playbooks(): void
    {
        $existingId = $this->createPlaybook('Duplicate invoice review');
        $otherId = $this->createPlaybook('Ghost employee review');

        [$company, $user, $approval] = $this->fixtures(actionType: 'create_investigation', extraPayload: [
            'title' => 'Playbook-Informed Investigation',
            'retrieval_query' => 'duplicate vendor invoices',
            'playbook_refs' => [
                $this->playbookRef((string) $existingId, 'Duplicate invoice review'),
                $this->playbookRef((string) $otherId, 'Ghost employee review'),
                $this->playbookRef('424242', 'Playbook that does not exist'),
            ],
        ]);

        (new AgentActionExecutorService())->execute($approval, $user);

        $this->assertSame(2, \App\Models\Fraud\RetrievalFeedback::count());

        foreach ([$existingId, $otherId] as $playbookId) {
            $this->assertDatabaseHas('retrieval_feedback', [
                'playbook_id' => $playbookId,
                'query_text' => 'duplicate vendor invoices',
                'relevance_score' => 5,
                'user_feedback' => 'outcome:investigation_created',
                'user_id' => $user->id,
            ]);
        }

        $this->assertDatabaseMissing('retrieval_feedback', ['playbook_id' => 424242]);
    }

    public function test_create_investigation_outcome_feedback_falls_back_to_ref_title_for_query_text(): void
    {
        $playbookId = $this->createPlaybook('Duplicate invoice review');

        [$company, $user, $approval] = $this->fixtures(actionType: 'create_investigation', extraPayload: [
            'title' => 'Playbook-Informed Investigation',
            'playbook_refs' => [
                $this->playbookRef((string) $playbookId, 'Duplicate invoice review'),
            ],
        ]);

        (new AgentActionExecutorService())->execute($approval, $user);

        $this->assertDatabaseHas('retrieval_feedback', [
            'playbook_id' => $playbookId,
            'query_text' => 'Duplicate invoice review',
            'relevance_score' => 5,
            'user_feedback' => 'outcome:investigation_created',
            'user_id' => $user->id,
        ]);
    }

    public function test_create_investigation_without_playbook_refs_has_empty_provenance_and_no_feedback(): void
    {
        [$company, $user, $approval] = $this->fixtures(actionType: 'create_investigation', extraPayload: [
            'title' => 'Plain Investigation',
        ]);

        $result = (new AgentActionExecutorService())->execute($approval, $user);

        $investigation = \App\Models\Investigation::find($result->resourceId);
        $this->assertSame([], $investigation->metadata['playbook_refs']);
        $this->assertNull($investigation->metadata['retrieval_query']);
        $this->assertSame(0, \App\Models\Fraud\RetrievalFeedback::count());
    }

    public function test_create_investigation_with_only_nonexistent_playbook_refs_still_succeeds(): void
    {
        [$company, $user, $approval] = $this->fixtures(actionType: 'create_investigation', extraPayload: [
            'title' => 'Investigation With Stale Refs',
            'retrieval_query' => 'stale playbook reference',
            'playbook_refs' => [
                $this->playbookRef('99999', 'Playbook removed from corpus'),
            ],
        ]);

        $result = (new AgentActionExecutorService())->execute($approval, $user);

        $this->assertSame('investigation', $result->resourceType);
        $this->assertDatabaseHas('investigations', ['id' => $result->resourceId]);
        $this->assertSame(0, \App\Models\Fraud\RetrievalFeedback::count());

        $fresh = AgentActionApproval::find($approval->id);
        $this->assertSame('approved', $fresh->status);
    }

    private function createPlaybook(string $title): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('investigation_playbooks')->insertGetId([
            'title' => $title,
            'category' => 'expense',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> Contract shape emitted by the Python agent service. */
    private function playbookRef(string $playbookId, string $title): array
    {
        return [
            'playbook_id' => $playbookId,
            'title' => $title,
            'confidence' => 'high',
            'relevance_score' => 0.91,
            'corpus_version' => 'fraud_playbooks:v2',
            'matched_fields' => ['title', 'red_flags'],
        ];
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
