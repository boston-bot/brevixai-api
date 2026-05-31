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

    public function test_service_can_fetch_document_level_reference(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1', title: 'Notice of Levy', body: 'levy document child text');

        $result = app(IrmKnowledgeService::class)->section('5.11.1');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('5.11.1', $result['reference']);
        $this->assertSame('5.11.1', $result['results'][0]['irm_reference']);
        $this->assertStringContainsString('levy document child text', $result['results'][0]['excerpt']);
    }

    public function test_service_groups_descendants_for_missing_parent_reference(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1.1', title: 'Notice of Levy', body: 'descendant levy procedure text');

        $result = app(IrmKnowledgeService::class)->section('5.11.1.1');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('5.11.1.1', $result['reference']);
        $this->assertSame('5.11.1.1', $result['results'][0]['irm_reference']);
        $this->assertSame('Notice of Levy', $result['results'][0]['section_title']);
        $this->assertStringContainsString('descendant levy procedure text', $result['results'][0]['excerpt']);
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

    public function test_records_checklist_maps_cp504_to_levy_sources(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1', title: 'Notice of Levy', body: 'levy notice intent to levy balance due collection');

        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/irs/records-checklist?issue_type=CP504')
            ->assertOk();

        $this->assertSame('levy notice intent to levy balance due collection', $response->json('query'));
        $this->assertSame('5.11.1.1', $response->json('results.0.irm_reference'));
        $this->assertContains('The IRS notice or letter, including all pages and dates.', $response->json('recommended_records'));
    }

    public function test_collection_risk_maps_lt11_to_levy_sources(): void
    {
        $this->seedIrmSection(reference: '5.11.1.1', title: 'Notice of Levy', body: 'notice intent to levy collection due process hearing');

        $result = app(IrmKnowledgeService::class)->summarizeCollectionRisk('LT11');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('notice intent to levy collection due process hearing', $result['query']);
        $this->assertSame('critical', $result['severity']);
        $this->assertSame('5.11.1.1', $result['results'][0]['irm_reference']);
    }

    // -------------------------------------------------------------------------
    // Relevance ranking tests
    // -------------------------------------------------------------------------

    public function test_levy_notice_ranks_collection_sections_above_noise(): void
    {
        $this->seedSection('1.1.1.1', 'General Introduction', 'levy procedures are described throughout this manual');
        $this->seedSection('5.11.1.2', 'Notice of Intent to Levy', 'levy notice collection intent to levy due process balance due');

        $result = app(IrmKnowledgeService::class)->search('levy notice');

        $this->assertSame('ok', $result['status']);
        $top = $result['results'][0]['irm_reference'];
        $this->assertMatchesRegularExpression('/^5\.(11|19)\./', $top,
            "Top result should be 5.11.* or 5.19.*, got {$top}");
    }

    public function test_cp504_query_ranks_levy_sections_above_noise(): void
    {
        $this->seedSection('1.1.1.1', 'General Introduction', 'levy collection intent balance due');
        $this->seedSection('5.11.2.1', 'Levy on Wages', 'levy notice intent to levy balance due collection');

        $result = app(IrmKnowledgeService::class)->explainNoticeType('CP504');

        $this->assertSame('ok', $result['status']);
        $top = $result['results'][0]['irm_reference'];
        $this->assertMatchesRegularExpression('/^5\.(11|19)\./', $top,
            "CP504 top result should be 5.11.* or 5.19.*, got {$top}");
    }

    public function test_lt11_query_ranks_levy_cdp_sections_above_noise(): void
    {
        $this->seedSection('1.1.1.1', 'General Policy', 'collection due process hearing levy procedures');
        $this->seedSection('5.11.3.1', 'CDP Levy Hearing', 'notice intent to levy collection due process hearing cdp');

        $result = app(IrmKnowledgeService::class)->summarizeCollectionRisk('LT11');

        $this->assertSame('ok', $result['status']);
        $top = $result['results'][0]['irm_reference'];
        $this->assertMatchesRegularExpression('/^5\.(11|19)\./', $top,
            "LT11 top result should be 5.11.* or 5.19.*, got {$top}");
    }

    public function test_cp2000_query_ranks_underreporter_sections_above_noise(): void
    {
        $this->seedSection('1.1.1.1', 'General Introduction', 'CP2000 proposed changes notice underreported income');
        $this->seedSection('4.19.1.1', 'Underreporter Program Overview', 'CP2000 underreported income proposed changes notice deficiency exam');

        $result = app(IrmKnowledgeService::class)->explainNoticeType('CP2000');

        $this->assertSame('ok', $result['status']);
        $top = $result['results'][0]['irm_reference'];
        $this->assertMatchesRegularExpression('/^(4\.(19|10)|20\.1)\./', $top,
            "CP2000 top result should be 4.19.*, 20.1.*, or 4.10.*, got {$top}");
    }

    public function test_trust_fund_recovery_penalty_ranks_tfrp_sections_above_noise(): void
    {
        $this->seedSection('1.1.1.1', 'General Introduction', 'employment tax trust fund recovery penalty responsible person');
        $this->seedSection('5.7.1.1', 'Trust Fund Recovery Penalty', 'trust fund recovery penalty TFRP responsible person payroll employment tax');

        $result = app(IrmKnowledgeService::class)->summarizeCollectionRisk('trust fund recovery penalty');

        $this->assertSame('ok', $result['status']);
        $top = $result['results'][0]['irm_reference'];
        $this->assertMatchesRegularExpression('/^(5\.7|8\.25|20\.1)\./', $top,
            "TFRP top result should be 5.7.*, 8.25.*, or 20.1.*, got {$top}");
    }

    public function test_exact_irm_reference_search_returns_matching_section(): void
    {
        $this->seedSection('5.11.1.1', 'Levy Overview', 'levy notice seizure collection');

        $result = app(IrmKnowledgeService::class)->section('5.11.1.1');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('5.11.1.1', $result['results'][0]['irm_reference']);
    }

    public function test_unknown_notice_code_returns_empty_safe_result(): void
    {
        $result = app(IrmKnowledgeService::class)->explainNoticeType('CP9999');

        $this->assertSame('no_results', $result['status']);
        $this->assertSame([], $result['results']);
        $this->assertStringContainsString('No source-backed IRM sections were found', $result['summary']);
    }

    private function seedIrmSection(
        string $reference,
        string $title,
        string $body = 'Sample IRM body text.'
    ): void {
        $document = IrmDocument::firstOrCreate(
            ['irm_reference' => $this->documentReferenceFor($reference)],
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

    private function documentReferenceFor(string $reference): string
    {
        $parts = explode('.', $reference);
        if (count($parts) >= 3) {
            return implode('.', array_slice($parts, 0, 3));
        }

        return $reference;
    }

    private function seedSection(string $reference, string $title, string $body = 'Sample IRM body text.'): void
    {
        $parts = explode('.', $reference);
        $docRef = implode('.', array_slice($parts, 0, 3));
        $partNum = max(1, (int) ($parts[0] ?? 1));
        $chapterNum = max(1, (int) ($parts[1] ?? 1));
        $sectionNum = max(1, (int) ($parts[2] ?? 1));

        $document = IrmDocument::firstOrCreate(
            ['irm_reference' => $docRef],
            [
                'part_number' => $partNum,
                'chapter_number' => $chapterNum,
                'section_number' => $sectionNum,
                'title' => "IRM {$docRef}",
                'effective_date' => '2026-05-30',
                's3_key' => "irm/{$docRef}/sample.xml",
            ]
        );

        IrmSection::create([
            'irm_document_id' => $document->id,
            'irm_reference' => $reference,
            'depth' => max(0, count($parts) - 3),
            'title' => $title,
            'effective_date' => '2026-05-30',
            'body_text' => $body,
        ]);
    }
}
