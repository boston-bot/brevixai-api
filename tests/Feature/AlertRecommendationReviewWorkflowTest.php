<?php

namespace Tests\Feature;

use App\Models\AlertRecommendation;
use App\Models\Company;
use App\Models\RecommendationReviewEvent;
use App\Models\User;
use App\Services\AlertRecommendationReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertRecommendationReviewWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-agent-key']);
        $this->createSchema();
    }

    public function test_list_pending_recommendations(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$otherCompany] = $this->createCompanyUser();
        $pending = $this->createRecommendation($company->id);
        $this->createRecommendation($company->id, ['status' => AlertRecommendation::STATUS_APPROVED]);
        $this->createRecommendation($otherCompany->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $pending->id)
            ->assertJsonPath('recommendations.0.status', AlertRecommendation::STATUS_PENDING_REVIEW)
            ->assertJsonPath('total', 1);
    }

    public function test_view_recommendation(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id, [
            'evidence' => ['flagged_vendor_count' => 2],
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/alert-recommendations/{$recommendation->id}")
            ->assertOk()
            ->assertJsonPath('recommendation.id', $recommendation->id)
            ->assertJsonPath('recommendation.evidence.flagged_vendor_count', 2);
    }

    public function test_user_recommendation_endpoints_require_authentication(): void
    {
        [$company] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);

        $this->getJson('/api/alert-recommendations')->assertUnauthorized();
        $this->getJson("/api/alert-recommendations/{$recommendation->id}")->assertUnauthorized();
        $this->postJson("/api/alert-recommendations/{$recommendation->id}/approve")->assertUnauthorized();
        $this->postJson("/api/alert-recommendations/{$recommendation->id}/dismiss")->assertUnauthorized();
    }

    public function test_approve_creates_alert(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/approve")
            ->assertOk()
            ->assertJsonPath('recommendation.status', AlertRecommendation::STATUS_APPROVED)
            ->assertJsonPath('recommendation.reviewed_by_user_id', $user->id)
            ->assertJsonPath('alert.alert_recommendation_id', $recommendation->id)
            ->assertJsonPath('alert.status', 'open')
            ->assertJsonPath('alert.rule_key', 'vendor_risk_review')
            ->assertJsonPath('alert.reason_codes.0', 'threshold_splitting')
            ->assertJsonPath('alert.source_system', 'deterministic_recommendation_engine')
            ->assertJsonPath('alert.source_recommendation_id', $recommendation->id)
            ->assertJsonPath('alert.confidence_score', 0.9)
            ->assertJsonPath('alert.evidence_refs.0', 'recommendation:'.$recommendation->id)
            ->assertJsonPath('alert.comparison_window.basis', 'current_available_records');

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', [
            'company_id' => $company->id,
            'alert_recommendation_id' => $recommendation->id,
            'title' => 'Review vendor risk signals',
            'source_system' => 'deterministic_recommendation_engine',
            'source_recommendation_id' => $recommendation->id,
        ]);
    }

    public function test_dismiss_does_not_create_alert(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/dismiss", [
            'review_note' => 'Known vendor activity.',
        ])
            ->assertOk()
            ->assertJsonPath('recommendation.status', AlertRecommendation::STATUS_DISMISSED)
            ->assertJsonPath('recommendation.reviewed_by_user_id', $user->id)
            ->assertJsonPath('recommendation.review_note', 'Known vendor activity.');

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_unauthorized_company_access_rejected(): void
    {
        [, $user] = $this->createCompanyUser();
        [$otherCompany] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($otherCompany->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/alert-recommendations/{$recommendation->id}")
            ->assertNotFound();

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/approve")
            ->assertNotFound();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_approval_respects_active_business_profile(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $profileA = $this->createProfile($company->id, 'Profile A');
        $profileB = $this->createProfile($company->id, 'Profile B');
        $recommendationA = $this->createRecommendation($company->id, ['business_profile_id' => $profileA]);
        $recommendationB = $this->createRecommendation($company->id, ['business_profile_id' => $profileB]);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/alert-recommendations/{$recommendationB->id}/approve",
            [],
            ['X-Brevix-Business-Profile-Id' => $profileA],
        )->assertNotFound();

        $this->postJson(
            "/api/alert-recommendations/{$recommendationA->id}/approve",
            [],
            ['X-Brevix-Business-Profile-Id' => $profileA],
        )
            ->assertOk()
            ->assertJsonPath('alert.business_profile_id', $profileA);

        $this->assertDatabaseHas('alerts', [
            'alert_recommendation_id' => $recommendationA->id,
            'business_profile_id' => $profileA,
        ]);
        $this->assertDatabaseHas('alert_recommendations', [
            'id' => $recommendationB->id,
            'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_double_approval_blocked(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/approve")
            ->assertOk();

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('current_status', AlertRecommendation::STATUS_APPROVED);

        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_double_dismissal_blocked(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/dismiss")
            ->assertOk();

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/dismiss")
            ->assertStatus(409)
            ->assertJsonPath('current_status', AlertRecommendation::STATUS_DISMISSED);

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_agent_cannot_approve(): void
    {
        [$company] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);

        $this->withToken('test-agent-key')
            ->postJson("/api/alert-recommendations/{$recommendation->id}/approve")
            ->assertUnauthorized();

        $this->assertDatabaseHas('alert_recommendations', [
            'id' => $recommendation->id,
            'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_agent_actor_cannot_approve_or_dismiss_via_service(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $approvalAttempt = $this->createRecommendation($company->id);
        $dismissalAttempt = $this->createRecommendation($company->id);
        $service = app(AlertRecommendationReviewService::class);

        try {
            $service->approve(
                companyId: $company->id,
                userId: $user->id,
                recommendationId: $approvalAttempt->id,
                actorType: RecommendationReviewEvent::ACTOR_AGENT,
            );
            $this->fail('Agent actor should not approve alert recommendations.');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Agents cannot approve or dismiss alert recommendations', $e->getMessage());
        }

        try {
            $service->dismiss(
                companyId: $company->id,
                userId: $user->id,
                recommendationId: $dismissalAttempt->id,
                actorType: RecommendationReviewEvent::ACTOR_AGENT,
            );
            $this->fail('Agent actor should not dismiss alert recommendations.');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Agents cannot approve or dismiss alert recommendations', $e->getMessage());
        }

        $this->assertDatabaseHas('alert_recommendations', [
            'id' => $approvalAttempt->id,
            'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseHas('alert_recommendations', [
            'id' => $dismissalAttempt->id,
            'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_reviewer_must_belong_to_company_when_service_creates_alert(): void
    {
        [$company] = $this->createCompanyUser();
        [, $otherUser] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);
        $service = app(AlertRecommendationReviewService::class);

        try {
            $service->approve(
                companyId: $company->id,
                userId: $otherUser->id,
                recommendationId: $recommendation->id,
            );
            $this->fail('Cross-company reviewer should not approve alert recommendations.');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Reviewer is not authorized for this company', $e->getMessage());
        }

        $this->assertDatabaseHas('alert_recommendations', [
            'id' => $recommendation->id,
            'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_review_audit_fields_stored(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $recommendation = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$recommendation->id}/dismiss", [
            'review_note' => 'False positive after invoice review.',
        ])->assertOk();

        $recommendation->refresh();

        $this->assertSame(AlertRecommendation::STATUS_DISMISSED, $recommendation->status);
        $this->assertSame($user->id, $recommendation->reviewed_by_user_id);
        $this->assertNotNull($recommendation->reviewed_at);
        $this->assertSame('False positive after invoice review.', $recommendation->review_note);
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
    private function createRecommendation(string $companyId, array $overrides = []): AlertRecommendation
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

    private function createProfile(string $companyId, string $name): string
    {
        if (! Schema::hasTable('business_profiles')) {
            Schema::create('business_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('company_id');
                $table->string('name');
                $table->boolean('is_default')->default(false);
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        $profileId = (string) Str::uuid();

        DB::table('business_profiles')->insert([
            'id' => $profileId,
            'company_id' => $companyId,
            'name' => $name,
            'is_default' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $profileId;
    }

    private function createSchema(): void
    {
        foreach ([
            'alerts',
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
            $table->foreignUuid('business_profile_id')->nullable();
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

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('business_profile_id')->nullable();
            $table->uuid('group_id')->nullable();
            $table->foreignUuid('alert_recommendation_id')->nullable();
            $table->text('rule_key');
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->json('reason_codes')->nullable();
            $table->text('source_system')->nullable();
            $table->uuid('source_recommendation_id')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('comparison_window')->nullable();
            $table->text('status')->default('open');
            $table->integer('priority_score')->default(50);
            $table->foreignUuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }
}
