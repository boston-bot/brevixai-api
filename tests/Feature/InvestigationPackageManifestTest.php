<?php

namespace Tests\Feature;

use App\Models\AuditCase;
use App\Models\CaseRecommendation;
use App\Models\Company;
use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use App\Models\InvestigationReportExport;
use App\Models\User;
use App\Services\InvestigationPackageManifestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestigationPackageManifestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_authorized_user_can_generate_manifest(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id, [
            'investigation_notes' => 'Analyst note body should be represented by reference only.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'manifest' => [
                    'investigation_id',
                    'generated_at',
                    'generated_by_user_id',
                    'included_sections',
                    'included_counts',
                    'report_exports',
                    'evidence_items',
                    'linked_alerts',
                    'linked_recommendations',
                    'activity_events',
                    'notes',
                    'disclaimer',
                ],
            ])
            ->assertJsonPath('manifest.investigation_id', $case->id)
            ->assertJsonPath('manifest.generated_by_user_id', $user->id)
            ->assertJsonPath('manifest.included_counts.notes', 1)
            ->assertJsonPath('manifest.notes.0.reference', 'audit_cases.investigation_notes');
    }

    public function test_unauthenticated_user_cannot_generate_manifest(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ])->assertUnauthorized();
    }

    public function test_user_cannot_generate_manifest_for_other_company_investigation(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany, $otherUser] = $this->createCompanyUser();
        $case = $this->createCase($otherCompany->id, $otherUser->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ])->assertNotFound();
    }

    public function test_manifest_includes_report_export_references(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $export = $this->createReportExport($case->id, $company->id, $user->id, [
            'format' => InvestigationReportExport::FORMAT_JSON,
            'report_hash' => str_repeat('a', 64),
            'metadata' => [
                'evidence_item_count' => 2,
                'activity_event_count' => 3,
                'raw_payload' => 'secret-report-metadata',
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'manifest.report_exports')
            ->assertJsonPath('manifest.report_exports.0.id', $export->id)
            ->assertJsonPath('manifest.report_exports.0.format', InvestigationReportExport::FORMAT_JSON)
            ->assertJsonPath('manifest.report_exports.0.report_hash', str_repeat('a', 64))
            ->assertJsonPath('manifest.report_exports.0.evidence_item_count', 2)
            ->assertJsonPath('manifest.report_exports.0.activity_event_count', 3)
            ->assertJsonMissingPath('manifest.report_exports.0.metadata');

        $this->assertStringNotContainsString('secret-report-metadata', json_encode($response->json('manifest')));
    }

    public function test_manifest_includes_evidence_item_references(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $item = $this->createEvidenceItem($case->id, $company->id, [
            'evidence_type' => InvestigationEvidenceItem::TYPE_TRANSACTION,
            'evidence_reference_id' => (string) Str::uuid(),
            'title' => 'Suspicious payment reference',
            'metadata' => [
                'safe_label' => 'visible',
                'raw_payload' => 'secret-evidence-payload',
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'manifest.evidence_items')
            ->assertJsonPath('manifest.evidence_items.0.id', $item->id)
            ->assertJsonPath('manifest.evidence_items.0.evidence_type', InvestigationEvidenceItem::TYPE_TRANSACTION)
            ->assertJsonPath('manifest.evidence_items.0.title', 'Suspicious payment reference')
            ->assertJsonMissingPath('manifest.evidence_items.0.metadata');

        $this->assertStringNotContainsString('secret-evidence-payload', json_encode($response->json('manifest')));
    }

    public function test_manifest_includes_activity_event_references(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $eventId = $this->createActivityEvent(
            $case->id,
            $company->id,
            InvestigationActivityEvent::EVENT_CASE_CREATED,
            InvestigationActivityEvent::ACTOR_USER,
            $user->id,
            'Case opened',
            ['raw_payload' => 'secret-event-payload'],
        );

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ]);

        $response->assertOk()
            ->assertJsonPath('manifest.activity_events.0.id', $eventId)
            ->assertJsonPath('manifest.activity_events.0.event_type', InvestigationActivityEvent::EVENT_CASE_CREATED)
            ->assertJsonMissingPath('manifest.activity_events.0.event_metadata');

        $this->assertStringNotContainsString('secret-event-payload', json_encode($response->json('manifest')));
    }

    public function test_manifest_includes_linked_alert_and_recommendation_references(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $alertRecommendationId = $this->createAlertRecommendation($company->id);
        $caseRecommendation = $this->createCaseRecommendation($company->id, [$alertRecommendationId]);
        $alertId = $this->createAlert($company->id, $alertRecommendationId);
        $case = $this->createCase($company->id, $user->id, [
            'case_recommendation_id' => $caseRecommendation->id,
            'alert_ids' => json_encode([$alertId]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'manifest.linked_alerts')
            ->assertJsonPath('manifest.linked_alerts.0.id', $alertId)
            ->assertJsonPath('manifest.linked_alerts.0.alert_recommendation_id', $alertRecommendationId)
            ->assertJsonCount(2, 'manifest.linked_recommendations')
            ->assertJsonPath('manifest.linked_recommendations.0.recommendation_type', 'case')
            ->assertJsonPath('manifest.linked_recommendations.1.recommendation_type', 'alert')
            ->assertJsonMissingPath('manifest.linked_recommendations.0.evidence')
            ->assertJsonMissingPath('manifest.linked_alerts.0.evidence');
    }

    public function test_manifest_includes_disclaimer(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ])->assertOk()
            ->assertJsonPath('manifest.disclaimer', InvestigationPackageManifestService::DISCLAIMER);
    }

    public function test_sensitive_metadata_and_payloads_are_excluded(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $alertRecommendationId = $this->createAlertRecommendation($company->id, [
            'evidence' => ['raw_payload' => 'secret-alert-recommendation-evidence'],
            'review_note' => 'secret-alert-review-note',
        ]);
        $caseRecommendation = $this->createCaseRecommendation($company->id, [$alertRecommendationId], [
            'evidence' => ['transaction_details' => 'secret-case-recommendation-evidence'],
            'review_note' => 'secret-case-review-note',
        ]);
        $alertId = $this->createAlert($company->id, $alertRecommendationId, [
            'detail' => 'secret-alert-detail',
            'evidence' => ['raw_payload' => 'secret-alert-evidence'],
        ]);
        $case = $this->createCase($company->id, $user->id, [
            'case_recommendation_id' => $caseRecommendation->id,
            'alert_ids' => json_encode([$alertId]),
            'investigation_notes' => 'secret-note-body',
        ]);

        $this->createReportExport($case->id, $company->id, $user->id, [
            'metadata' => ['raw_payload' => 'secret-export-metadata'],
        ]);
        $this->createEvidenceItem($case->id, $company->id, [
            'metadata' => ['transaction_details' => 'secret-evidence-metadata'],
        ]);
        $this->createActivityEvent(
            $case->id,
            $company->id,
            InvestigationActivityEvent::EVENT_CASE_CREATED,
            InvestigationActivityEvent::ACTOR_USER,
            $user->id,
            'Case opened',
            ['supporting_evidence' => 'secret-activity-metadata'],
        );

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('manifest.notes.0.content')
            ->assertJsonMissingPath('manifest.evidence_items.0.metadata')
            ->assertJsonMissingPath('manifest.activity_events.0.event_metadata')
            ->assertJsonMissingPath('manifest.report_exports.0.metadata');

        $encodedManifest = json_encode($response->json('manifest'));
        foreach ([
            'secret-alert-recommendation-evidence',
            'secret-alert-review-note',
            'secret-case-recommendation-evidence',
            'secret-case-review-note',
            'secret-alert-detail',
            'secret-alert-evidence',
            'secret-note-body',
            'secret-export-metadata',
            'secret-evidence-metadata',
            'secret-activity-metadata',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $encodedManifest);
        }
    }

    public function test_activity_event_recorded_when_manifest_generated(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/investigations/{$case->id}/package-manifest", [
            'format' => 'json',
        ])->assertOk();

        $this->assertDatabaseHas('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_PACKAGE_MANIFEST_GENERATED,
            'actor_type' => InvestigationActivityEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);

        $event = InvestigationActivityEvent::where('audit_case_id', $case->id)
            ->where('event_type', InvestigationActivityEvent::EVENT_PACKAGE_MANIFEST_GENERATED)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('json', $event->event_metadata['format'] ?? null);
    }

    public function test_agents_cannot_generate_manifest_via_service(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $case = $this->createCase($company->id, $user->id);
        $service = app(InvestigationPackageManifestService::class);

        try {
            $service->generate(
                companyId: $company->id,
                caseId: $case->id,
                actorType: InvestigationActivityEvent::ACTOR_AGENT,
                actorId: (string) Str::uuid(),
            );
            $this->fail('Agent package manifest generation should be blocked.');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Agents cannot generate investigation package manifests', $e->getMessage());
        }

        $this->assertDatabaseMissing('investigation_activity_events', [
            'audit_case_id' => $case->id,
            'event_type' => InvestigationActivityEvent::EVENT_PACKAGE_MANIFEST_GENERATED,
        ]);
    }

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
        $alertIds = $overrides['alert_ids'] ?? null;
        unset($overrides['alert_ids']);

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

        if ($alertIds !== null) {
            DB::table('audit_cases')
                ->where('id', $case->id)
                ->update(['alert_ids' => $alertIds]);
        }

        return $case;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReportExport(
        string $caseId,
        string $companyId,
        string $userId,
        array $overrides = [],
    ): InvestigationReportExport {
        return InvestigationReportExport::create(array_merge([
            'audit_case_id' => $caseId,
            'company_id' => $companyId,
            'generated_by_user_id' => $userId,
            'format' => InvestigationReportExport::FORMAT_JSON,
            'filename' => null,
            'report_hash' => str_repeat('b', 64),
            'generated_at' => now(),
            'metadata' => [
                'evidence_item_count' => 0,
                'activity_event_count' => 0,
            ],
        ], $overrides));
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
     * @param  array<string, mixed>|null  $eventMetadata
     */
    private function createActivityEvent(
        string $caseId,
        string $companyId,
        string $eventType,
        string $actorType,
        ?string $actorId,
        string $summary,
        ?array $eventMetadata = null,
    ): string {
        $eventId = (string) Str::uuid();

        DB::table('investigation_activity_events')->insert([
            'id' => $eventId,
            'audit_case_id' => $caseId,
            'company_id' => $companyId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_summary' => $summary,
            'event_metadata' => $eventMetadata ? json_encode($eventMetadata) : null,
            'created_at' => now()->subMinute(),
        ]);

        return $eventId;
    }

    /**
     * @param  array<int, string>  $relatedAlertRecommendationIds
     * @param  array<string, mixed>  $overrides
     */
    private function createCaseRecommendation(
        string $companyId,
        array $relatedAlertRecommendationIds = [],
        array $overrides = [],
    ): CaseRecommendation {
        $recommendation = new CaseRecommendation(array_merge([
            'company_id' => $companyId,
            'case_type' => 'vendor_risk',
            'severity' => 'high',
            'title' => 'Review vendor risk cluster',
            'summary' => 'Multiple linked alerts suggest vendor review is warranted.',
            'source_risk_domains' => ['vendor_risk'],
            'related_alert_recommendation_ids' => $relatedAlertRecommendationIds,
            'evidence' => ['vendor_risk' => ['count' => 2]],
            'confidence_score' => 0.87,
            'status' => CaseRecommendation::STATUS_APPROVED,
        ], $overrides));
        $recommendation->id = (string) Str::uuid();
        $recommendation->save();

        return $recommendation;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAlertRecommendation(string $companyId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('alert_recommendations')->insert(array_merge([
            'id' => $id,
            'company_id' => $companyId,
            'source_risk_domain' => 'vendor_risk',
            'alert_type' => 'shell_vendor',
            'severity' => 'warning',
            'title' => 'Review shell vendor indicators',
            'summary' => 'Vendor attributes match elevated risk signals.',
            'evidence' => json_encode(['vendor_risk' => ['count' => 1]]),
            'source_rule_ids' => json_encode(['vendor_shell_rule']),
            'confidence_score' => 0.76,
            'status' => 'approved',
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->encodeJsonColumns($overrides, ['evidence', 'source_rule_ids'])));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAlert(string $companyId, string $alertRecommendationId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('alerts')->insert(array_merge([
            'id' => $id,
            'company_id' => $companyId,
            'alert_recommendation_id' => $alertRecommendationId,
            'rule_key' => 'vendor_shell_rule',
            'severity' => 'warning',
            'title' => 'Shell vendor signal',
            'detail' => 'Raw alert detail should not be returned by manifest.',
            'evidence' => json_encode(['vendor' => 'Apex Consulting']),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->encodeJsonColumns($overrides, ['evidence'])));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $jsonColumns
     * @return array<string, mixed>
     */
    private function encodeJsonColumns(array $values, array $jsonColumns): array
    {
        foreach ($jsonColumns as $column) {
            if (array_key_exists($column, $values) && is_array($values[$column])) {
                $values[$column] = json_encode($values[$column]);
            }
        }

        return $values;
    }

    private function createSchema(): void
    {
        foreach ([
            'investigation_report_exports',
            'investigation_evidence_items',
            'investigation_activity_events',
            'alerts',
            'audit_cases',
            'case_recommendations',
            'alert_recommendations',
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
            $table->json('evidence')->nullable();
            $table->json('source_rule_ids')->nullable();
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
            $table->json('evidence')->nullable();
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
            $table->text('alert_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('alert_recommendation_id')->nullable();
            $table->text('rule_key')->nullable();
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->text('status')->default('open');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
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
