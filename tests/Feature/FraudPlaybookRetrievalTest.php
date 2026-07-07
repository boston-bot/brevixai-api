<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FraudPlaybookRetrievalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-tool-key']);

        Schema::dropIfExists('retrieval_feedback');
        Schema::dropIfExists('playbook_embeddings');
        Schema::dropIfExists('playbook_relationships');
        Schema::dropIfExists('playbook_versions');
        Schema::dropIfExists('investigation_playbooks');
        Schema::dropIfExists('playbook_sources');

        Schema::create('playbook_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
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

        Schema::create('retrieval_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->string('query_text');
            $table->integer('relevance_score');
            $table->text('user_feedback')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_search_requires_agent_tool_auth(): void
    {
        $this->getJson('/api/internal/agent-tools/fraud/playbooks/search?query=duplicate')
            ->assertUnauthorized();
    }

    public function test_search_returns_versioned_retrieval_contract_and_backward_compatible_data(): void
    {
        $sourceId = $this->createSource('Brevix Fraud Library');
        $matchingId = $this->createPlaybook($sourceId, [
            'title' => 'Duplicate invoice and split payment review',
            'category' => 'expense',
            'description' => 'Investigate repeated vendor invoices and split payments below approval thresholds.',
            'red_flags' => ['duplicate invoice number', 'split payments to the same vendor'],
            'tests' => ['match invoice numbers against vendor and payment date'],
            'document_requests' => ['vendor invoice register', 'approval log'],
            'intent_key' => 'suspected_fraud_or_missing_funds',
        ]);
        $this->createPlaybook($sourceId, [
            'title' => 'Ghost employee payroll review',
            'category' => 'payroll',
            'description' => 'Investigate payroll payments to employees with missing HR records.',
            'red_flags' => ['employee has no personnel file'],
        ]);
        $this->createPlaybook($sourceId, [
            'title' => 'Inactive duplicate invoice playbook',
            'category' => 'expense',
            'description' => 'This inactive item should not be retrieved.',
            'is_active' => false,
        ]);
        $this->createVersion($matchingId, '2026.07');

        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/fraud/playbooks/search?query=duplicate%20invoice%20split%20payment&limit=2')
            ->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('corpus_id', 'fraud_playbooks')
            ->assertJsonPath('corpus_version', 'fraud_playbooks:v2')
            ->assertJsonPath('scoring.strategy', 'lexical_playbook_v2')
            ->assertJsonPath('scoring.hybrid', false)
            ->assertJsonPath('results.0.title', 'Duplicate invoice and split payment review')
            ->assertJsonPath('results.0.confidence', 'high')
            ->assertJsonPath('results.0.citations.0.source_name', 'Brevix Fraud Library')
            ->assertJsonPath('results.0.citations.0.source_version', '2026.07')
            ->assertJsonPath('data.0.title', 'Duplicate invoice and split payment review');

        $this->assertContains('title', $response->json('results.0.citations.0.fields'));
        $this->assertNotContains('Inactive duplicate invoice playbook', array_column($response->json('data'), 'title'));
    }

    public function test_search_scores_json_fields_and_returns_snippet_provenance(): void
    {
        $sourceId = $this->createSource('ACFE');
        $this->createPlaybook($sourceId, [
            'title' => 'Related party supplier review',
            'category' => 'vendor',
            'description' => 'Review vendor master data for hidden relationships.',
            'red_flags' => ['same bank account shared by employee and vendor', 'shared home address'],
            'tests' => ['compare vendor bank accounts to employee direct deposit accounts'],
            'document_requests' => ['vendor master file', 'employee payroll file'],
        ]);

        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/fraud/playbooks/search?query=same%20bank%20account%20employee%20vendor')
            ->assertOk();

        $response->assertJsonPath('results.0.title', 'Related party supplier review')
            ->assertJsonPath('results.0.snippet_field', 'red_flags');

        $this->assertContains('red_flags', $response->json('results.0.citations.0.fields'));
        $this->assertStringContainsString('same bank account', $response->json('results.0.snippet'));
    }

    public function test_search_returns_safe_no_results_contract(): void
    {
        $this->createPlaybook($this->createSource('Brevix'), [
            'title' => 'Clean payroll reconciliation',
            'category' => 'payroll',
            'description' => 'No related keyword appears here.',
        ]);

        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/fraud/playbooks/search?query=cryptocurrency%20wallet%20seed')
            ->assertOk();

        $response->assertJsonPath('status', 'no_results')
            ->assertJsonPath('result_count', 0)
            ->assertJsonPath('results', [])
            ->assertJsonPath('data', []);
    }

    public function test_feedback_persists_relevance_review(): void
    {
        $playbookId = $this->createPlaybook($this->createSource('Brevix'), [
            'title' => 'Duplicate invoice review',
            'category' => 'expense',
        ]);

        $this->withToken('test-tool-key')
            ->postJson('/api/internal/agent-tools/fraud/playbooks/feedback', [
                'playbook_id' => $playbookId,
                'query_text' => 'duplicate invoice',
                'relevance_score' => 5,
                'user_feedback' => 'Matched the expected duplicate invoice playbook.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Feedback submitted successfully');

        $this->assertDatabaseHas('retrieval_feedback', [
            'playbook_id' => $playbookId,
            'query_text' => 'duplicate invoice',
            'relevance_score' => 5,
        ]);
    }

    private function createSource(string $name): int
    {
        return (int) DB::table('playbook_sources')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createPlaybook(int $sourceId, array $overrides): int
    {
        return (int) DB::table('investigation_playbooks')->insertGetId(array_merge([
            'source_id' => $sourceId,
            'title' => 'Default playbook',
            'category' => 'expense',
            'description' => null,
            'symptoms' => null,
            'red_flags' => null,
            'tests' => null,
            'document_requests' => null,
            'intent_key' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->encodeJsonFields($overrides)));
    }

    private function createVersion(int $playbookId, string $version): void
    {
        DB::table('playbook_versions')->insert([
            'playbook_id' => $playbookId,
            'version_number' => $version,
            'content_snapshot' => json_encode(['version' => $version]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function encodeJsonFields(array $values): array
    {
        foreach (['symptoms', 'red_flags', 'tests', 'document_requests'] as $field) {
            if (array_key_exists($field, $values) && is_array($values[$field])) {
                $values[$field] = json_encode($values[$field]);
            }
        }

        return $values;
    }
}
