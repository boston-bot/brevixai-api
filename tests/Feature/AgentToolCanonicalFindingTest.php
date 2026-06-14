<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentToolCanonicalFindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-tool-key']);
        $this->createSchema();
    }

    public function test_agent_findings_materialize_as_canonical_findings_and_open_investigations(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        $agentRunId = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        $payload = [
            'agent_run_id' => $agentRunId,
            'findings' => [[
                'title' => 'Duplicate Payment Pattern',
                'severity' => 'high',
                'confidence' => 0.82,
                'summary' => 'Rex identified two vendor payments that need reviewer attention.',
                'evidence' => [
                    [
                        'type' => 'transaction',
                        'id' => $transactionId,
                        'title' => 'Northstar Consulting payment',
                        'summary' => 'Payment matched duplicate-payment indicators.',
                        'raw' => 'DO NOT STORE RAW AGENT EVIDENCE',
                    ],
                    [
                        'type' => 'recommended_next_steps',
                        'steps' => [
                            'Invoice register for the vendor',
                            'Approval record for both payments',
                        ],
                    ],
                ],
                'suggestedRecords' => [[
                    'recordType' => 'bank_detail',
                    'label' => 'Bank payment detail',
                    'reason' => 'Needed to verify settlement timing.',
                    'priority' => 'recommended',
                ]],
            ]],
        ];

        $response = $this->withToken('test-tool-key')
            ->withHeaders([
                'X-Brevix-User-Id' => $user->id,
                'X-Brevix-Business-Profile-Id' => $profileId,
            ])
            ->postJson("/api/internal/agent-tools/company/{$company->id}/findings", $payload);

        $response->assertCreated()
            ->assertJsonPath('stored', 1)
            ->assertJsonPath('materialized.findingsCreated', 1)
            ->assertJsonPath('materialized.evidenceItemsCreated', 1)
            ->assertJsonPath('findings.0.sourceModule', 'rex_agent')
            ->assertJsonPath('findings.0.category', 'vendor_payments')
            ->assertJsonPath('findings.0.severity', 'critical')
            ->assertJsonPath('findings.0.confidence', 'high');

        $findingId = $response->json('finding_ids.0');
        $this->assertNotEmpty($findingId);

        $this->assertDatabaseHas('findings', [
            'id' => $findingId,
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'source_module' => 'rex_agent',
            'source_record_type' => 'agent_finding',
            'title' => 'Duplicate Payment Pattern',
            'severity' => 'critical',
            'confidence' => 'high',
            'status' => 'new',
        ]);
        $this->assertDatabaseHas('evidence_items', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'finding_id' => $findingId,
            'source_type' => 'transaction',
            'source_record_id' => $transactionId,
            'added_by_actor_type' => 'agent',
        ]);
        $this->assertDatabaseHas('suggested_records', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'finding_id' => $findingId,
            'record_type' => 'bank_detail',
        ]);
        $this->assertDatabaseHas('suggested_records', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'finding_id' => $findingId,
            'record_type' => 'agent_recommended_follow_up',
            'label' => 'Invoice register for the vendor',
        ]);
        $this->assertDatabaseCount('alerts', 0);
        $this->assertStringNotContainsString(
            'DO NOT STORE RAW AGENT EVIDENCE',
            json_encode(DB::table('evidence_items')->where('finding_id', $findingId)->first()) ?: '',
        );

        $this->withToken('test-tool-key')
            ->withHeaders([
                'X-Brevix-User-Id' => $user->id,
                'X-Brevix-Business-Profile-Id' => $profileId,
            ])
            ->postJson("/api/internal/agent-tools/company/{$company->id}/findings", $payload)
            ->assertCreated()
            ->assertJsonPath('materialized.findingsUpdated', 1);

        $this->assertSame(1, DB::table('findings')->where('source_module', 'rex_agent')->count());

        Sanctum::actingAs($user);
        $opened = $this->postJson("/api/findings/{$findingId}/create-investigation", [], [
            'X-Brevix-Business-Profile-Id' => $profileId,
        ]);

        $opened->assertCreated()
            ->assertJsonPath('investigation.title', 'Duplicate Payment Pattern')
            ->assertJsonPath('investigation.category', 'vendor_payments');

        $investigationId = $opened->json('investigation.id');
        $this->assertDatabaseHas('findings', [
            'id' => $findingId,
            'investigation_id' => $investigationId,
            'status' => 'in_review',
        ]);
        $this->assertDatabaseMissing('evidence_items', [
            'finding_id' => $findingId,
            'investigation_id' => null,
        ]);
        $this->assertDatabaseMissing('suggested_records', [
            'finding_id' => $findingId,
            'investigation_id' => null,
        ]);
    }

    /** @return array{0: Company, 1: User, 2: string} */
    private function createWorkspace(): array
    {
        $company = new Company(['name' => 'Agent Canonical Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.test',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Rex',
            'last_name' => 'Reviewer',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        $profileId = (string) Str::uuid();
        DB::table('business_profiles')->insert([
            'id' => $profileId,
            'company_id' => $company->id,
            'name' => 'Main Books',
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workspace_memberships')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'scope' => 'workspace',
            'granted_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$company, $user, $profileId];
    }

    private function createSchema(): void
    {
        foreach ([
            'alerts',
            'case_packages',
            'review_events',
            'reviewer_notes',
            'suggested_records',
            'evidence_item_finding',
            'evidence_items',
            'findings',
            'investigations',
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

        Schema::create('investigations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('legacy_audit_case_id')->nullable();
            $table->text('title');
            $table->text('category')->default('unsure');
            $table->text('subcategory')->nullable();
            $table->text('status')->default('open');
            $table->text('priority')->default('medium');
            $table->date('review_period_start')->nullable();
            $table->date('review_period_end')->nullable();
            $table->text('scope_statement')->nullable();
            $table->json('scope_limitations')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->uuid('created_by');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id')->nullable();
            $table->text('category')->default('unsure');
            $table->text('source_module');
            $table->text('source_record_type');
            $table->text('source_record_id');
            $table->text('title');
            $table->text('summary')->nullable();
            $table->text('detail')->nullable();
            $table->text('severity')->default('warning');
            $table->text('confidence')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->text('reason_code')->nullable();
            $table->text('status')->default('new');
            $table->json('evidence_refs')->nullable();
            $table->json('recommended_action')->nullable();
            $table->text('reviewer_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('evidence_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id')->nullable();
            $table->uuid('finding_id')->nullable();
            $table->uuid('legacy_evidence_item_id')->nullable();
            $table->text('evidence_type');
            $table->text('source_type')->nullable();
            $table->text('source_id')->nullable();
            $table->text('source_record_id')->nullable();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->text('citation_label')->nullable();
            $table->text('source_row_range')->nullable();
            $table->text('file_name')->nullable();
            $table->text('storage_key')->nullable();
            $table->text('hash')->nullable();
            $table->text('added_by_actor_type')->default('user');
            $table->uuid('added_by_actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('evidence_item_finding', function (Blueprint $table): void {
            $table->uuid('evidence_item_id');
            $table->uuid('finding_id');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('suggested_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id')->nullable();
            $table->uuid('finding_id')->nullable();
            $table->text('record_type');
            $table->text('label');
            $table->text('reason')->nullable();
            $table->text('priority')->default('recommended');
            $table->text('status')->default('requested');
            $table->uuid('satisfying_evidence_item_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('reviewer_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id');
            $table->uuid('finding_id')->nullable();
            $table->uuid('author_id')->nullable();
            $table->text('author_name')->nullable();
            $table->text('body');
            $table->text('visibility')->default('internal');
            $table->timestamps();
        });

        Schema::create('review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id');
            $table->uuid('finding_id')->nullable();
            $table->text('event_type');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->text('previous_status')->nullable();
            $table->text('next_status')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('case_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id');
            $table->text('format');
            $table->text('status')->default('completed');
            $table->text('title');
            $table->timestamp('generated_at')->nullable();
            $table->uuid('generated_by')->nullable();
            $table->json('included_sections')->nullable();
            $table->json('included_counts')->nullable();
            $table->text('package_hash')->nullable();
            $table->text('filename')->nullable();
            $table->text('storage_key')->nullable();
            $table->json('manifest')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->text('title');
            $table->text('status')->default('open');
            $table->timestamps();
        });
    }
}
