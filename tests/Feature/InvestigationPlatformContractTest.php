<?php

namespace Tests\Feature;

use App\Models\AuditCase;
use App\Models\CaseRecommendation;
use App\Models\Company;
use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use App\Models\InvestigationReportExport;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestigationPlatformContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_contract_endpoint_exposes_canonical_vocabulary_and_shapes(): void
    {
        $response = $this->getJson('/api/investigation-platform/contract');

        $response->assertOk()
            ->assertJsonPath('contractVersion', '2026-06-12')
            ->assertJsonPath('resources', [
                'Investigation',
                'Finding',
                'EvidenceItem',
                'SuggestedRecord',
                'ReviewEvent',
                'CasePackage',
            ])
            ->assertJsonPath('guardrails.rexRole', 'investigation_assistant')
            ->assertJsonPath('guardrails.findingsAreConclusions', false)
            ->assertJsonPath('guardrails.requiresHumanReviewForFinalJudgment', true)
            ->assertJsonStructure([
                'vocabulary' => [
                    'investigationCategories',
                    'investigationStatuses',
                    'findingStatuses',
                    'findingSourceModules',
                    'evidenceTypes',
                    'suggestedRecordStatuses',
                    'packageFormats',
                ],
                'responseShapes' => [
                    'investigation',
                    'finding',
                    'evidenceItem',
                    'suggestedRecord',
                    'reviewEvent',
                    'casePackage',
                ],
            ]);

        $this->assertContains('vendor_payments', $response->json('vocabulary.investigationCategories'));
        $this->assertContains('ready_for_package', $response->json('vocabulary.investigationStatuses'));
        $this->assertContains('vendor_risk', $response->json('vocabulary.findingSourceModules'));
        $this->assertContains('tax_notices', $response->json('vocabulary.findingSourceModules'));
        $this->assertContains('source_row', $response->json('vocabulary.evidenceTypes'));
    }

    public function test_investigation_contract_projects_current_workspace_into_canonical_shapes(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);
        $case = $this->createCase($company->id, $user->id, [
            'case_recommendation_id' => $recommendation->id,
            'investigation_status' => 'in_review',
            'investigation_priority' => 'high',
            'investigation_summary' => 'Review vendor payment and reconciliation signals.',
            'investigation_metadata' => [
                'category' => 'vendor_payments',
                'review_period' => [
                    'startDate' => '2026-05-01',
                    'endDate' => '2026-05-31',
                    'label' => 'May 2026',
                ],
                'scope_limitations' => ['Vendor onboarding packet is not yet available.'],
                'suggested_records' => [[
                    'id' => 'suggested-record-1',
                    'findingId' => "finding:case_recommendation:{$recommendation->id}",
                    'recordType' => 'vendor_onboarding_packet',
                    'label' => 'Vendor onboarding packet',
                    'reason' => 'Needed to verify vendor setup details.',
                    'priority' => 'required',
                    'status' => 'requested',
                ]],
            ],
        ]);

        $evidence = InvestigationEvidenceItem::create([
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'evidence_type' => InvestigationEvidenceItem::TYPE_RECOMMENDATION,
            'evidence_reference_id' => $recommendation->id,
            'title' => 'Case recommendation evidence',
            'summary' => 'Recommendation opened this investigation.',
            'source' => 'system:case_recommendation',
            'added_by_actor_type' => InvestigationEvidenceItem::ACTOR_SYSTEM,
            'added_by_actor_id' => null,
            'metadata' => [
                'citation_label' => 'Case recommendation',
                'source_type' => 'case_recommendation',
            ],
        ]);

        InvestigationActivityEvent::create([
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'event_type' => InvestigationActivityEvent::EVENT_STATUS_CHANGED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
            'event_summary' => 'Investigation moved into review.',
            'event_metadata' => [
                'previous_status' => 'open',
                'next_status' => 'in_review',
            ],
        ]);

        $export = InvestigationReportExport::create([
            'audit_case_id' => $case->id,
            'company_id' => $company->id,
            'generated_by_user_id' => $user->id,
            'format' => InvestigationReportExport::FORMAT_JSON,
            'filename' => 'investigation-package.json',
            'report_hash' => str_repeat('b', 64),
            'generated_at' => now(),
            'metadata' => [
                'finding_count' => 1,
                'evidence_item_count' => 1,
                'activity_event_count' => 1,
                'suggested_record_count' => 1,
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/investigations/{$case->id}/contract");

        $response->assertOk()
            ->assertJsonPath('contractVersion', '2026-06-12')
            ->assertJsonPath('investigation.id', $case->id)
            ->assertJsonPath('investigation.category', 'vendor_payments')
            ->assertJsonPath('investigation.status', 'in_review')
            ->assertJsonPath('investigation.priority', 'high')
            ->assertJsonPath('investigation.reviewPeriod.label', 'May 2026')
            ->assertJsonPath('findings.0.id', "finding:case_recommendation:{$recommendation->id}")
            ->assertJsonPath('findings.0.sourceModule', 'case_recommendations')
            ->assertJsonPath('findings.0.severity', 'warning')
            ->assertJsonPath('findings.0.confidence', 'high')
            ->assertJsonPath('findings.0.evidenceRefs.0.id', $evidence->id)
            ->assertJsonPath('evidenceItems.0.evidenceType', 'recommendation')
            ->assertJsonPath('suggestedRecords.0.id', 'suggested-record-1')
            ->assertJsonPath('suggestedRecords.0.priority', 'required')
            ->assertJsonPath('reviewEvents.0.eventType', InvestigationActivityEvent::EVENT_STATUS_CHANGED)
            ->assertJsonPath('reviewEvents.0.previousStatus', 'open')
            ->assertJsonPath('reviewEvents.0.nextStatus', 'in_review')
            ->assertJsonPath('casePackages.0.id', $export->id)
            ->assertJsonPath('casePackages.0.packageHash', str_repeat('b', 64));
    }

    public function test_contract_subresources_are_authenticated_and_company_scoped(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $otherCase = $this->createCase($otherCompany->id, $otherUser->id);

        $this->getJson("/api/investigations/{$otherCase->id}/findings")
            ->assertUnauthorized();

        Sanctum::actingAs($user);

        $this->getJson("/api/investigations/{$otherCase->id}/findings")
            ->assertNotFound();

        $case = $this->createCase($company->id, $user->id);

        $this->getJson("/api/investigations/{$case->id}/findings")
            ->assertOk()
            ->assertJsonPath('contractVersion', '2026-06-12')
            ->assertJsonPath('investigationId', $case->id)
            ->assertJsonPath('findings', []);

        $this->getJson("/api/investigations/{$case->id}/suggested-records")
            ->assertOk()
            ->assertJsonPath('suggestedRecords', []);

        $this->getJson("/api/investigations/{$case->id}/activity")
            ->assertOk()
            ->assertJsonPath('reviewEvents', []);

        $this->getJson("/api/investigations/{$case->id}/packages")
            ->assertOk()
            ->assertJsonPath('casePackages', []);
    }

    /** @return array{0: Company, 1: User} */
    private function createCompanyUser(): array
    {
        $company = new Company(['name' => 'Contract Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.test',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Contract',
            'last_name' => 'Reviewer',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }

    private function createCaseRecommendation(string $companyId): CaseRecommendation
    {
        return CaseRecommendation::create([
            'company_id' => $companyId,
            'case_type' => 'vendor_payment_reconciliation_investigation',
            'severity' => 'high',
            'title' => 'Investigate vendor and reconciliation risk signals',
            'summary' => 'Deterministic scoring found elevated vendor payment and reconciliation indicators.',
            'source_risk_domains' => ['vendor_risk', 'reconciliation_risk'],
            'related_alert_recommendation_ids' => [],
            'evidence' => ['domain_scores' => ['vendor_risk' => 75]],
            'confidence_score' => 0.9,
            'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCase(string $companyId, string $userId, array $overrides = []): AuditCase
    {
        $case = new AuditCase(array_merge([
            'company_id' => $companyId,
            'title' => 'Contract investigation',
            'description' => 'Investigation opened for contract testing.',
            'status' => 'open',
            'severity' => 'warning',
            'investigation_status' => AuditCase::INVESTIGATION_STATUS_OPEN,
            'investigation_priority' => AuditCase::INVESTIGATION_PRIORITY_MEDIUM,
            'created_by' => $userId,
        ], $overrides));
        $case->id = (string) Str::uuid();
        $case->save();

        return $case;
    }

    private function createSchema(): void
    {
        foreach ([
            'investigation_report_exports',
            'investigation_evidence_items',
            'investigation_activity_events',
            'audit_cases',
            'case_recommendations',
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
    }
}
