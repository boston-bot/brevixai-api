<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EvidenceItem;
use App\Models\Finding;
use App\Models\SuggestedRecord;
use App\Models\User;
use App\Services\InvestigationBackfillService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestigationPlatformCanonicalApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_canonical_investigation_lifecycle_accepts_frontend_aliases(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/investigations', [
            'title' => 'Review vendor payment spike',
            'category' => 'vendor',
            'priority' => 'warning',
            'scopeStatement' => 'Review May vendor payments for duplicate or suspicious activity.',
            'scopeLimitations' => ['Bank statements not yet uploaded.'],
        ], $this->profileHeaders($profileId));

        $created->assertCreated()
            ->assertJsonPath('investigation.category', 'vendor_payments')
            ->assertJsonPath('investigation.priority', 'medium')
            ->assertJsonPath('investigation.status', 'open')
            ->assertJsonPath('investigation.investigation_status', 'open')
            ->assertJsonPath('investigation.scopeLimitations.0', 'Bank statements not yet uploaded.');

        $investigationId = $created->json('investigation.id');

        $this->getJson('/api/investigations?status=open', $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('counts.open', 1)
            ->assertJsonPath('status_counts.open', 1)
            ->assertJsonPath('investigations.0.id', $investigationId);

        $this->postJson("/api/investigations/{$investigationId}/status", [
            'status' => 'in_progress',
        ], $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('investigation.status', 'in_progress')
            ->assertJsonPath('investigation.investigation_status', 'in_review');

        $this->postJson("/api/investigations/{$investigationId}/notes", [
            'body' => 'Reviewer confirmed the scope.',
        ], $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('note.body', 'Reviewer confirmed the scope.');

        $this->assertDatabaseHas('investigations', [
            'id' => $investigationId,
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'status' => 'in_review',
        ]);

        $this->getJson("/api/investigations/{$investigationId}", $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('investigation.notes.0.body', 'Reviewer confirmed the scope.')
            ->assertJsonPath('reviewer_notes.0.body', 'Reviewer confirmed the scope.');
    }

    public function test_findings_review_flow_opens_investigation_and_adds_reviewer_note(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        Sanctum::actingAs($user);

        $finding = Finding::create([
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'category' => 'reconciliation',
            'source_module' => 'reconciliation',
            'source_record_type' => 'discrepancy',
            'source_record_id' => 'disc-001',
            'title' => 'Ledger and bank mismatch',
            'summary' => 'Bank activity exceeds ledger by $1,250.',
            'severity' => 'critical',
            'confidence' => 'high',
            'confidence_score' => 0.9400,
            'status' => 'new',
            'reviewer_status' => 'pending',
            'evidence_refs' => [],
            'metadata' => [],
        ]);

        $this->getJson('/api/findings', $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('findings.0.sourceModule', 'reconciliation')
            ->assertJsonPath('findings.0.sourceRecordId', 'disc-001');

        $opened = $this->postJson("/api/findings/{$finding->id}/create-investigation", [], $this->profileHeaders($profileId));

        $opened->assertCreated()
            ->assertJsonPath('investigation.title', 'Ledger and bank mismatch')
            ->assertJsonPath('investigation.category', 'reconciliation')
            ->assertJsonPath('investigation.priority', 'high');

        $investigationId = $opened->json('investigation.id');

        $this->postJson("/api/findings/{$finding->id}/review", [
            'status' => 'reviewed',
            'note' => 'Included in the reconciliation investigation.',
        ], $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('finding.status', 'reviewed')
            ->assertJsonPath('finding.reviewerStatus', 'reviewed');

        $this->assertDatabaseHas('findings', [
            'id' => $finding->id,
            'investigation_id' => $investigationId,
            'status' => 'reviewed',
            'reviewer_status' => 'reviewed',
        ]);
        $this->assertDatabaseHas('reviewer_notes', [
            'investigation_id' => $investigationId,
            'finding_id' => $finding->id,
            'body' => 'Included in the reconciliation investigation.',
        ]);
        $this->assertDatabaseHas('review_events', [
            'investigation_id' => $investigationId,
            'finding_id' => $finding->id,
            'event_type' => 'finding_reviewed',
        ]);
    }

    public function test_generates_persisted_json_case_package_with_contract_aliases(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/investigations', [
            'title' => 'Package-ready tax notice review',
            'category' => 'tax_notice',
            'priority' => 'high',
            'scopeStatement' => 'Prepare reviewer package for the notice response.',
        ], $this->profileHeaders($profileId))->assertCreated();

        $investigationId = $created->json('investigation.id');

        $finding = Finding::create([
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'investigation_id' => $investigationId,
            'category' => 'tax',
            'source_module' => 'tax_notices',
            'source_record_type' => 'notice_interpretation',
            'source_record_id' => 'notice-001',
            'title' => 'Notice response deadline identified',
            'summary' => 'The notice appears to require response within 30 days.',
            'severity' => 'warning',
            'confidence' => 'medium',
            'status' => 'reviewed',
            'reviewer_status' => 'reviewed',
            'evidence_refs' => [],
            'metadata' => [],
        ]);

        EvidenceItem::create([
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'investigation_id' => $investigationId,
            'finding_id' => $finding->id,
            'evidence_type' => 'document',
            'source_type' => 'upload',
            'source_id' => 'upload-001',
            'source_record_id' => 'notice.pdf',
            'title' => 'IRS notice upload',
            'summary' => 'Uploaded notice document.',
            'citation_label' => 'Notice PDF',
            'added_by_actor_type' => 'user',
            'added_by_actor_id' => $user->id,
            'metadata' => [],
        ]);

        SuggestedRecord::create([
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'investigation_id' => $investigationId,
            'finding_id' => $finding->id,
            'record_type' => 'tax_payment_confirmation',
            'label' => 'Tax payment confirmation',
            'reason' => 'Needed to support the response package.',
            'priority' => 'required',
            'status' => 'requested',
            'metadata' => [],
        ]);

        $response = $this->postJson("/api/investigations/{$investigationId}/packages", [
            'format' => 'json',
        ], $this->profileHeaders($profileId));

        $response->assertCreated()
            ->assertJsonPath('package.format', 'json')
            ->assertJsonPath('package.status', 'completed')
            ->assertJsonPath('package.included_counts.findings', 1)
            ->assertJsonPath('package.includedCounts.evidence_items', 1)
            ->assertJsonPath('package.manifest.included_counts.suggested_records', 1)
            ->assertJsonPath('package.manifest.disclaimer', \App\Support\ProfessionalServicesDisclaimer::TEXT);

        $this->assertNotEmpty($response->json('package.package_hash'));
        $this->assertSame($response->json('package.package_hash'), $response->json('package.packageHash'));
        $this->assertDatabaseHas('case_packages', [
            'investigation_id' => $investigationId,
            'format' => 'json',
            'status' => 'completed',
        ]);

        $this->getJson("/api/investigations/{$investigationId}/packages", $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('packages.0.packageHash', $response->json('package.packageHash'));
    }

    public function test_backfill_migrates_legacy_case_records_into_canonical_tables(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();

        $recommendationId = (string) Str::uuid();
        DB::table('case_recommendations')->insert([
            'id' => $recommendationId,
            'company_id' => $company->id,
            'case_type' => 'vendor_payment_reconciliation_investigation',
            'severity' => 'high',
            'title' => 'Investigate vendor payment risk',
            'summary' => 'Recommendation summary.',
            'source_risk_domains' => json_encode(['vendor_risk']),
            'related_alert_recommendation_ids' => json_encode([]),
            'evidence' => json_encode(['score' => 91]),
            'confidence_score' => 0.9100,
            'status' => 'pending_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caseId = (string) Str::uuid();
        DB::table('audit_cases')->insert([
            'id' => $caseId,
            'company_id' => $company->id,
            'case_recommendation_id' => $recommendationId,
            'title' => 'Legacy vendor case',
            'description' => 'Legacy case description.',
            'status' => 'open',
            'severity' => 'high',
            'investigation_status' => 'in_review',
            'investigation_priority' => 'high',
            'investigation_summary' => 'Legacy investigation summary.',
            'investigation_notes' => null,
            'investigation_metadata' => json_encode([
                'category' => 'vendor_payments',
                'scope_limitations' => ['Legacy limitation.'],
            ]),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
            'last_activity_at' => now(),
        ]);

        $legacyEvidenceId = (string) Str::uuid();
        DB::table('investigation_evidence_items')->insert([
            'id' => $legacyEvidenceId,
            'audit_case_id' => $caseId,
            'company_id' => $company->id,
            'evidence_type' => 'recommendation',
            'evidence_reference_id' => $recommendationId,
            'title' => 'Legacy recommendation evidence',
            'summary' => 'Legacy evidence summary.',
            'source' => 'system:case_recommendation',
            'added_by_actor_type' => 'system',
            'added_by_actor_id' => null,
            'metadata' => json_encode(['citation_label' => 'Legacy recommendation']),
            'created_at' => now(),
        ]);

        DB::table('investigation_activity_events')->insert([
            'id' => (string) Str::uuid(),
            'audit_case_id' => $caseId,
            'company_id' => $company->id,
            'event_type' => 'status_changed',
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'event_summary' => 'Legacy status changed.',
            'event_metadata' => json_encode(['previous_status' => 'open', 'next_status' => 'in_review']),
            'created_at' => now(),
        ]);

        DB::table('investigation_report_exports')->insert([
            'id' => (string) Str::uuid(),
            'audit_case_id' => $caseId,
            'company_id' => $company->id,
            'generated_by_user_id' => $user->id,
            'format' => 'json',
            'filename' => 'legacy-package.json',
            'storage_key' => null,
            'report_hash' => str_repeat('c', 64),
            'metadata' => json_encode(['finding_count' => 1]),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(InvestigationBackfillService::class)->run(companyId: $company->id);

        $this->assertSame(1, $result['investigations']);
        $this->assertSame(1, $result['findings']);
        $this->assertSame(1, $result['evidence_items']);
        $this->assertSame(1, $result['review_events']);
        $this->assertSame(1, $result['case_packages']);

        $investigationId = DB::table('investigations')->where('legacy_audit_case_id', $caseId)->value('id');
        $this->assertNotNull($investigationId);
        $this->assertDatabaseHas('investigations', [
            'id' => $investigationId,
            'business_profile_id' => $profileId,
            'category' => 'vendor_payments',
            'status' => 'in_review',
            'priority' => 'high',
        ]);
        $this->assertDatabaseHas('findings', [
            'investigation_id' => $investigationId,
            'source_module' => 'case_recommendations',
            'source_record_id' => $recommendationId,
        ]);
        $this->assertDatabaseHas('evidence_items', [
            'investigation_id' => $investigationId,
            'legacy_evidence_item_id' => $legacyEvidenceId,
        ]);
        $this->assertDatabaseHas('review_events', [
            'investigation_id' => $investigationId,
            'event_type' => 'status_changed',
        ]);
        $this->assertDatabaseHas('case_packages', [
            'investigation_id' => $investigationId,
            'package_hash' => str_repeat('c', 64),
        ]);

        app(InvestigationBackfillService::class)->run(companyId: $company->id);
        $this->assertSame(1, DB::table('investigations')->where('legacy_audit_case_id', $caseId)->count());
        $this->assertSame(1, DB::table('findings')->where('source_record_id', $recommendationId)->count());
        $this->assertSame(1, DB::table('evidence_items')->where('legacy_evidence_item_id', $legacyEvidenceId)->count());
        $this->assertSame(1, DB::table('case_packages')->where('package_hash', str_repeat('c', 64))->count());
    }

    /** @return array{0: Company, 1: User, 2: string} */
    private function createWorkspace(): array
    {
        $company = new Company(['name' => 'Canonical API Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.test',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Case',
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

    /** @return array<string, string> */
    private function profileHeaders(string $profileId): array
    {
        return ['X-Brevix-Business-Profile-Id' => $profileId];
    }

    private function createSchema(): void
    {
        foreach ([
            'investigation_report_exports',
            'investigation_evidence_items',
            'investigation_activity_events',
            'audit_cases',
            'case_recommendations',
            'case_packages',
            'review_events',
            'reviewer_notes',
            'suggested_records',
            'evidence_item_finding',
            'evidence_items',
            'findings',
            'investigations',
            'personal_access_tokens',
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
            $table->uuid('company_id');
            $table->text('case_type');
            $table->text('severity');
            $table->text('title');
            $table->text('summary');
            $table->json('source_risk_domains');
            $table->json('related_alert_recommendation_ids');
            $table->json('evidence');
            $table->decimal('confidence_score', 5, 4)->default(0);
            $table->text('status')->default('pending_review');
            $table->timestamps();
        });

        Schema::create('audit_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('case_recommendation_id')->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('status')->default('open');
            $table->text('severity')->default('warning');
            $table->text('investigation_status')->default('open');
            $table->uuid('investigation_assigned_user_id')->nullable();
            $table->text('investigation_priority')->default('medium');
            $table->text('investigation_summary')->nullable();
            $table->text('investigation_notes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('investigation_metadata')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('investigation_activity_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('audit_case_id');
            $table->uuid('company_id');
            $table->text('event_type');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->text('event_summary');
            $table->json('event_metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('investigation_evidence_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('audit_case_id');
            $table->uuid('company_id');
            $table->text('evidence_type');
            $table->uuid('evidence_reference_id')->nullable();
            $table->text('title');
            $table->text('summary');
            $table->text('source');
            $table->text('added_by_actor_type');
            $table->uuid('added_by_actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('investigation_report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('audit_case_id');
            $table->uuid('company_id');
            $table->uuid('generated_by_user_id')->nullable();
            $table->text('format');
            $table->text('filename')->nullable();
            $table->text('storage_key')->nullable();
            $table->text('report_hash')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('generated_at')->nullable();
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
            $table->uuid('investigation_id');
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
            $table->uuid('investigation_id');
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
    }
}
