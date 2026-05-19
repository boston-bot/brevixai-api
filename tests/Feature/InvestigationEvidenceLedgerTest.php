<?php

namespace Tests\Feature;

use App\Models\AuditCase;
use App\Models\CaseRecommendation;
use App\Models\Company;
use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use App\Models\User;
use App\Services\InvestigationEvidenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestigationEvidenceLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    // -------------------------------------------------------------------------
    // List evidence
    // -------------------------------------------------------------------------

    public function test_list_evidence_returns_items_for_own_investigation(): void
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

        $response = $this->getJson("/api/investigations/{$case->id}/evidence");

        $response->assertOk()
            ->assertJsonCount(2, 'evidence_items')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('evidence_items.0.evidence_type', InvestigationEvidenceItem::TYPE_TRANSACTION)
            ->assertJsonPath('evidence_items.1.evidence_type', InvestigationEvidenceItem::TYPE_VENDOR);
    }

    public function test_list_evidence_returns_404_for_other_company(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/investigations/{$case->id}/evidence")->assertNotFound();
    }

    public function test_list_evidence_returns_empty_for_new_investigation(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/investigations/{$case->id}/evidence")
            ->assertOk()
            ->assertJsonCount(0, 'evidence_items')
            ->assertJsonPath('total', 0);
    }

    // -------------------------------------------------------------------------
    // Add transaction evidence
    // -------------------------------------------------------------------------

    public function test_add_transaction_evidence(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $transactionId = (string) Str::uuid();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/evidence", [
            'evidence_type' => 'transaction',
            'evidence_reference_id' => $transactionId,
            'title' => 'Split payment #4821',
            'summary' => 'Payment split into three transactions just below approval threshold.',
            'source' => 'transaction:review',
        ]);

        $response->assertCreated()
            ->assertJsonPath('evidence_item.evidence_type', 'transaction')
            ->assertJsonPath('evidence_item.evidence_reference_id', $transactionId)
            ->assertJsonPath('evidence_item.title', 'Split payment #4821')
            ->assertJsonPath('evidence_item.added_by_actor_type', InvestigationEvidenceItem::ACTOR_USER)
            ->assertJsonPath('evidence_item.added_by_actor_id', $user->id);

        $this->assertDatabaseHas('investigation_evidence_items', [
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'evidence_type' => 'transaction',
            'evidence_reference_id' => $transactionId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Add vendor evidence
    // -------------------------------------------------------------------------

    public function test_add_vendor_evidence(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $vendorId = (string) Str::uuid();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/evidence", [
            'evidence_type' => 'vendor',
            'evidence_reference_id' => $vendorId,
            'title' => 'Shell entity: Apex Consulting LLC',
            'summary' => 'No web presence, registered address is a PO box, no employees found.',
            'source' => 'vendor:risk_profile',
        ]);

        $response->assertCreated()
            ->assertJsonPath('evidence_item.evidence_type', 'vendor')
            ->assertJsonPath('evidence_item.evidence_reference_id', $vendorId);

        $this->assertDatabaseHas('investigation_evidence_items', [
            'audit_case_id' => $case->id,
            'evidence_type' => 'vendor',
        ]);
    }

    // -------------------------------------------------------------------------
    // Add alert evidence
    // -------------------------------------------------------------------------

    public function test_add_alert_evidence(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $alertId = (string) Str::uuid();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/evidence", [
            'evidence_type' => 'alert',
            'evidence_reference_id' => $alertId,
            'title' => 'High-severity duplicate payment alert',
            'summary' => 'Alert fired for duplicate invoice #INV-9942 paid twice in 30 days.',
            'source' => 'alert:duplicate_payment_rule',
        ]);

        $response->assertCreated()
            ->assertJsonPath('evidence_item.evidence_type', 'alert')
            ->assertJsonPath('evidence_item.evidence_reference_id', $alertId);
    }

    // -------------------------------------------------------------------------
    // Reject cross-company evidence
    // -------------------------------------------------------------------------

    public function test_add_evidence_rejected_for_other_company_investigation(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/evidence", [
            'evidence_type' => 'note',
            'title' => 'Cross-company attempt',
            'summary' => 'Should not be allowed.',
            'source' => 'manual',
        ])->assertNotFound();

        $this->assertDatabaseMissing('investigation_evidence_items', [
            'audit_case_id' => $case->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Delete evidence
    // -------------------------------------------------------------------------

    public function test_delete_evidence_removes_item(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $item = $this->createEvidenceItem($case->id, $company->id);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/investigations/{$case->id}/evidence/{$item->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('investigation_evidence_items', [
            'id' => $item->id,
        ]);
    }

    public function test_delete_evidence_rejected_for_other_company(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);
        $item = $this->createEvidenceItem($case->id, $otherCompany->id);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/investigations/{$case->id}/evidence/{$item->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('investigation_evidence_items', [
            'id' => $item->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Agent cannot add evidence
    // -------------------------------------------------------------------------

    public function test_agent_cannot_add_evidence_via_service(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $service = app(InvestigationEvidenceService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Agents cannot add evidence items');

        $service->add(
            companyId: $company->id,
            actorType: InvestigationEvidenceItem::ACTOR_AGENT,
            actorId: null,
            caseId: $case->id,
            data: [
                'evidence_type' => InvestigationEvidenceItem::TYPE_NOTE,
                'title' => 'Agent attempt',
                'summary' => 'Should be blocked.',
                'source' => 'agent:chat',
            ],
        );
    }

    public function test_agent_cannot_remove_evidence_via_service(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $item = $this->createEvidenceItem($case->id, $company->id);

        $service = app(InvestigationEvidenceService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Agents cannot remove evidence items');

        $service->remove(
            companyId: $company->id,
            actorType: InvestigationEvidenceItem::ACTOR_AGENT,
            actorId: null,
            caseId: $case->id,
            evidenceItemId: $item->id,
        );
    }

    // -------------------------------------------------------------------------
    // Metadata sanitized
    // -------------------------------------------------------------------------

    public function test_metadata_sanitized_on_add(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/evidence", [
            'evidence_type' => 'transaction',
            'title' => 'Test with sensitive metadata',
            'summary' => 'Metadata should be sanitized.',
            'source' => 'manual',
            'metadata' => [
                'safe_field' => 'visible',
                'raw_payload' => 'should-be-stripped',
                'transaction_details' => ['account' => 'secret'],
                'evidence' => ['raw_data' => 'also-stripped'],
                'risk_score' => 85,
            ],
        ])->assertCreated();

        $item = InvestigationEvidenceItem::where('audit_case_id', $case->id)->first();
        $metadata = $item->metadata;

        $this->assertArrayHasKey('safe_field', $metadata);
        $this->assertArrayHasKey('risk_score', $metadata);
        $this->assertArrayNotHasKey('raw_payload', $metadata);
        $this->assertArrayNotHasKey('transaction_details', $metadata);
        $this->assertArrayNotHasKey('evidence', $metadata);
        $this->assertStringNotContainsString('should-be-stripped', json_encode($metadata));
        $this->assertStringNotContainsString('secret', json_encode($metadata));
    }

    // -------------------------------------------------------------------------
    // Activity event recorded
    // -------------------------------------------------------------------------

    public function test_activity_event_recorded_on_add(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/evidence", [
            'evidence_type' => 'note',
            'title' => 'Investigator field note',
            'summary' => 'Observed irregular approval pattern.',
            'source' => 'manual',
        ])->assertCreated();

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_EVIDENCE_LINKED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);
    }

    public function test_activity_event_recorded_on_remove(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $item = $this->createEvidenceItem($case->id, $company->id);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/investigations/{$case->id}/evidence/{$item->id}")
            ->assertOk();

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_EVIDENCE_REMOVED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Investigation detail includes evidence_items
    // -------------------------------------------------------------------------

    public function test_investigation_detail_includes_evidence_items(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $this->createEvidenceItem($case->id, $company->id, [
            'evidence_type' => InvestigationEvidenceItem::TYPE_TRANSACTION,
            'title' => 'Key transaction',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/investigations/{$case->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'evidence_items')
            ->assertJsonPath('evidence_items.0.evidence_type', InvestigationEvidenceItem::TYPE_TRANSACTION)
            ->assertJsonPath('evidence_items.0.title', 'Key transaction');
    }

    public function test_investigation_detail_includes_empty_evidence_items_when_none(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/investigations/{$case->id}")
            ->assertOk()
            ->assertJsonCount(0, 'evidence_items');
    }

    // -------------------------------------------------------------------------
    // System adds evidence on recommendation approval
    // -------------------------------------------------------------------------

    public function test_recommendation_approval_auto_adds_evidence_item(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/case-recommendations/{$recommendation->id}/approve")
            ->assertOk();

        $case = AuditCase::where('company_id', $company->id)->first();
        $this->assertNotNull($case);

        $this->assertDatabaseHas('investigation_evidence_items', [
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'evidence_type' => InvestigationEvidenceItem::TYPE_RECOMMENDATION,
            'evidence_reference_id' => $recommendation->id,
            'added_by_actor_type' => InvestigationEvidenceItem::ACTOR_SYSTEM,
        ]);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_add_evidence_rejects_invalid_evidence_type(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/evidence", [
            'evidence_type' => 'invalid_type',
            'title' => 'Test',
            'summary' => 'Test summary.',
            'source' => 'manual',
        ])->assertUnprocessable();
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
