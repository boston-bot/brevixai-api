<?php

namespace Tests\Feature\FraudTesting;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FraudAgentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fraud_testing.api_key' => 'test-fraud-token']);
        $this->createFraudTables();
    }

    protected function tearDown(): void
    {
        $this->dropFraudTables();
        parent::tearDown();
    }

    // ─── Auth ────────────────────────────────────────────────────────────────

    public function test_agent_api_rejects_missing_token(): void
    {
        $this->getJson('/api/internal/fraud-scenarios/pending')
            ->assertUnauthorized();
    }

    public function test_agent_api_rejects_wrong_token(): void
    {
        $this->withToken('wrong-token')
            ->getJson('/api/internal/fraud-scenarios/pending')
            ->assertUnauthorized();
    }

    // ─── Pending ─────────────────────────────────────────────────────────────

    public function test_pending_returns_empty_when_none_exist(): void
    {
        $this->withToken('test-fraud-token')
            ->getJson('/api/internal/fraud-scenarios/pending')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_pending_returns_pending_scenarios(): void
    {
        $this->insertSubmission(['extraction_status' => 'pending']);
        $this->insertSubmission(['extraction_status' => 'completed']);

        $response = $this->withToken('test-fraud-token')
            ->getJson('/api/internal/fraud-scenarios/pending')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('pending', $response->json('data.0.extraction_status') ?? 'pending');
    }

    public function test_pending_respects_limit_param(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertSubmission(['extraction_status' => 'pending']);
        }

        $response = $this->withToken('test-fraud-token')
            ->getJson('/api/internal/fraud-scenarios/pending?limit=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // ─── Claim ────────────────────────────────────────────────────────────────

    public function test_claim_marks_extraction_status_as_processing(): void
    {
        $id = $this->insertSubmission(['extraction_status' => 'pending']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-scenarios/{$id}/claim")
            ->assertOk()
            ->assertJsonPath('scenario_id', $id)
            ->assertJsonPath('extraction_status', 'processing');

        $this->assertDatabaseHas('fraud_scenario_submissions', [
            'id' => $id,
            'extraction_status' => 'processing',
        ]);
    }

    public function test_claim_returns_conflict_if_not_pending(): void
    {
        $id = $this->insertSubmission(['extraction_status' => 'completed']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-scenarios/{$id}/claim")
            ->assertStatus(409);
    }

    public function test_claim_creates_generation_run(): void
    {
        $id = $this->insertSubmission(['extraction_status' => 'pending']);

        $response = $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-scenarios/{$id}/claim")
            ->assertOk();

        $runId = $response->json('run_id');
        $this->assertNotNull($runId);
        $this->assertDatabaseHas('fraud_generation_runs', [
            'id' => $runId,
            'scenario_submission_id' => $id,
            'run_type' => 'extraction',
            'status' => 'running',
        ]);
    }

    // ─── Save Extraction ──────────────────────────────────────────────────────

    public function test_save_extraction_persists_extraction_record(): void
    {
        $id = $this->insertSubmission(['extraction_status' => 'processing']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-scenarios/{$id}/extraction", [
                'fraud_category' => 'Payroll Fraud',
                'industry' => 'Construction',
                'actor_type' => 'Payroll Manager',
                'concealment_method' => 'Minimal employee records',
                'summary' => 'Ghost employee scheme.',
                'confidence_score' => 0.86,
                'model_name' => 'gpt-4',
                'prompt_version' => 'fraud-extraction-v1',
                'structured_payload' => ['scenario_title' => 'Ghost Employee'],
                'expected_indicators' => [
                    [
                        'indicator_key' => 'missing_personnel_file',
                        'indicator_name' => 'Missing Personnel File',
                        'severity' => 'High',
                        'should_detect' => true,
                    ],
                ],
                'expected_findings' => [
                    [
                        'finding_key' => 'potential_ghost_employee',
                        'finding_title' => 'Potential Ghost Employee',
                        'finding_description' => 'Employee has no supporting records.',
                        'expected_risk_score' => 90,
                        'expected_confidence' => 'High',
                    ],
                ],
                'document_requests' => [
                    ['document_name' => 'Payroll Register', 'priority' => 'High'],
                ],
                'investigation_questions' => [
                    ['question' => 'Who approved this employee?', 'priority' => 'High'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('extraction_status', 'completed');

        $this->assertDatabaseHas('fraud_scenario_extractions', [
            'scenario_submission_id' => $id,
            'fraud_category' => 'Payroll Fraud',
        ]);
        $this->assertDatabaseHas('fraud_expected_indicators', [
            'scenario_submission_id' => $id,
            'indicator_key' => 'missing_personnel_file',
        ]);
        $this->assertDatabaseHas('fraud_expected_findings', [
            'scenario_submission_id' => $id,
            'finding_key' => 'potential_ghost_employee',
        ]);
        $this->assertDatabaseHas('fraud_document_requests', [
            'scenario_submission_id' => $id,
            'document_name' => 'Payroll Register',
        ]);
        $this->assertDatabaseHas('fraud_investigation_questions', [
            'scenario_submission_id' => $id,
        ]);
        $this->assertDatabaseHas('fraud_scenario_submissions', [
            'id' => $id,
            'extraction_status' => 'completed',
        ]);
    }

    public function test_save_extraction_requires_structured_payload(): void
    {
        $id = $this->insertSubmission(['extraction_status' => 'processing']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-scenarios/{$id}/extraction", [
                'fraud_category' => 'Payroll Fraud',
            ])
            ->assertUnprocessable();
    }

    // ─── Save Mock Data ───────────────────────────────────────────────────────

    public function test_save_mock_data_creates_company_parties_transactions(): void
    {
        $id = $this->insertSubmission(['mock_data_status' => 'processing']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-scenarios/{$id}/mock-data", [
                'mock_company' => [
                    'company_name' => 'Acme Construction LLC',
                    'industry' => 'Construction',
                    'entity_type' => 'LLC',
                    'employee_count' => 20,
                    'months_of_activity' => 24,
                ],
                'parties' => [
                    ['external_party_id' => 'EMP-001', 'party_type' => 'Employee', 'party_name' => 'Jane Smith', 'is_fraud_actor' => false],
                    ['external_party_id' => 'EMP-GHOST', 'party_type' => 'Employee', 'party_name' => 'John Ghost', 'is_fraud_actor' => true],
                ],
                'transactions' => [
                    ['external_transaction_id' => 'TX-001', 'transaction_type' => 'Payroll Payment', 'amount' => 4500.00, 'external_party_id' => 'EMP-001', 'is_fraudulent' => false, 'transaction_date' => '2025-01-15'],
                    ['external_transaction_id' => 'TX-002', 'transaction_type' => 'Payroll Payment', 'amount' => 3200.00, 'external_party_id' => 'EMP-GHOST', 'is_fraudulent' => true, 'fraud_pattern' => 'ghost_employee', 'transaction_date' => '2025-01-15'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('mock_data_status', 'completed')
            ->assertJsonPath('party_count', 2)
            ->assertJsonPath('transaction_count', 2);

        $this->assertDatabaseHas('fraud_mock_companies', ['scenario_submission_id' => $id, 'company_name' => 'Acme Construction LLC']);
        $this->assertDatabaseHas('fraud_mock_parties', ['scenario_submission_id' => $id, 'party_name' => 'John Ghost', 'is_fraud_actor' => 1]);
        $this->assertDatabaseHas('fraud_mock_transactions', ['scenario_submission_id' => $id, 'is_fraudulent' => 1]);
        $this->assertDatabaseHas('fraud_scenario_submissions', ['id' => $id, 'mock_data_status' => 'completed']);
    }

    // ─── Fail ─────────────────────────────────────────────────────────────────

    public function test_fail_marks_extraction_as_failed(): void
    {
        $id = $this->insertSubmission(['extraction_status' => 'processing']);

        $this->withToken('test-fraud-token')
            ->postJson("/api/internal/fraud-scenarios/{$id}/fail", [
                'stage' => 'extraction',
                'error_message' => 'LLM returned invalid JSON',
            ])
            ->assertOk()
            ->assertJsonPath('scenario_id', $id);

        $this->assertDatabaseHas('fraud_scenario_submissions', [
            'id' => $id,
            'extraction_status' => 'failed',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function insertSubmission(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('fraud_scenario_submissions')->insert(array_merge([
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

    private function createFraudTables(): void
    {
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

        Schema::create('fraud_generation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id');
            $table->string('run_type');
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('input_payload')->nullable();
            $table->text('output_payload')->nullable();
            $table->text('errors')->nullable();
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

    private function dropFraudTables(): void
    {
        foreach ([
            'fraud_mock_transactions', 'fraud_mock_parties', 'fraud_mock_companies',
            'fraud_investigation_questions', 'fraud_document_requests',
            'fraud_expected_findings', 'fraud_expected_indicators',
            'fraud_scenario_extractions', 'fraud_generation_runs',
            'fraud_scenario_submissions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
