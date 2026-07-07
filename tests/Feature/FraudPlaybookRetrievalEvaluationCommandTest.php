<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FraudPlaybookRetrievalEvaluationCommandTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('playbook_versions');
        Schema::dropIfExists('investigation_playbooks');
        Schema::dropIfExists('playbook_sources');

        Schema::create('playbook_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('investigation_playbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('playbook_sources')->nullOnDelete();
            $table->string('title');
            $table->string('category');
            $table->text('description')->nullable();
            $table->json('symptoms')->nullable();
            $table->json('red_flags')->nullable();
            $table->json('tests')->nullable();
            $table->json('document_requests')->nullable();
            $table->string('intent_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('playbook_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->string('version_number');
            $table->json('content_snapshot');
            $table->timestamps();
        });

        $this->fixturePath = storage_path('framework/testing/fraud-playbook-eval.json');
        File::delete($this->fixturePath);
    }

    protected function tearDown(): void
    {
        File::delete($this->fixturePath);

        parent::tearDown();
    }

    public function test_evaluation_command_passes_expected_relevance_scenarios(): void
    {
        $this->seedPlaybooks();
        File::put($this->fixturePath, json_encode([
            'scenarios' => [
                [
                    'query' => 'duplicate invoice split payment',
                    'expected_top_title' => 'Duplicate invoice and split payment review',
                    'min_confidence' => 'high',
                    'must_not_match_titles' => ['Ghost employee payroll review'],
                ],
                [
                    'query' => 'same bank account employee vendor',
                    'expected_top_title' => 'Related party supplier review',
                    'min_confidence' => 'medium',
                ],
            ],
        ]));

        $this->artisan('retrieval:evaluate-fraud-playbooks', [
            '--fixture' => $this->fixturePath,
        ])
            ->expectsOutputToContain('PASS duplicate invoice split payment')
            ->expectsOutputToContain('PASS same bank account employee vendor')
            ->assertExitCode(0);
    }

    public function test_evaluation_command_fails_on_wrong_expected_match(): void
    {
        $this->seedPlaybooks();
        File::put($this->fixturePath, json_encode([
            'scenarios' => [
                [
                    'query' => 'duplicate invoice split payment',
                    'expected_top_title' => 'Ghost employee payroll review',
                    'min_confidence' => 'medium',
                ],
            ],
        ]));

        $this->artisan('retrieval:evaluate-fraud-playbooks', [
            '--fixture' => $this->fixturePath,
        ])
            ->expectsOutputToContain('FAIL duplicate invoice split payment')
            ->assertExitCode(1);
    }

    private function seedPlaybooks(): void
    {
        $sourceId = (int) DB::table('playbook_sources')->insertGetId([
            'name' => 'Brevix Fraud Library',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('investigation_playbooks')->insert([
            [
                'source_id' => $sourceId,
                'title' => 'Duplicate invoice and split payment review',
                'category' => 'expense',
                'description' => 'Investigate repeated vendor invoices and split payments below approval thresholds.',
                'symptoms' => json_encode(['duplicate invoice paid twice']),
                'red_flags' => json_encode(['split payments to the same vendor']),
                'tests' => json_encode(['match invoice number and payment date']),
                'document_requests' => json_encode(['vendor invoice register']),
                'intent_key' => 'suspected_fraud_or_missing_funds',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'source_id' => $sourceId,
                'title' => 'Related party supplier review',
                'category' => 'vendor',
                'description' => 'Review vendor master data for hidden relationships.',
                'symptoms' => json_encode(['new vendor with employee overlap']),
                'red_flags' => json_encode(['same bank account shared by employee and vendor']),
                'tests' => json_encode(['compare vendor bank account to employee direct deposit']),
                'document_requests' => json_encode(['vendor master file']),
                'intent_key' => 'vendor_payment_controls',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'source_id' => $sourceId,
                'title' => 'Ghost employee payroll review',
                'category' => 'payroll',
                'description' => 'Investigate payroll payments to employees with missing HR records.',
                'symptoms' => json_encode(['payroll recipient has no personnel file']),
                'red_flags' => json_encode(['inactive employee receives payroll']),
                'tests' => json_encode(['compare payroll file to HR roster']),
                'document_requests' => json_encode(['payroll register']),
                'intent_key' => 'suspected_fraud_or_missing_funds',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
