<?php

namespace Tests\Feature;

use App\Models\IrmDocument;
use App\Models\IrmSection;
use App\Services\IrmKnowledgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IrmKnowledgeToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-tool-key']);

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
    }

    public function test_service_search_returns_source_metadata_and_disclaimer(): void
    {
        $this->seedIrmSection(
            reference: '5.11.1.1',
            title: 'Notice of Levy',
            body: 'A levy is a legal seizure of property to satisfy a tax debt after notice and demand.'
        );

        $result = app(IrmKnowledgeService::class)->search('levy notice');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('levy notice', $result['query']);
        $this->assertNotEmpty($result['results']);
        $this->assertSame('5.11.1.1', $result['results'][0]['irm_reference']);
        $this->assertSame('Collection Process', $result['results'][0]['document_title']);
        $this->assertSame('irm', $result['results'][0]['source_type']);
        $this->assertArrayHasKey('disclaimer', $result);
    }

    public function test_service_can_fetch_exact_reference(): void
    {
        $this->seedIrmSection(reference: '5.1.10.3', title: 'Balance Due Notices');

        $result = app(IrmKnowledgeService::class)->section('5.1.10.3');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('5.1.10.3', $result['reference']);
        $this->assertSame('5.1.10.3', $result['results'][0]['irm_reference']);
    }

    public function test_service_returns_safe_empty_result_for_unknown_notice_code(): void
    {
        $result = app(IrmKnowledgeService::class)->explainNoticeType('UNKNOWN');

        $this->assertSame('no_results', $result['status']);
        $this->assertSame('UNKNOWN', $result['notice_code']);
        $this->assertSame([], $result['results']);
        $this->assertStringContainsString('No source-backed IRM sections were found', $result['summary']);
    }

    public function test_search_limit_is_respected(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1', title: 'Levy One', body: 'levy notice');
        $this->seedIrmSection(reference: '5.11.1.2', title: 'Levy Two', body: 'levy notice');

        $result = app(IrmKnowledgeService::class)->search('levy', 1);

        $this->assertCount(1, $result['results']);
    }

    public function test_endpoint_requires_agent_tool_auth(): void
    {
        $this->getJson('/api/internal/agent-tools/irs/irm/search?topic=levy')
            ->assertUnauthorized();
    }

    public function test_endpoint_search_returns_results(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1', title: 'Notice of Levy', body: 'levy notice');

        $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/irs/irm/search?topic=levy&limit=3')
            ->assertOk()
            ->assertJsonPath('query', 'levy')
            ->assertJsonPath('results.0.irm_reference', '5.11.1.1')
            ->assertJsonPath('results.0.source_type', 'irm')
            ->assertJsonStructure(['disclaimer']);
    }

    public function test_endpoint_validates_search_topic(): void
    {
        $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/irs/irm/search?topic=a')
            ->assertUnprocessable();
    }

    public function test_records_checklist_includes_recommended_records_and_sources(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1', title: 'Notice of Levy', body: 'levy collection due process');

        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/irs/records-checklist?issue_type=levy')
            ->assertOk();

        $this->assertNotEmpty($response->json('recommended_records'));
        $this->assertSame('5.11.1.1', $response->json('results.0.irm_reference'));
        $this->assertNotEmpty($response->json('disclaimer'));
    }

    private function seedIrmSection(
        string $reference,
        string $title,
        string $body = 'Sample IRM body text.'
    ): void {
        $document = IrmDocument::firstOrCreate(
            ['irm_reference' => substr($reference, 0, 6)],
            [
                'part_number' => 5,
                'chapter_number' => 11,
                'section_number' => 1,
                'title' => 'Collection Process',
                'effective_date' => '2026-05-30',
                's3_key' => 'irm/collection/sample.xml',
            ]
        );

        IrmSection::create([
            'irm_document_id' => $document->id,
            'irm_reference' => $reference,
            'depth' => 1,
            'title' => $title,
            'effective_date' => '2026-05-30',
            'body_text' => $body,
        ]);
    }
}
