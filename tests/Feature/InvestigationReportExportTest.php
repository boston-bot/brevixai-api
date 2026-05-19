<?php

namespace Tests\Feature;

use App\Models\AuditCase;
use App\Models\CaseRecommendation;
use App\Models\Company;
use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use App\Models\User;
use App\Services\InvestigationReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestigationReportExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    // -------------------------------------------------------------------------
    // Authorized user generates report
    // -------------------------------------------------------------------------

    public function test_authorized_user_can_generate_report(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/reports", [
            'format' => 'json',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'report' => [
                    'title',
                    'generated_at',
                    'generated_by_user_id',
                    'case_summary',
                    'risk_summary',
                    'investigative_synthesis',
                    'evidence_items',
                    'activity_timeline',
                    'notes',
                    'disclaimer',
                ],
            ])
            ->assertJsonPath('report.generated_by_user_id', $user->id)
            ->assertJsonPath('report.title', 'Test Investigation');
    }

    // -------------------------------------------------------------------------
    // Unauthenticated user blocked
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_generate_report(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $this->postJson("/api/investigations/{$case->id}/reports", [
            'format' => 'json',
        ])->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Cross-company isolation
    // -------------------------------------------------------------------------

    public function test_user_cannot_generate_report_for_other_company_investigation(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/reports")->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Report includes evidence items
    // -------------------------------------------------------------------------

    public function test_report_includes_evidence_items(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $this->createEvidenceItem($case->id, $company->id, [
            'evidence_type' => InvestigationEvidenceItem::TYPE_TRANSACTION,
            'title' => 'Suspicious payment',
        ]);
        $this->createEvidenceItem($case->id, $company->id, [
            'evidence_type' => InvestigationEvidenceItem::TYPE_VENDOR,
            'title' => 'Shell vendor profile',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/reports");

        $response->assertOk()
            ->assertJsonCount(2, 'report.evidence_items')
            ->assertJsonPath('report.evidence_items.0.evidence_type', InvestigationEvidenceItem::TYPE_TRANSACTION)
            ->assertJsonPath('report.evidence_items.0.title', 'Suspicious payment')
            ->assertJsonPath('report.evidence_items.1.evidence_type', InvestigationEvidenceItem::TYPE_VENDOR);
    }

    // -------------------------------------------------------------------------
    // Report includes activity timeline
    // -------------------------------------------------------------------------

    public function test_report_includes_activity_timeline(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        InvestigationActivityEvent::create([
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'event_type' => InvestigationActivityEvent::EVENT_CASE_CREATED,
            'actor_type' => InvestigationActivityEvent::ACTOR_SYSTEM,
            'actor_id' => null,
            'event_summary' => 'Case created from recommendation approval',
            'event_metadata' => ['internal' => 'data'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/reports");

        $response->assertOk();

        $timeline = $response->json('report.activity_timeline');

        // At least the seeded event should be present (report generation adds one more)
        $this->assertGreaterThanOrEqual(1, count($timeline));
        $this->assertEquals(
            InvestigationActivityEvent::EVENT_CASE_CREATED,
            $timeline[0]['event_type']
        );
        // event_metadata must not be exposed in the report
        $this->assertArrayNotHasKey('event_metadata', $timeline[0]);
    }

    // -------------------------------------------------------------------------
    // Report includes disclaimer
    // -------------------------------------------------------------------------

    public function test_report_includes_disclaimer(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/reports")
            ->assertOk()
            ->assertJsonPath(
                'report.disclaimer',
                'This report summarizes risk indicators and review activity. It is not a legal conclusion or proof of fraud.'
            );
    }

    // -------------------------------------------------------------------------
    // Sensitive metadata excluded from evidence items
    // -------------------------------------------------------------------------

    public function test_sensitive_metadata_excluded_from_evidence_items(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $item = new InvestigationEvidenceItem([
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'evidence_type' => InvestigationEvidenceItem::TYPE_TRANSACTION,
            'title' => 'Payment with sensitive metadata',
            'summary' => 'Metadata contains sensitive fields.',
            'source' => 'manual',
            'added_by_actor_type' => InvestigationEvidenceItem::ACTOR_USER,
            'added_by_actor_id' => $user->id,
            'metadata' => [
                'safe_label' => 'visible',
                'risk_score' => 92,
                'raw_payload' => 'should-be-stripped',
                'transaction_details' => ['account' => 'secret'],
                'evidence' => ['domain' => 'also-stripped'],
            ],
        ]);
        $item->id = (string) Str::uuid();
        $item->save();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/reports");

        $response->assertOk();

        $evidenceItems = $response->json('report.evidence_items');
        $this->assertCount(1, $evidenceItems);

        $metadata = $evidenceItems[0]['metadata'];
        $this->assertArrayHasKey('safe_label', $metadata);
        $this->assertArrayHasKey('risk_score', $metadata);
        $this->assertArrayNotHasKey('raw_payload', $metadata);
        $this->assertArrayNotHasKey('transaction_details', $metadata);
        $this->assertArrayNotHasKey('evidence', $metadata);
        $this->assertStringNotContainsString('should-be-stripped', json_encode($metadata));
        $this->assertStringNotContainsString('secret', json_encode($metadata));
    }

    // -------------------------------------------------------------------------
    // Activity event recorded on report generation
    // -------------------------------------------------------------------------

    public function test_activity_event_recorded_when_report_generated(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/reports")->assertOk();

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_REPORT_GENERATED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Agents cannot generate reports
    // -------------------------------------------------------------------------

    public function test_agent_cannot_generate_report_via_service(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $service = app(InvestigationReportService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Agents cannot generate investigation reports');

        $service->generate(
            companyId: $company->id,
            caseId: $case->id,
            actorType: InvestigationActivityEvent::ACTOR_AGENT,
            actorId: (string) Str::uuid(),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        $company = new Company(['name' => 'Test Co ' . Str::random(4)]);
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
    private function createEvidenceItem(string $caseId, string $companyId, array $overrides = []): InvestigationEvidenceItem
    {
        $item = new InvestigationEvidenceItem(array_merge([
            'audit_case_id' => $caseId,
            'company_id' => $companyId,
            'evidence_type' => InvestigationEvidenceItem::TYPE_NOTE,
            'title' => 'Test evidence item',
            'summary' => 'A test evidence entry.',
            'source' => 'manual',
            'added_by_actor_type' => InvestigationEvidenceItem::ACTOR_USER,
        ], $overrides));
        $item->id = (string) Str::uuid();
        $item->save();

        return $item;
    }

    private function createSchema(): void
    {
        foreach ([
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
