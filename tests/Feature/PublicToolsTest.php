<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('irm_sections');
        Schema::dropIfExists('irm_documents');

        Schema::create('irm_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('irm_reference')->unique();
            $table->unsignedSmallInteger('part_number');
            $table->unsignedSmallInteger('chapter_number');
            $table->unsignedSmallInteger('section_number');
            $table->string('title');
            $table->string('catalog_number')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('audience')->nullable();
            $table->string('s3_key');
            $table->string('file_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('irm_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('irm_document_id')->constrained()->cascadeOnDelete();
            $table->string('xml_id')->nullable();
            $table->string('irm_reference');
            $table->unsignedTinyInteger('depth');
            $table->string('title')->nullable();
            $table->date('effective_date')->nullable();
            $table->longText('body_text');
            $table->timestamps();
        });

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

    public function test_irs_notice_lookup_search_requires_no_auth_and_includes_disclaimer(): void
    {
        $this->seedIrmSection(
            reference: '5.11.1.1',
            title: 'Notice of Levy',
            body: 'A levy is a legal seizure of property to satisfy a tax debt after notice and demand.'
        );

        $response = $this->getJson('/api/public/tools/irs-notice-lookup?topic=levy+notice')
            ->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('results.0.irm_reference', '5.11.1.1');

        $this->assertArrayHasKey('disclaimer', $response->json());
    }

    public function test_irs_notice_lookup_section_returns_exact_reference(): void
    {
        $this->seedIrmSection(reference: '5.1.10.3', title: 'Balance Due Notices');

        $this->getJson('/api/public/tools/irs-notice-lookup/section?reference=5.1.10.3')
            ->assertOk()
            ->assertJsonPath('reference', '5.1.10.3')
            ->assertJsonPath('results.0.irm_reference', '5.1.10.3');
    }

    public function test_irs_notice_lookup_is_rate_limited_per_ip(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1', title: 'Notice of Levy');

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/public/tools/irs-notice-lookup?topic=levy+notice')->assertOk();
        }

        $this->getJson('/api/public/tools/irs-notice-lookup?topic=levy+notice')
            ->assertStatus(429);
    }

    public function test_fraud_check_search_requires_no_auth_and_includes_disclaimer(): void
    {
        $sourceId = $this->createSource('Brevix Fraud Library');
        $this->createPlaybook($sourceId, [
            'title' => 'Duplicate invoice and split payment review',
            'category' => 'expense',
            'description' => 'Investigate repeated vendor invoices and split payments below approval thresholds.',
            'red_flags' => ['duplicate invoice number'],
        ]);

        $response = $this->getJson('/api/public/tools/fraud-check?query=duplicate+invoice')
            ->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('results.0.title', 'Duplicate invoice and split payment review');

        $this->assertSame(
            \App\Support\ProfessionalServicesDisclaimer::TEXT,
            $response->json('disclaimer')
        );
    }

    public function test_fraud_check_feedback_persists_without_auth_and_includes_disclaimer(): void
    {
        $playbookId = $this->createPlaybook($this->createSource('Brevix'), [
            'title' => 'Duplicate invoice review',
            'category' => 'expense',
        ]);

        $response = $this->postJson('/api/public/tools/fraud-check/feedback', [
            'playbook_id' => $playbookId,
            'query_text' => 'duplicate invoice',
            'relevance_score' => 5,
            'user_feedback' => 'This matched what I was looking for.',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Feedback submitted successfully');

        $this->assertSame(
            \App\Support\ProfessionalServicesDisclaimer::TEXT,
            $response->json('disclaimer')
        );

        $this->assertDatabaseHas('retrieval_feedback', [
            'playbook_id' => $playbookId,
            'query_text' => 'duplicate invoice',
            'relevance_score' => 5,
        ]);
    }

    public function test_internal_fraud_playbook_search_now_includes_disclaimer(): void
    {
        config(['services.brevix_agent.api_key' => 'test-tool-key']);
        $sourceId = $this->createSource('Brevix');
        $this->createPlaybook($sourceId, [
            'title' => 'Duplicate invoice review',
            'category' => 'expense',
            'description' => 'Investigate duplicate invoices.',
        ]);

        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/fraud/playbooks/search?query=duplicate')
            ->assertOk();

        $this->assertSame(
            \App\Support\ProfessionalServicesDisclaimer::TEXT,
            $response->json('disclaimer')
        );
    }

    private function seedIrmSection(string $reference, string $title, string $body = 'Body text.'): void
    {
        $documentId = DB::table('irm_documents')->insertGetId([
            'irm_reference' => $reference,
            'part_number' => 5,
            'chapter_number' => 11,
            'section_number' => 1,
            'title' => 'Collection Process',
            's3_key' => 'irm/'.$reference.'.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('irm_sections')->insert([
            'irm_document_id' => $documentId,
            'irm_reference' => $reference,
            'depth' => 3,
            'title' => $title,
            'body_text' => $body,
            'created_at' => now(),
            'updated_at' => now(),
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
