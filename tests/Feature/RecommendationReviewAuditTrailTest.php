<?php

namespace Tests\Feature;

use App\Models\AlertRecommendation;
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
use App\Services\RecommendationReviewAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationReviewAuditTrailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-agent-key']);
        $this->createSchema();
    }

    public function test_creation_event_recorded(): void
    {
        [$company] = $this->createCompanyUser();
        $this->bindRiskSources($this->multiDomainHighRiskSources());

        app(AlertRecommendationService::class)->getAlertRecommendations($company->id);
        app(CaseRecommendationService::class)->getCaseRecommendations($company->id);

        $this->assertDatabaseHas('recommendation_review_events', [
            'company_id' => $company->id,
            'recommendation_type' => RecommendationReviewEvent::TYPE_ALERT,
            'event_type' => RecommendationReviewEvent::EVENT_CREATED,
            'actor_type' => RecommendationReviewEvent::ACTOR_SYSTEM,
        ]);
        $this->assertDatabaseHas('recommendation_review_events', [
            'company_id' => $company->id,
            'recommendation_type' => RecommendationReviewEvent::TYPE_CASE,
            'event_type' => RecommendationReviewEvent::EVENT_CREATED,
            'actor_type' => RecommendationReviewEvent::ACTOR_SYSTEM,
        ]);
    }

    public function test_view_event_recorded(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createAlertRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/alert-recommendations/{$recommendation->id}")
            ->assertOk();

        $this->assertDatabaseHas('recommendation_review_events', [
            'company_id' => $company->id,
            'recommendation_type' => RecommendationReviewEvent::TYPE_ALERT,
            'recommendation_id' => $recommendation->id,
            'event_type' => RecommendationReviewEvent::EVENT_VIEWED,
            'actor_type' => RecommendationReviewEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);
    }

    public function test_approval_event_recorded(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createAlertRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('recommendation_review_events', [
            'company_id' => $company->id,
            'recommendation_type' => RecommendationReviewEvent::TYPE_ALERT,
            'recommendation_id' => $recommendation->id,
            'event_type' => RecommendationReviewEvent::EVENT_APPROVED,
            'actor_type' => RecommendationReviewEvent::ACTOR_USER,
            'actor_id' => $user->id,
        ]);
    }

    public function test_dismissal_event_recorded(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/case-recommendations/{$recommendation->id}/dismiss", [
            'review_note' => 'Duplicate investigation.',
        ])->assertOk();

        $event = RecommendationReviewEvent::where('recommendation_type', RecommendationReviewEvent::TYPE_CASE)
            ->where('recommendation_id', $recommendation->id)
            ->where('event_type', RecommendationReviewEvent::EVENT_DISMISSED)
            ->firstOrFail();

        $this->assertSame(RecommendationReviewEvent::ACTOR_USER, $event->actor_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame(true, $event->event_metadata['has_review_note']);
        $this->assertArrayNotHasKey('review_note', $event->event_metadata);
    }

    public function test_agent_cannot_approve_or_dismiss(): void
    {
        [$company] = $this->createCompanyUser();
        $alertRecommendation = $this->createAlertRecommendation($company->id);
        $caseRecommendation = $this->createCaseRecommendation($company->id);

        $this->withToken('test-agent-key')
            ->postJson("/api/alert-recommendations/{$alertRecommendation->id}/approve")
            ->assertUnauthorized();

        $this->withToken('test-agent-key')
            ->postJson("/api/case-recommendations/{$caseRecommendation->id}/dismiss")
            ->assertUnauthorized();

        $this->assertDatabaseMissing('recommendation_review_events', [
            'event_type' => RecommendationReviewEvent::EVENT_APPROVED,
            'actor_type' => RecommendationReviewEvent::ACTOR_AGENT,
        ]);
        $this->assertDatabaseMissing('recommendation_review_events', [
            'event_type' => RecommendationReviewEvent::EVENT_DISMISSED,
            'actor_type' => RecommendationReviewEvent::ACTOR_AGENT,
        ]);
    }

    public function test_event_metadata_excludes_sensitive_evidence(): void
    {
        [$company] = $this->createCompanyUser();
        $recommendation = $this->createAlertRecommendation($company->id, [
            'evidence' => [
                'secret_account' => 'do-not-log',
            ],
        ]);

        app(RecommendationReviewAuditService::class)->record(
            companyId: $company->id,
            recommendationType: RecommendationReviewEvent::TYPE_ALERT,
            recommendationId: $recommendation->id,
            eventType: RecommendationReviewEvent::EVENT_CREATED,
            actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
            metadata: [
                'alert_type' => 'vendor_risk_review',
                'evidence' => ['secret_account' => 'do-not-log'],
                'nested' => [
                    'supporting_evidence' => ['transaction_id' => 'hidden'],
                    'safe_count' => 2,
                ],
            ],
        );

        $event = RecommendationReviewEvent::where('recommendation_id', $recommendation->id)->firstOrFail();

        $this->assertArrayNotHasKey('evidence', $event->event_metadata);
        $this->assertArrayNotHasKey('supporting_evidence', $event->event_metadata['nested']);
        $this->assertSame(2, $event->event_metadata['nested']['safe_count']);
        $this->assertStringNotContainsString('do-not-log', json_encode($event->event_metadata, JSON_THROW_ON_ERROR));
    }

    public function test_alert_recommendation_history_returned(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createAlertRecommendation($company->id);

        app(RecommendationReviewAuditService::class)->record(
            companyId: $company->id,
            recommendationType: RecommendationReviewEvent::TYPE_ALERT,
            recommendationId: $recommendation->id,
            eventType: RecommendationReviewEvent::EVENT_CREATED,
            actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
            metadata: ['alert_type' => $recommendation->alert_type],
        );

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/alert-recommendations/{$recommendation->id}");

        $response->assertOk()
            ->assertJsonPath('recommendation.review_events.0.event_type', RecommendationReviewEvent::EVENT_CREATED)
            ->assertJsonPath('recommendation.review_events.1.event_type', RecommendationReviewEvent::EVENT_VIEWED)
            ->assertJsonPath('recommendation.review_events.1.actor_type', RecommendationReviewEvent::ACTOR_USER);
    }

    public function test_case_recommendation_history_returned(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id);

        app(RecommendationReviewAuditService::class)->record(
            companyId: $company->id,
            recommendationType: RecommendationReviewEvent::TYPE_CASE,
            recommendationId: $recommendation->id,
            eventType: RecommendationReviewEvent::EVENT_CREATED,
            actorType: RecommendationReviewEvent::ACTOR_SYSTEM,
            metadata: ['case_type' => $recommendation->case_type],
        );

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/case-recommendations/{$recommendation->id}");

        $response->assertOk()
            ->assertJsonPath('recommendation.review_events.0.event_type', RecommendationReviewEvent::EVENT_CREATED)
            ->assertJsonPath('recommendation.review_events.1.event_type', RecommendationReviewEvent::EVENT_VIEWED)
            ->assertJsonPath('recommendation.review_events.1.actor_type', RecommendationReviewEvent::ACTOR_USER);
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
    private function createAlertRecommendation(string $companyId, array $overrides = []): AlertRecommendation
    {
        return AlertRecommendation::create(array_merge([
            'company_id' => $companyId,
            'source_risk_domain' => 'vendor_risk',
            'alert_type' => 'vendor_risk_review',
            'severity' => 'high',
            'title' => 'Review vendor risk signals',
            'summary' => 'Vendor risk scoring identified deterministic payment patterns that require human review.',
            'evidence' => [
                'flagged_vendors' => ['Northstar Consulting'],
            ],
            'source_rule_ids' => ['threshold_splitting'],
            'confidence_score' => 0.9,
            'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
        ], $overrides));
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
            'evidence' => [
                'domain_scores' => [
                    'vendor_risk' => 75,
                    'reconciliation_risk' => 45,
                ],
            ],
            'confidence_score' => 0.9,
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

            public function scoreAllVendors(string $companyId): array
            {
                return $this->scores;
            }
        });

        $this->app->instance(ReconciliationRiskScoringService::class, new class($sources['reconciliation_risk']) extends ReconciliationRiskScoringService
        {
            public function __construct(private readonly array $risk) {}

            public function scoreReconciliation(string $companyId): array
            {
                return array_merge(['company_id' => $companyId], $this->risk);
            }
        });

        $this->app->instance(EntityRelationshipRiskScoringService::class, new class($sources['entity_relationship_risk']) extends EntityRelationshipRiskScoringService
        {
            public function __construct(private readonly array $risk) {}

            public function scoreEntityRelationships(string $companyId): array
            {
                return array_merge(['company_id' => $companyId], $this->risk);
            }
        });

        $this->app->instance(AggregateRiskSummaryService::class, new class($sources['aggregate_summary']) extends AggregateRiskSummaryService
        {
            public function __construct(private readonly array $summary) {}

            public function getAggregateRiskSummary(string $companyId): array
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
                ],
                'supporting_evidence' => [
                    'threshold_splitting' => [
                        'split_transaction_groups' => [[
                            ['id' => (string) Str::uuid(), 'amount' => 4500.00, 'date' => '2026-05-11'],
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
                'highest_risk_findings' => [],
                'triggered_rules_summary' => [
                    'vendor_risk' => 1,
                    'reconciliation_risk' => 2,
                    'entity_relationship_risk' => 0,
                ],
            ],
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'recommendation_review_events',
            'audit_case_events',
            'audit_cases',
            'alerts',
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

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->uuid('group_id')->nullable();
            $table->foreignUuid('alert_recommendation_id')->nullable();
            $table->text('rule_key');
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->text('status')->default('open');
            $table->integer('priority_score')->default(50);
            $table->foreignUuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
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
    }
}
