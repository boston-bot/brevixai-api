<?php

namespace Tests\Feature;

use App\Models\AuditCase;
use App\Models\CaseRecommendation;
use App\Models\Company;
use App\Models\InvestigationActivityEvent;
use App\Models\User;
use App\Services\InvestigationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestigationWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public function test_list_returns_investigations_for_own_company(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->createCase($company->id, $user->id);
        $this->createCase($company->id, $user->id, ['investigation_priority' => 'critical']);

        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/investigations');

        $response->assertOk()
            ->assertJsonCount(2, 'investigations')
            ->assertJsonPath('total', 2);
    }

    public function test_list_filters_by_investigation_status(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->createCase($company->id, $user->id, ['investigation_status' => 'open']);
        $this->createCase($company->id, $user->id, ['investigation_status' => 'in_review']);

        Sanctum::actingAs($user);

        $this->getJson('/api/investigations?investigation_status=open')
            ->assertOk()
            ->assertJsonCount(1, 'investigations')
            ->assertJsonPath('investigations.0.investigation_status', 'open');

        $this->getJson('/api/investigations?investigation_status=in_review')
            ->assertOk()
            ->assertJsonCount(1, 'investigations');
    }

    public function test_list_filters_by_priority(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->createCase($company->id, $user->id, ['investigation_priority' => 'critical']);
        $this->createCase($company->id, $user->id, ['investigation_priority' => 'low']);

        Sanctum::actingAs($user);

        $this->getJson('/api/investigations?investigation_priority=critical')
            ->assertOk()
            ->assertJsonCount(1, 'investigations')
            ->assertJsonPath('investigations.0.investigation_priority', 'critical');
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/investigations')->assertUnauthorized();
    }

    public function test_investigation_write_endpoints_require_authentication(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $this->getJson("/api/investigations/{$case->id}")->assertUnauthorized();
        $this->postJson("/api/investigations/{$case->id}/assign", [
            'assignee_id' => $user->id,
        ])->assertUnauthorized();
        $this->postJson("/api/investigations/{$case->id}/status", [
            'investigation_status' => 'in_review',
        ])->assertUnauthorized();
        $this->postJson("/api/investigations/{$case->id}/notes", [
            'notes' => 'Unauthenticated note.',
        ])->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Detail
    // -------------------------------------------------------------------------

    public function test_show_returns_full_investigation_detail(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);
        $case = $this->createCase($company->id, $user->id, [
            'case_recommendation_id' => $recommendation->id,
            'investigation_status' => 'in_review',
            'investigation_priority' => 'high',
            'investigation_summary' => 'Elevated vendor risk patterns require review.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/investigations/{$case->id}");

        $response->assertOk()
            ->assertJsonPath('investigation.id', $case->id)
            ->assertJsonPath('investigation.title', $case->title)
            ->assertJsonPath('workspace.investigation_status', 'in_review')
            ->assertJsonPath('workspace.investigation_priority', 'high')
            ->assertJsonPath('workspace.investigation_summary', 'Elevated vendor risk patterns require review.')
            ->assertJsonPath('recommendation.id', $recommendation->id)
            ->assertJsonPath('recommendation.case_type', $recommendation->case_type);
    }

    public function test_show_returns_activity_timeline(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $this->createActivityEvent($case->id, $company->id, InvestigationActivityEvent::EVENT_CASE_CREATED, InvestigationActivityEvent::ACTOR_USER, $user->id, 'Case opened');
        $this->createActivityEvent($case->id, $company->id, InvestigationActivityEvent::EVENT_STATUS_CHANGED, InvestigationActivityEvent::ACTOR_USER, $user->id, 'Status changed');

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/investigations/{$case->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'activity_timeline')
            ->assertJsonPath('activity_timeline.0.event_type', InvestigationActivityEvent::EVENT_CASE_CREATED)
            ->assertJsonPath('activity_timeline.1.event_type', InvestigationActivityEvent::EVENT_STATUS_CHANGED);
    }

    public function test_show_sanitizes_activity_event_metadata(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        InvestigationActivityEvent::create([
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'event_type' => InvestigationActivityEvent::EVENT_STATUS_CHANGED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
            'event_summary' => 'Status changed',
            'event_metadata' => [
                'safe_field' => 'visible',
                'raw_payload' => 'secret-raw-payload',
                'nested' => [
                    'transaction_details' => ['account' => 'secret-account'],
                    'safe_count' => 2,
                ],
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/investigations/{$case->id}");

        $response->assertOk()
            ->assertJsonPath('activity_timeline.0.event_metadata.safe_field', 'visible')
            ->assertJsonPath('activity_timeline.0.event_metadata.nested.safe_count', 2)
            ->assertJsonMissingPath('activity_timeline.0.event_metadata.raw_payload')
            ->assertJsonMissingPath('activity_timeline.0.event_metadata.nested.transaction_details');

        $this->assertStringNotContainsString('secret-raw-payload', $response->getContent());
        $this->assertStringNotContainsString('secret-account', $response->getContent());
    }

    public function test_show_returns_404_for_other_company(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/investigations/{$case->id}")->assertNotFound();
    }

    public function test_show_returns_null_recommendation_when_none_linked(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/investigations/{$case->id}")
            ->assertOk()
            ->assertJsonPath('recommendation', null);
    }

    // -------------------------------------------------------------------------
    // Assign
    // -------------------------------------------------------------------------

    public function test_assign_updates_investigation_assignee(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/assign", [
            'assignee_id' => $user->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_cases', [
            'id' => $case->id,
            'investigation_assigned_user_id' => $user->id,
        ]);
    }

    public function test_assign_records_activity_event(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/assign", [
            'assignee_id' => $user->id,
        ])->assertOk();

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_ASSIGNED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);
    }

    public function test_assign_updates_last_activity_at(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $this->assertNull($case->last_activity_at);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/assign", [
            'assignee_id' => $user->id,
        ])->assertOk();

        $this->assertNotNull($case->fresh()->last_activity_at);
    }

    public function test_assign_rejects_user_from_other_company(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/assign", [
            'assignee_id' => $otherUser->id,
        ])->assertStatus(422);
    }

    public function test_assign_blocked_for_other_company_case(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/assign", [
            'assignee_id' => $user->id,
        ])->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    public function test_status_update_changes_investigation_status(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id, ['investigation_status' => 'open']);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/status", [
            'investigation_status' => 'in_review',
        ])->assertOk();

        $this->assertDatabaseHas('audit_cases', [
            'id' => $case->id,
            'investigation_status' => 'in_review',
        ]);
    }

    public function test_status_update_records_activity_event(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/status", [
            'investigation_status' => 'escalated',
        ])->assertOk();

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_STATUS_CHANGED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
        ]);
    }

    public function test_status_update_rejects_invalid_status(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/status", [
            'investigation_status' => 'invalid_status',
        ])->assertUnprocessable();
    }

    public function test_status_update_blocked_for_other_company(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/status", [
            'investigation_status' => 'resolved',
        ])->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Notes
    // -------------------------------------------------------------------------

    public function test_notes_adds_investigation_notes(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/notes", [
            'notes' => 'Vendor payments show a clear pattern of splitting.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_cases', [
            'id' => $case->id,
            'investigation_notes' => 'Vendor payments show a clear pattern of splitting.',
        ]);
    }

    public function test_notes_records_activity_event(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/notes", [
            'notes' => 'Initial investigation notes.',
        ])->assertOk();

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_NOTES_ADDED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
        ]);
    }

    public function test_notes_blocked_for_other_company(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/notes", [
            'notes' => 'Unauthorized notes attempt.',
        ])->assertNotFound();

        $this->assertDatabaseMissing('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_NOTES_ADDED,
        ]);
    }

    // -------------------------------------------------------------------------
    // Activity events
    // -------------------------------------------------------------------------

    public function test_activity_event_metadata_excludes_sensitive_keys(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $investigationService = app(InvestigationService::class);
        $investigationService->recordActivity(
            caseId: $case->id,
            companyId: $company->id,
            eventType: InvestigationActivityEvent::EVENT_STATUS_CHANGED,
            actorType: InvestigationActivityEvent::ACTOR_SYSTEM,
            actorId: null,
            eventSummary: 'System status update',
            eventMetadata: [
                'new_status' => 'in_review',
                'evidence' => ['secret_transaction' => 'do-not-log'],
                'supporting_evidence' => ['raw_data' => 'also-hidden'],
                'safe_field' => 'visible',
            ],
        );

        $event = InvestigationActivityEvent::where('audit_case_id', $case->id)->first();
        $metadata = $event->event_metadata;

        $this->assertArrayHasKey('safe_field', $metadata);
        $this->assertArrayHasKey('new_status', $metadata);
        $this->assertArrayNotHasKey('evidence', $metadata);
        $this->assertArrayNotHasKey('supporting_evidence', $metadata);
        $this->assertStringNotContainsString('do-not-log', json_encode($metadata));
        $this->assertStringNotContainsString('also-hidden', json_encode($metadata));
    }

    public function test_case_creation_from_recommendation_records_investigation_activity(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/case-recommendations/{$recommendation->id}/approve")
            ->assertOk();

        $case = AuditCase::where('company_id', $company->id)->first();
        $this->assertNotNull($case);

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_CASE_CREATED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        $company = new Company(['name' => 'Test Co '.Str::random(4)]);
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCase(string $companyId, string $userId, array $overrides = []): AuditCase
    {
        $case = new AuditCase(array_merge([
            'company_id' => $companyId,
            'title' => 'Test Investigation',
            'description' => 'A test investigation case.',
            'status' => 'open',
            'severity' => 'warning',
            'created_by' => $userId,
            'investigation_status' => 'open',
            'investigation_priority' => 'medium',
        ], $overrides));
        $case->id = (string) Str::uuid();
        $case->save();

        return $case;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCaseRecommendation(string $companyId, array $overrides = []): CaseRecommendation
    {
        return CaseRecommendation::create(array_merge([
            'company_id' => $companyId,
            'case_type' => 'vendor_payment_reconciliation_investigation',
            'severity' => 'high',
            'title' => 'Investigate vendor and reconciliation risk signals',
            'summary' => 'Deterministic scoring found elevated signals across vendor risk and reconciliation risk.',
            'source_risk_domains' => ['vendor_risk', 'reconciliation_risk'],
            'related_alert_recommendation_ids' => [(string) Str::uuid()],
            'evidence' => ['domain_scores' => ['vendor_risk' => 75]],
            'confidence_score' => 0.9,
            'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
        ], $overrides));
    }

    private function createActivityEvent(
        string $caseId,
        string $companyId,
        string $eventType,
        string $actorType,
        ?string $actorId,
        string $summary,
    ): InvestigationActivityEvent {
        return InvestigationActivityEvent::create([
            'audit_case_id' => $caseId,
            'company_id' => $companyId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_summary' => $summary,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'investigation_report_exports',
            'investigation_evidence_items',
            'investigation_activity_events',
            'audit_case_events',
            'audit_cases',
            'case_recommendations',
            'alert_recommendations',
            'recommendation_review_events',
            'personal_access_tokens',
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

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
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

        Schema::create('case_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->text('case_type');
            $table->text('severity');
            $table->text('title');
            $table->text('summary');
            $table->json('source_risk_domains');
            $table->json('related_alert_recommendation_ids');
            $table->json('evidence');
            $table->decimal('confidence_score', 5, 4)->default(0);
            $table->boolean('requires_human_review')->default(true);
            $table->boolean('can_auto_create')->default(false);
            $table->text('status')->default('pending_review');
            $table->foreignUuid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('case_recommendation_id')->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('status')->default('open');
            $table->text('severity')->default('warning');
            $table->text('investigation_status')->default('open');
            $table->foreignUuid('investigation_assigned_user_id')->nullable();
            $table->text('investigation_priority')->default('medium');
            $table->text('investigation_summary')->nullable();
            $table->text('investigation_notes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('investigation_metadata')->nullable();
            $table->foreignUuid('assigned_to')->nullable();
            $table->foreignUuid('created_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_case_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id');
            $table->foreignUuid('company_id');
            $table->foreignUuid('user_id')->nullable();
            $table->text('event_type');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('recommendation_review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->text('recommendation_type');
            $table->uuid('recommendation_id');
            $table->text('event_type');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->json('event_metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('investigation_activity_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_case_id');
            $table->foreignUuid('company_id');
            $table->text('event_type');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->text('event_summary');
            $table->json('event_metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('investigation_report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_case_id');
            $table->foreignUuid('company_id');
            $table->foreignUuid('generated_by_user_id');
            $table->text('format');
            $table->text('filename')->nullable();
            $table->text('report_hash');
            $table->timestamp('generated_at')->useCurrent();
            $table->json('metadata')->nullable();
        });

        Schema::create('investigation_evidence_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_case_id');
            $table->foreignUuid('company_id');
            $table->text('evidence_type');
            $table->uuid('evidence_reference_id')->nullable();
            $table->text('title');
            $table->text('summary');
            $table->text('source');
            $table->text('added_by_actor_type');
            $table->uuid('added_by_actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
