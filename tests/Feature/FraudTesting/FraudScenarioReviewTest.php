<?php

namespace Tests\Feature\FraudTesting;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FraudScenarioReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fraud_testing.api_key' => 'test-fraud-token']);

        Schema::create('fraud_scenario_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id')->nullable();
            $table->string('external_scenario_id')->nullable();
            $table->string('title');
            $table->text('narrative');
            $table->string('source')->nullable();
            $table->string('severity')->nullable();
            $table->string('status')->default('imported');
            $table->string('extraction_status')->default('pending');
            $table->string('mock_data_status')->default('pending');
            $table->string('review_status')->default('unreviewed');
            $table->integer('row_number')->nullable();
            $table->text('raw_row')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_scenario_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('fraud_category')->nullable();
            $table->string('industry')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('concealment_method')->nullable();
            $table->text('summary')->nullable();
            $table->text('structured_payload');
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('model_name')->nullable();
            $table->string('prompt_version')->nullable();
            $table->text('extraction_errors')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_expected_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('indicator_key');
            $table->string('indicator_name');
            $table->string('indicator_category')->nullable();
            $table->text('description')->nullable();
            $table->string('severity')->nullable();
            $table->text('data_needed')->nullable();
            $table->boolean('should_detect')->default(true);
            $table->timestamps();
        });

        Schema::create('fraud_expected_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('finding_key');
            $table->string('finding_title');
            $table->text('finding_description');
            $table->integer('expected_risk_score')->nullable();
            $table->string('expected_confidence')->nullable();
            $table->text('recommended_action')->nullable();
            $table->text('expected_user_message')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_document_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('document_name');
            $table->text('why_needed')->nullable();
            $table->string('priority')->nullable();
            $table->text('expected_issue_found')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_investigation_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->text('question');
            $table->string('asked_to')->nullable();
            $table->text('why_question_matters')->nullable();
            $table->string('priority')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_mock_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('company_name');
            $table->string('industry')->nullable();
            $table->string('entity_type')->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->integer('employee_count')->nullable();
            $table->integer('vendor_count')->nullable();
            $table->integer('customer_count')->nullable();
            $table->integer('months_of_activity')->nullable();
            $table->text('profile_payload');
            $table->timestamps();
        });

        Schema::create('fraud_mock_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->uuid('mock_company_id')->nullable();
            $table->string('external_party_id')->nullable();
            $table->string('party_type');
            $table->string('party_name');
            $table->string('role')->nullable();
            $table->boolean('is_fraud_actor')->default(false);
            $table->boolean('is_related_party')->default(false);
            $table->text('attributes')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_mock_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->uuid('mock_company_id')->nullable();
            $table->string('external_transaction_id')->nullable();
            $table->string('transaction_type');
            $table->date('transaction_date')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->uuid('party_id')->nullable();
            $table->string('account_category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_fraudulent')->default(false);
            $table->string('fraud_pattern')->nullable();
            $table->string('expected_brevix_signal')->nullable();
            $table->text('payload')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'fraud_mock_transactions', 'fraud_mock_parties', 'fraud_mock_companies',
            'fraud_investigation_questions', 'fraud_document_requests',
            'fraud_expected_findings', 'fraud_expected_indicators',
            'fraud_scenario_extractions', 'fraud_scenario_submissions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_list_scenarios_returns_paginated_results(): void
    {
        $this->insertSubmission(['title' => 'Scenario A']);
        $this->insertSubmission(['title' => 'Scenario B']);

        $response = $this->withToken('test-fraud-token')
            ->getJson('/api/internal/fraud-testing/scenarios')
            ->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_scenarios_filters_by_extraction_status(): void
    {
        $this->insertSubmission(['extraction_status' => 'completed']);
        $this->insertSubmission(['extraction_status' => 'pending']);

        $response = $this->withToken('test-fraud-token')
            ->getJson('/api/internal/fraud-testing/scenarios?extraction_status=completed')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_list_scenarios_filters_by_review_status(): void
    {
        $this->insertSubmission(['review_status' => 'approved']);
        $this->insertSubmission(['review_status' => 'unreviewed']);

        $response = $this->withToken('test-fraud-token')
            ->getJson('/api/internal/fraud-testing/scenarios?review_status=approved')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_get_scenario_returns_404_for_unknown_id(): void
    {
        $this->withToken('test-fraud-token')
            ->getJson('/api/internal/fraud-testing/scenarios/' . Str::uuid())
            ->assertNotFound();
    }

    public function test_get_scenario_returns_detail(): void
    {
        $id = $this->insertSubmission(['title' => 'Ghost Employee Test']);

        $this->withToken('test-fraud-token')
            ->getJson("/api/internal/fraud-testing/scenarios/{$id}")
            ->assertOk()
            ->assertJsonPath('submission.id', $id)
            ->assertJsonPath('submission.title', 'Ghost Employee Test');
    }

    public function test_approve_scenario_sets_review_status(): void
    {
        $id = $this->insertSubmission(['review_status' => 'unreviewed']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('review_status', 'approved');

        $this->assertDatabaseHas('fraud_scenario_submissions', [
            'id' => $id,
            'review_status' => 'approved',
        ]);
    }

    public function test_reject_scenario_sets_review_status(): void
    {
        $id = $this->insertSubmission(['review_status' => 'unreviewed']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-testing/scenarios/{$id}/reject")
            ->assertOk()
            ->assertJsonPath('review_status', 'rejected');

        $this->assertDatabaseHas('fraud_scenario_submissions', [
            'id' => $id,
            'review_status' => 'rejected',
        ]);
    }

    public function test_approve_returns_404_for_unknown_id(): void
    {
        $this->withToken('test-fraud-token')
            ->postJson('/api/internal/fraud-testing/scenarios/' . Str::uuid() . '/approve')
            ->assertNotFound();
    }

    public function test_admin_routes_require_token(): void
    {
        $this->getJson('/api/internal/fraud-testing/scenarios')->assertUnauthorized();
    }

    private function insertSubmission(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('fraud_scenario_submissions')->insert(array_merge([
            'id' => $id,
            'external_scenario_id' => 'TEST-' . strtoupper(Str::random(4)),
            'title' => 'Test Scenario',
            'narrative' => 'A payroll manager created a fictitious employee.',
            'status' => 'imported',
            'extraction_status' => 'pending',
            'mock_data_status' => 'pending',
            'review_status' => 'unreviewed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
        return $id;
    }
}
