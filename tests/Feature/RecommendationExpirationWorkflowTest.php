<?php

namespace Tests\Feature;

use App\Models\AlertRecommendation;
use App\Models\CaseRecommendation;
use App\Models\Company;
use App\Models\RecommendationReviewEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationExpirationWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-18 12:00:00'));
        config(['recommendations.expiration_days' => 30]);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_stale_pending_alert_recommendation_expires(): void
    {
        [$company] = $this->createCompanyUser();
        $stale = $this->createAlertRecommendation($company->id);
        $fresh = $this->createAlertRecommendation($company->id, [
            'source_risk_domain' => 'reconciliation_risk',
            'alert_type' => 'reconciliation_risk_review',
        ]);
        $this->backdate($stale, 31);
        $this->backdate($fresh, 29);

        $this->artisan('recommendations:expire')
            ->expectsOutput('Expired 1 recommendation(s).')
            ->assertExitCode(0);

        $this->assertSame(AlertRecommendation::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertSame(AlertRecommendation::STATUS_PENDING_REVIEW, $fresh->fresh()->status);
    }

    public function test_stale_pending_case_recommendation_expires(): void
    {
        [$company] = $this->createCompanyUser();
        $stale = $this->createCaseRecommendation($company->id);
        $fresh = $this->createCaseRecommendation($company->id, [
            'case_type' => 'related_party_vendor_investigation',
        ]);
        $this->backdate($stale, 31);
        $this->backdate($fresh, 29);

        $this->artisan('recommendations:expire')
            ->expectsOutput('Expired 1 recommendation(s).')
            ->assertExitCode(0);

        $this->assertSame(CaseRecommendation::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertSame(CaseRecommendation::STATUS_PENDING_REVIEW, $fresh->fresh()->status);
    }

    public function test_approved_recommendation_does_not_expire(): void
    {
        [$company] = $this->createCompanyUser();
        $alert = $this->createAlertRecommendation($company->id, [
            'status' => AlertRecommendation::STATUS_APPROVED,
        ]);
        $case = $this->createCaseRecommendation($company->id, [
            'status' => CaseRecommendation::STATUS_APPROVED,
        ]);
        $this->backdate($alert, 90);
        $this->backdate($case, 90);

        $this->artisan('recommendations:expire')
            ->expectsOutput('Expired 0 recommendation(s).')
            ->assertExitCode(0);

        $this->assertSame(AlertRecommendation::STATUS_APPROVED, $alert->fresh()->status);
        $this->assertSame(CaseRecommendation::STATUS_APPROVED, $case->fresh()->status);
    }

    public function test_dismissed_recommendation_does_not_expire(): void
    {
        [$company] = $this->createCompanyUser();
        $alert = $this->createAlertRecommendation($company->id, [
            'status' => AlertRecommendation::STATUS_DISMISSED,
        ]);
        $case = $this->createCaseRecommendation($company->id, [
            'status' => CaseRecommendation::STATUS_DISMISSED,
        ]);
        $this->backdate($alert, 90);
        $this->backdate($case, 90);

        $this->artisan('recommendations:expire')
            ->expectsOutput('Expired 0 recommendation(s).')
            ->assertExitCode(0);

        $this->assertSame(AlertRecommendation::STATUS_DISMISSED, $alert->fresh()->status);
        $this->assertSame(CaseRecommendation::STATUS_DISMISSED, $case->fresh()->status);
    }

    public function test_expiration_event_is_recorded(): void
    {
        [$company] = $this->createCompanyUser();
        $alert = $this->createAlertRecommendation($company->id, [
            'evidence' => ['secret_account' => 'do-not-log'],
        ]);
        $case = $this->createCaseRecommendation($company->id, [
            'evidence' => ['supporting_evidence' => ['transaction_id' => 'hidden']],
        ]);
        $this->backdate($alert, 31);
        $this->backdate($case, 31);

        $this->artisan('recommendations:expire')->assertExitCode(0);

        $this->assertDatabaseHas('recommendation_review_events', [
            'company_id' => $company->id,
            'recommendation_type' => RecommendationReviewEvent::TYPE_ALERT,
            'recommendation_id' => $alert->id,
            'event_type' => RecommendationReviewEvent::EVENT_EXPIRED,
            'actor_type' => RecommendationReviewEvent::ACTOR_SYSTEM,
            'actor_id' => null,
        ]);
        $this->assertDatabaseHas('recommendation_review_events', [
            'company_id' => $company->id,
            'recommendation_type' => RecommendationReviewEvent::TYPE_CASE,
            'recommendation_id' => $case->id,
            'event_type' => RecommendationReviewEvent::EVENT_EXPIRED,
            'actor_type' => RecommendationReviewEvent::ACTOR_SYSTEM,
            'actor_id' => null,
        ]);

        $metadata = RecommendationReviewEvent::query()
            ->whereIn('recommendation_id', [$alert->id, $case->id])
            ->pluck('event_metadata')
            ->all();

        $encodedMetadata = json_encode($metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('do-not-log', $encodedMetadata);
        $this->assertStringNotContainsString('hidden', $encodedMetadata);
    }

    public function test_expired_recommendation_cannot_be_approved(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createAlertRecommendation($company->id, [
            'status' => AlertRecommendation::STATUS_EXPIRED,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('current_status', AlertRecommendation::STATUS_EXPIRED);

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_expired_recommendation_cannot_be_dismissed(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createCaseRecommendation($company->id, [
            'status' => CaseRecommendation::STATUS_EXPIRED,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/case-recommendations/{$recommendation->id}/dismiss", [
            'review_note' => 'Too old to review.',
        ])
            ->assertStatus(409)
            ->assertJsonPath('current_status', CaseRecommendation::STATUS_EXPIRED);

        $recommendation->refresh();
        $this->assertSame(CaseRecommendation::STATUS_EXPIRED, $recommendation->status);
        $this->assertNull($recommendation->review_note);
    }

    public function test_detail_endpoints_show_expired_status_and_review_history(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $alert = $this->createAlertRecommendation($company->id);
        $case = $this->createCaseRecommendation($company->id);
        $this->backdate($alert, 31);
        $this->backdate($case, 31);

        $this->artisan('recommendations:expire')->assertExitCode(0);

        Sanctum::actingAs($user);

        $this->getJson("/api/alert-recommendations/{$alert->id}")
            ->assertOk()
            ->assertJsonPath('recommendation.status', AlertRecommendation::STATUS_EXPIRED)
            ->assertJsonPath('recommendation.review_events.0.event_type', RecommendationReviewEvent::EVENT_EXPIRED)
            ->assertJsonPath('recommendation.review_events.0.actor_type', RecommendationReviewEvent::ACTOR_SYSTEM)
            ->assertJsonPath('recommendation.review_events.1.event_type', RecommendationReviewEvent::EVENT_VIEWED);

        $this->getJson("/api/case-recommendations/{$case->id}")
            ->assertOk()
            ->assertJsonPath('recommendation.status', CaseRecommendation::STATUS_EXPIRED)
            ->assertJsonPath('recommendation.review_events.0.event_type', RecommendationReviewEvent::EVENT_EXPIRED)
            ->assertJsonPath('recommendation.review_events.0.actor_type', RecommendationReviewEvent::ACTOR_SYSTEM)
            ->assertJsonPath('recommendation.review_events.1.event_type', RecommendationReviewEvent::EVENT_VIEWED);
    }

    public function test_status_filter_works_for_list_endpoints(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $pendingAlert = $this->createAlertRecommendation($company->id);
        $expiredAlert = $this->createAlertRecommendation($company->id, [
            'source_risk_domain' => 'reconciliation_risk',
            'alert_type' => 'reconciliation_risk_review',
            'status' => AlertRecommendation::STATUS_EXPIRED,
        ]);
        $pendingCase = $this->createCaseRecommendation($company->id);
        $expiredCase = $this->createCaseRecommendation($company->id, [
            'case_type' => 'related_party_vendor_investigation',
            'status' => CaseRecommendation::STATUS_EXPIRED,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $pendingAlert->id);

        $this->getJson('/api/alert-recommendations?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $expiredAlert->id);

        $this->getJson('/api/case-recommendations')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $pendingCase->id);

        $this->getJson('/api/case-recommendations?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $expiredCase->id);
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

    private function backdate(Model $recommendation, int $days): void
    {
        $timestamp = now()->subDays($days);

        $recommendation->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();
    }

    private function createSchema(): void
    {
        foreach ([
            'recommendation_review_events',
            'audit_cases',
            'alerts',
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
            $table->foreignUuid('created_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
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
