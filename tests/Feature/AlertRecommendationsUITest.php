<?php

namespace Tests\Feature;

use App\Models\AlertRecommendation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for the alert recommendation review UI and the API endpoints it depends on.
 *
 * Each test corresponds to a required UI behaviour:
 *   - pending recommendations render
 *   - evidence expands (data is present in API response)
 *   - approve calls correct endpoint
 *   - dismiss calls correct endpoint with note
 *   - errors display safely
 *   - reviewed items are removed from the pending list after action
 */
class AlertRecommendationsUITest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    // -------------------------------------------------------------------------
    // Pending recommendations render
    // -------------------------------------------------------------------------

    public function test_pending_recommendations_are_returned_for_authenticated_user(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $rec->id)
            ->assertJsonPath('recommendations.0.status', AlertRecommendation::STATUS_PENDING_REVIEW)
            ->assertJsonPath('recommendations.0.title', $rec->title)
            ->assertJsonPath('recommendations.0.summary', $rec->summary)
            ->assertJsonPath('recommendations.0.severity', $rec->severity)
            ->assertJsonPath('recommendations.0.source_risk_domain', $rec->source_risk_domain)
            ->assertJsonPath('total', 1);
    }

    public function test_multiple_pending_recommendations_all_returned(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->createRecommendation($company->id, ['title' => 'First']);
        $this->createRecommendation($company->id, ['title' => 'Second']);
        $this->createRecommendation($company->id, ['title' => 'Third']);

        Sanctum::actingAs($user);

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(3, 'recommendations')
            ->assertJsonPath('total', 3);
    }

    public function test_only_pending_recommendations_shown_by_default(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $pending = $this->createRecommendation($company->id);
        $this->createRecommendation($company->id, ['status' => AlertRecommendation::STATUS_APPROVED]);
        $this->createRecommendation($company->id, ['status' => AlertRecommendation::STATUS_DISMISSED]);

        Sanctum::actingAs($user);

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $pending->id);
    }

    public function test_page_renders_successfully(): void
    {
        $this->get('/alert-recommendations')
            ->assertOk()
            ->assertSee('Alert Recommendations')
            ->assertSee('Requires human review')
            ->assertSee('Approve Alert')
            ->assertSee('Dismiss Recommendation');
    }

    // -------------------------------------------------------------------------
    // Evidence expands — evidence data is present in API response
    // -------------------------------------------------------------------------

    public function test_evidence_data_is_included_in_recommendation_response(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->createRecommendation($company->id, [
            'evidence' => [
                'flagged_vendors' => ['Northstar Consulting', 'Apex Supplies'],
                'flagged_vendor_count' => 2,
                'transaction_total' => 45000,
            ],
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonPath('recommendations.0.evidence.flagged_vendor_count', 2)
            ->assertJsonPath('recommendations.0.evidence.transaction_total', 45000);
    }

    public function test_individual_recommendation_includes_full_evidence(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id, [
            'evidence' => ['pattern' => 'threshold_splitting', 'occurrences' => 7],
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/alert-recommendations/{$rec->id}")
            ->assertOk()
            ->assertJsonPath('recommendation.evidence.pattern', 'threshold_splitting')
            ->assertJsonPath('recommendation.evidence.occurrences', 7);
    }

    // -------------------------------------------------------------------------
    // Approve calls correct endpoint
    // -------------------------------------------------------------------------

    public function test_approve_endpoint_marks_recommendation_approved(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/approve")
            ->assertOk()
            ->assertJsonPath('recommendation.status', AlertRecommendation::STATUS_APPROVED)
            ->assertJsonPath('recommendation.reviewed_by_user_id', $user->id);
    }

    public function test_approve_creates_alert_from_recommendation(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/approve")
            ->assertOk()
            ->assertJsonPath('alert.alert_recommendation_id', $rec->id)
            ->assertJsonPath('alert.status', 'open');

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', [
            'company_id' => $company->id,
            'alert_recommendation_id' => $rec->id,
        ]);
    }

    public function test_approve_stores_review_audit_fields(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/approve")->assertOk();

        $rec->refresh();
        $this->assertSame(AlertRecommendation::STATUS_APPROVED, $rec->status);
        $this->assertSame($user->id, $rec->reviewed_by_user_id);
        $this->assertNotNull($rec->reviewed_at);
    }

    // -------------------------------------------------------------------------
    // Dismiss calls correct endpoint with note
    // -------------------------------------------------------------------------

    public function test_dismiss_endpoint_with_review_note(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss", [
            'review_note' => 'Known vendor — reviewed manually.',
        ])
            ->assertOk()
            ->assertJsonPath('recommendation.status', AlertRecommendation::STATUS_DISMISSED)
            ->assertJsonPath('recommendation.reviewed_by_user_id', $user->id)
            ->assertJsonPath('recommendation.review_note', 'Known vendor — reviewed manually.');
    }

    public function test_dismiss_endpoint_without_note_is_accepted(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('recommendation.status', AlertRecommendation::STATUS_DISMISSED);
    }

    public function test_dismiss_does_not_create_alert(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss")->assertOk();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_dismiss_note_max_length_enforced(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss", [
            'review_note' => str_repeat('a', 2001),
        ])->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // Errors display safely
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/alert-recommendations')
            ->assertUnauthorized();
    }

    public function test_approve_unknown_id_returns_safe_404(): void
    {
        [, $user] = $this->createCompanyUser();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/alert-recommendations/'.Str::uuid().'/approve');

        $response->assertNotFound();

        // Response must be a safe error message — no stack traces or model internals
        $json = $response->json();
        $this->assertArrayHasKey('error', $json);
        $this->assertArrayNotHasKey('exception', $json);
        $this->assertArrayNotHasKey('trace', $json);
    }

    public function test_dismiss_unknown_id_returns_safe_404(): void
    {
        [, $user] = $this->createCompanyUser();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/alert-recommendations/'.Str::uuid().'/dismiss');

        $response->assertNotFound();

        $json = $response->json();
        $this->assertArrayHasKey('error', $json);
        $this->assertArrayNotHasKey('exception', $json);
        $this->assertArrayNotHasKey('trace', $json);
    }

    public function test_double_approve_returns_409_conflict(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/approve")->assertOk();

        $this->postJson("/api/alert-recommendations/{$rec->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('current_status', AlertRecommendation::STATUS_APPROVED);

        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_double_dismiss_returns_409_conflict(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss")->assertOk();

        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss")
            ->assertStatus(409)
            ->assertJsonPath('current_status', AlertRecommendation::STATUS_DISMISSED);
    }

    public function test_cross_company_access_returns_safe_404(): void
    {
        [, $user] = $this->createCompanyUser();
        [$otherCompany] = $this->createCompanyUser();
        $rec = $this->createRecommendation($otherCompany->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/alert-recommendations/{$rec->id}")->assertNotFound();
        $this->postJson("/api/alert-recommendations/{$rec->id}/approve")->assertNotFound();
        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss")->assertNotFound();

        $this->assertDatabaseCount('alerts', 0);
    }

    // -------------------------------------------------------------------------
    // Reviewed items are removed from the pending list after action
    // -------------------------------------------------------------------------

    public function test_approved_recommendation_no_longer_in_pending_list(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/approve")->assertOk();

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(0, 'recommendations')
            ->assertJsonPath('total', 0);
    }

    public function test_dismissed_recommendation_no_longer_in_pending_list(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $rec = $this->createRecommendation($company->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$rec->id}/dismiss", [
            'review_note' => 'Not actionable.',
        ])->assertOk();

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(0, 'recommendations')
            ->assertJsonPath('total', 0);
    }

    public function test_remaining_pending_visible_after_partial_review(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $first = $this->createRecommendation($company->id, ['title' => 'First']);
        $this->createRecommendation($company->id, ['title' => 'Second']);
        $this->createRecommendation($company->id, ['title' => 'Third']);

        Sanctum::actingAs($user);

        $this->postJson("/api/alert-recommendations/{$first->id}/approve")->assertOk();

        $this->getJson('/api/alert-recommendations')
            ->assertOk()
            ->assertJsonCount(2, 'recommendations')
            ->assertJsonPath('total', 2);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        $company = new Company(['name' => 'Brevix Test Co']);
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
            'evidence' => ['flagged_vendors' => ['Northstar Consulting']],
            'source_rule_ids' => ['threshold_splitting'],
            'confidence_score' => 0.9,
            'status' => AlertRecommendation::STATUS_PENDING_REVIEW,
        ], $overrides));
    }

    private function createSchema(): void
    {
        foreach (['alerts', 'alert_recommendations', 'personal_access_tokens', 'business_profile_memberships', 'workspace_memberships', 'business_profiles', 'users', 'companies'] as $table) {
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
    }
}
