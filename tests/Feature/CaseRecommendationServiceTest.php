<?php

namespace Tests\Feature;

use App\Models\CaseRecommendation;
use App\Models\Company;
use App\Models\RecommendationReviewEvent;
use App\Models\User;
use App\Services\Agents\AggregateRiskSummaryService;
use App\Services\Agents\AlertRecommendationService;
use App\Services\Agents\CaseRecommendationService;
use App\Services\Agents\EntityRelationshipRiskScoringService;
use App\Services\Agents\ReconciliationRiskScoringService;
use App\Services\Agents\VendorRiskScoringService;
use App\Services\CaseRecommendationReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CaseRecommendationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-agent-key']);
        $this->createSchema();
    }

    public function test_no_case_recommendation_for_low_risk(): void
    {
        [$company] = $this->createCompanyUser();
        $this->bindRiskSources($this->lowRiskSources());

        $result = app(CaseRecommendationService::class)->getCaseRecommendations($company->id);

        $this->assertSame($company->id, $result['company_id']);
        $this->assertSame([], $result['case_recommendations']);
        $this->assertDatabaseCount('case_recommendations', 0);
        $this->assertDatabaseCount('audit_cases', 0);
    }

    public function test_case_recommendation_for_multi_domain_high_risk(): void
    {
        [$company] = $this->createCompanyUser();
        $this->bindRiskSources($this->multiDomainHighRiskSources());

        $result = app(CaseRecommendationService::class)->getCaseRecommendations($company->id);

        $this->assertCount(1, $result['case_recommendations']);

        $recommendation = $result['case_recommendations'][0];
        $this->assertSame('vendor_payment_reconciliation_investigation', $recommendation['case_type']);
        $this->assertSame('high', $recommendation['severity']);
        $this->assertSame([
            'vendor_risk',
            'reconciliation_risk',
        ], $recommendation['source_risk_domains']);
        $this->assertCount(2, $recommendation['related_alert_recommendation_ids']);
        $this->assertTrue($recommendation['requires_human_review']);
        $this->assertFalse($recommendation['can_auto_create']);
        $this->assertSame('pending_review', $recommendation['status']);
        $this->assertDatabaseCount('case_recommendations', 1);
        $this->assertDatabaseCount('audit_cases', 0);
    }

    public function test_approval_creates_case(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/case-recommendations/{$recommendation->id}/approve");

        $response
            ->assertOk()
            ->assertJsonPath('recommendation.status', CaseRecommendation::STATUS_APPROVED)
            ->assertJsonPath('case.case_recommendation_id', $recommendation->id)
            ->assertJsonPath('case.status', 'open')
            ->assertJsonPath('case.title', 'Investigate vendor and reconciliation risk signals');

        $this->assertTrue(Str::isUuid((string) $response->json('recommendation.case_id')));
        $this->assertDatabaseCount('audit_cases', 1);
        $this->assertDatabaseHas('audit_cases', [
            'company_id' => $company->id,
            'case_recommendation_id' => $recommendation->id,
            'created_by' => $user->id,
        ]);
        $this->assertDatabaseHas('case_recommendations', [
            'id' => $recommendation->id,
            'status' => CaseRecommendation::STATUS_APPROVED,
            'reviewed_by_user_id' => $user->id,
        ]);
    }

    public function test_approval_is_scoped_to_active_business_profile(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->createBusinessProfileSchema();
        $profileA = $this->createBusinessProfile($company->id, 'Profile A');
        $profileB = $this->createBusinessProfile($company->id, 'Profile B');
        $recommendation = $this->createCaseRecommendation($company->id, [
            'business_profile_id' => $profileB,
        ]);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/case-recommendations/{$recommendation->id}/approve",
            [],
            ['X-Brevix-Business-Profile-Id' => $profileA],
        )->assertNotFound();

        $this->assertDatabaseCount('audit_cases', 0);
        $this->assertDatabaseHas('case_recommendations', [
            'id' => $recommendation->id,
            'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
        ]);

        $response = $this->postJson(
            "/api/case-recommendations/{$recommendation->id}/approve",
            [],
            ['X-Brevix-Business-Profile-Id' => $profileB],
        );

        $response->assertOk()
            ->assertJsonPath('case.business_profile_id', $profileB)
            ->assertJsonPath('recommendation.business_profile_id', $profileB);

        $this->assertDatabaseHas('audit_cases', [
            'company_id' => $company->id,
            'business_profile_id' => $profileB,
            'case_recommendation_id' => $recommendation->id,
        ]);
        $this->assertDatabaseHas('audit_case_events', [
            'company_id' => $company->id,
            'business_profile_id' => $profileB,
            'case_id' => $response->json('case.id'),
        ]);
    }

    public function test_dismissal_does_not_create_case(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/case-recommendations/{$recommendation->id}/dismiss", [
            'review_note' => 'Known issue already being handled.',
        ])
            ->assertOk()
            ->assertJsonPath('recommendation.status', CaseRecommendation::STATUS_DISMISSED)
            ->assertJsonPath('recommendation.case_id', null);

        $this->assertDatabaseCount('audit_cases', 0);
        $this->assertDatabaseHas('case_recommendations', [
            'id' => $recommendation->id,
            'status' => CaseRecommendation::STATUS_DISMISSED,
            'reviewed_by_user_id' => $user->id,
            'review_note' => 'Known issue already being handled.',
        ]);
    }

    public function test_user_recommendation_endpoints_require_authentication(): void
    {
        [$company] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        $this->getJson('/api/case-recommendations')->assertUnauthorized();
        $this->getJson("/api/case-recommendations/{$recommendation->id}")->assertUnauthorized();
        $this->postJson("/api/case-recommendations/{$recommendation->id}/approve")->assertUnauthorized();
        $this->postJson("/api/case-recommendations/{$recommendation->id}/dismiss")->assertUnauthorized();
    }

    public function test_unauthorized_company_access_rejected(): void
    {
        [, $user] = $this->createCompanyUser();
        [$otherCompany] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($otherCompany->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/case-recommendations/{$recommendation->id}")
            ->assertNotFound();

        $this->postJson("/api/case-recommendations/{$recommendation->id}/approve")
            ->assertNotFound();

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/company/{$otherCompany->id}/case-recommendations")
            ->assertForbidden();

        $this->assertDatabaseCount('audit_cases', 0);
    }

    public function test_internal_agent_endpoint_returns_recommendations_without_case_creation(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->bindRiskSources($this->multiDomainHighRiskSources());

        $this->getJson("/api/internal/agent-tools/company/{$company->id}/case-recommendations")
            ->assertUnauthorized();

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/company/{$company->id}/case-recommendations")
            ->assertOk()
            ->assertJsonPath('company_id', $company->id)
            ->assertJsonCount(1, 'case_recommendations')
            ->assertJsonPath('case_recommendations.0.requires_human_review', true)
            ->assertJsonPath('case_recommendations.0.can_auto_create', false);

        $this->assertDatabaseCount('case_recommendations', 1);
        $this->assertDatabaseCount('audit_cases', 0);
    }

    public function test_agent_actor_cannot_approve_or_dismiss_via_service(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $approvalAttempt = $this->createCaseRecommendation($company->id);
        $dismissalAttempt = $this->createCaseRecommendation($company->id);
        $service = app(CaseRecommendationReviewService::class);

        try {
            $service->approve(
                companyId: $company->id,
                userId: $user->id,
                recommendationId: $approvalAttempt->id,
                actorType: RecommendationReviewEvent::ACTOR_AGENT,
            );
            $this->fail('Agent actor should not approve case recommendations.');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Agents cannot approve or dismiss case recommendations', $e->getMessage());
        }

        try {
            $service->dismiss(
                companyId: $company->id,
                userId: $user->id,
                recommendationId: $dismissalAttempt->id,
                actorType: RecommendationReviewEvent::ACTOR_AGENT,
            );
            $this->fail('Agent actor should not dismiss case recommendations.');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Agents cannot approve or dismiss case recommendations', $e->getMessage());
        }

        $this->assertDatabaseHas('case_recommendations', [
            'id' => $approvalAttempt->id,
            'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseHas('case_recommendations', [
            'id' => $dismissalAttempt->id,
            'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseCount('audit_cases', 0);
    }

    public function test_reviewer_must_belong_to_company_when_service_creates_case(): void
    {
        [$company] = $this->createCompanyUser();
        [, $otherUser] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);
        $service = app(CaseRecommendationReviewService::class);

        try {
            $service->approve(
                companyId: $company->id,
                userId: $otherUser->id,
                recommendationId: $recommendation->id,
            );
            $this->fail('Cross-company reviewer should not approve case recommendations.');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Reviewer is not authorized for this company', $e->getMessage());
        }

        $this->assertDatabaseHas('case_recommendations', [
            'id' => $recommendation->id,
            'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseCount('audit_cases', 0);
    }

    public function test_double_approval_blocked(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/case-recommendations/{$recommendation->id}/approve")
            ->assertOk();

        $this->postJson("/api/case-recommendations/{$recommendation->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('current_status', CaseRecommendation::STATUS_APPROVED);

        $this->assertDatabaseCount('audit_cases', 1);
    }

    public function test_recommendations_always_require_human_review(): void
    {
        [$company] = $this->createCompanyUser();
        $this->bindRiskSources($this->multiDomainHighRiskSources());

        $recommendation = app(CaseRecommendationService::class)->getCaseRecommendations($company->id)['case_recommendations'][0];

        $this->assertTrue($recommendation['requires_human_review']);
        $this->assertDatabaseHas('case_recommendations', [
            'id' => $recommendation['id'],
            'requires_human_review' => true,
        ]);
    }

    public function test_can_auto_create_always_false(): void
    {
        [$company] = $this->createCompanyUser();
        $this->bindRiskSources($this->multiDomainHighRiskSources());

        $recommendation = app(CaseRecommendationService::class)->getCaseRecommendations($company->id)['case_recommendations'][0];

        $this->assertFalse($recommendation['can_auto_create']);
        $this->assertDatabaseHas('case_recommendations', [
            'id' => $recommendation['id'],
            'can_auto_create' => false,
        ]);
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        $company = new Company([
            'name' => 'Brevix Test Co',
        ]);
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
    private function createCaseRecommendation(string $companyId, array $overrides = []): CaseRecommendation
    {
        return CaseRecommendation::create(array_merge([
            'company_id' => $companyId,
            'case_type' => 'vendor_payment_reconciliation_investigation',
            'severity' => 'high',
            'title' => 'Investigate vendor and reconciliation risk signals',
            'summary' => 'Deterministic scoring found elevated signals across vendor risk and reconciliation risk.',
            'source_risk_domains' => ['vendor_risk', 'reconciliation_risk'],
            'related_alert_recommendation_ids' => [
                (string) Str::uuid(),
                (string) Str::uuid(),
            ],
            'evidence' => [
                'domain_scores' => [
                    'vendor_risk' => 75,
                    'reconciliation_risk' => 45,
                ],
            ],
            'confidence_score' => 0.9,
            'requires_human_review' => false,
            'can_auto_create' => true,
            'status' => CaseRecommendation::STATUS_PENDING_REVIEW,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    private function bindRiskSources(array $sources): void
    {
        $this->app->instance(VendorRiskScoringService::class, new class($sources['vendor_scores']) extends VendorRiskScoringService
        {
            public function __construct(private readonly array $scores) {}

            public function scoreAllVendors(string $companyId, ?string $businessProfileId = null): array
            {
                return $this->scores;
            }
        });

        $this->app->instance(ReconciliationRiskScoringService::class, new class($sources['reconciliation_risk']) extends ReconciliationRiskScoringService
        {
            public function __construct(private readonly array $risk) {}

            public function scoreReconciliation(string $companyId, ?string $businessProfileId = null): array
            {
                return array_merge(['company_id' => $companyId], $this->risk);
            }
        });

        $this->app->instance(EntityRelationshipRiskScoringService::class, new class($sources['entity_relationship_risk']) extends EntityRelationshipRiskScoringService
        {
            public function __construct(private readonly array $risk) {}

            public function scoreEntityRelationships(string $companyId, ?string $businessProfileId = null): array
            {
                return array_merge(['company_id' => $companyId], $this->risk);
            }
        });

        $this->app->instance(AggregateRiskSummaryService::class, new class($sources['aggregate_summary']) extends AggregateRiskSummaryService
        {
            public function __construct(private readonly array $summary) {}

            public function getAggregateRiskSummary(string $companyId, ?string $businessProfileId = null): array
            {
                return array_merge(['company_id' => $companyId], $this->summary);
            }
        });

        $this->app->forgetInstance(AlertRecommendationService::class);
        $this->app->forgetInstance(CaseRecommendationService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function lowRiskSources(): array
    {
        return [
            'vendor_scores' => [],
            'reconciliation_risk' => [
                'reconciliation_risk_score' => 0,
                'risk_level' => 'low',
                'triggered_rules' => [],
                'supporting_evidence' => [],
            ],
            'entity_relationship_risk' => [
                'entity_relationship_risk_score' => 0,
                'risk_level' => 'low',
                'triggered_rules' => [],
                'supporting_evidence' => [],
                'related_entities' => [],
            ],
            'aggregate_summary' => [
                'overall_risk_score' => 0,
                'overall_risk_level' => 'low',
                'contributing_risk_domains' => [
                    'vendor_risk' => ['score' => 0, 'risk_level' => 'low'],
                    'reconciliation_risk' => ['score' => 0, 'risk_level' => 'low'],
                    'entity_relationship_risk' => ['score' => 0, 'risk_level' => 'low'],
                ],
                'highest_risk_findings' => [],
                'triggered_rules_summary' => [
                    'vendor_risk' => 0,
                    'reconciliation_risk' => 0,
                    'entity_relationship_risk' => 0,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function multiDomainHighRiskSources(): array
    {
        return [
            'vendor_scores' => [[
                'vendor_name' => 'Northstar Consulting',
                'vendor_risk_score' => 75,
                'risk_level' => 'high',
                'triggered_rules' => [
                    [
                        'rule_key' => 'threshold_splitting',
                        'name' => 'Threshold Splitting Behavior',
                        'weight' => 20,
                        'explanation' => 'Multiple payments were just below the approval threshold.',
                    ],
                    [
                        'rule_key' => 'rapid_payment',
                        'name' => 'Rapid Payment after Onboarding',
                        'weight' => 15,
                        'explanation' => 'High-value payment was made shortly after onboarding.',
                    ],
                ],
                'supporting_evidence' => [
                    'threshold_splitting' => [
                        'split_transaction_groups' => [[
                            ['id' => (string) Str::uuid(), 'amount' => 4500.00, 'date' => '2026-05-11'],
                            ['id' => (string) Str::uuid(), 'amount' => 4800.00, 'date' => '2026-05-12'],
                        ]],
                    ],
                ],
            ]],
            'reconciliation_risk' => [
                'reconciliation_risk_score' => 45,
                'risk_level' => 'medium',
                'triggered_rules' => [
                    [
                        'rule_key' => 'bank_ledger_mismatch',
                        'name' => 'Bank-to-Ledger Mismatches',
                        'weight' => 15,
                        'explanation' => 'Bank-side transaction has no matching ledger entry.',
                    ],
                    [
                        'rule_key' => 'unmatched_withdrawals',
                        'name' => 'Unmatched Withdrawals',
                        'weight' => 20,
                        'explanation' => 'Withdrawal could not be matched to ledger entries.',
                    ],
                ],
                'supporting_evidence' => [
                    'bank_ledger_mismatch' => [
                        'discrepancies' => [
                            ['id' => (string) Str::uuid(), 'amount' => 1200.00, 'status' => 'new'],
                        ],
                    ],
                ],
            ],
            'entity_relationship_risk' => [
                'entity_relationship_risk_score' => 0,
                'risk_level' => 'low',
                'triggered_rules' => [],
                'supporting_evidence' => [],
                'related_entities' => [],
            ],
            'aggregate_summary' => [
                'overall_risk_score' => 75,
                'overall_risk_level' => 'high',
                'contributing_risk_domains' => [
                    'vendor_risk' => ['score' => 75, 'risk_level' => 'high'],
                    'reconciliation_risk' => ['score' => 45, 'risk_level' => 'medium'],
                    'entity_relationship_risk' => ['score' => 0, 'risk_level' => 'low'],
                ],
                'highest_risk_findings' => [
                    [
                        'domain' => 'vendor_risk',
                        'source' => 'Northstar Consulting',
                        'rule_key' => 'threshold_splitting',
                        'name' => 'Threshold Splitting Behavior',
                        'weight' => 20,
                        'explanation' => 'Multiple payments were just below the approval threshold.',
                    ],
                    [
                        'domain' => 'reconciliation_risk',
                        'source' => 'General Ledger / Bank Statement',
                        'rule_key' => 'unmatched_withdrawals',
                        'name' => 'Unmatched Withdrawals',
                        'weight' => 20,
                        'explanation' => 'Withdrawal could not be matched to ledger entries.',
                    ],
                ],
                'triggered_rules_summary' => [
                    'vendor_risk' => 2,
                    'reconciliation_risk' => 2,
                    'entity_relationship_risk' => 0,
                ],
            ],
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_case_events',
            'audit_cases',
            'case_recommendations',
            'alert_recommendations',
            'personal_access_tokens',
            'business_profile_memberships',
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
            $table->uuid('business_profile_id')->nullable();
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
            $table->uuid('business_profile_id')->nullable();
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
            $table->uuid('business_profile_id')->nullable();
            $table->foreignUuid('case_recommendation_id')->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('status')->default('open');
            $table->text('severity')->default('warning');
            $table->foreignUuid('assigned_to')->nullable();
            $table->foreignUuid('created_by');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_case_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id');
            $table->foreignUuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->foreignUuid('user_id')->nullable();
            $table->text('event_type');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createBusinessProfileSchema(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    private function createBusinessProfile(string $companyId, string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('business_profiles')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => $name,
            'is_default' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
