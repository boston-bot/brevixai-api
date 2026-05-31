<?php

namespace Tests\Feature;

use App\Models\IrmDocument;
use App\Models\IrmSection;
use App\Services\IrsNoticeExtractionService;
use App\Services\IrsTaxNoticeService;
use App\Services\IrmKnowledgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IrsNoticeExtractionToolTest extends TestCase
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

    private function fakeExtraction(
        string $noticeType = 'CP504',
        string $riskLevel = 'critical',
        ?int $deadlineDays = 30,
    ): array {
        return [
            'notice_type' => $noticeType,
            'deadline_days' => $deadlineDays,
            'deadline_description' => $deadlineDays !== null ? "{$deadlineDays}-day window from notice date" : 'See notice for deadline details.',
            'required_action' => 'Pay in full or file Form 9465 to stop levy action.',
            'risk_level' => $riskLevel,
            'key_amount' => 5000.00,
            'summary' => "CP504 is an urgent notice of intent to levy. You have {$deadlineDays} days to respond.",
            'disclaimer' => 'For informational purposes only.',
        ];
    }

    private function mockTaxNoticeService(array $extraction): void
    {
        $mock = $this->createMock(IrsTaxNoticeService::class);
        $mock->method('interpretNotice')->willReturn($extraction);
        $this->app->instance(IrsTaxNoticeService::class, $mock);
    }

    private function seedIrmSection(string $reference, string $title, string $body): void
    {
        $parts = explode('.', $reference);
        $docRef = implode('.', array_slice($parts, 0, 3));

        $document = IrmDocument::firstOrCreate(
            ['irm_reference' => $docRef],
            [
                'part_number' => (int) ($parts[0] ?? 5),
                'chapter_number' => (int) ($parts[1] ?? 11),
                'section_number' => (int) ($parts[2] ?? 1),
                'title' => "IRM {$docRef}",
                'effective_date' => '2026-05-31',
                's3_key' => "irm/{$docRef}/sample.xml",
            ]
        );

        IrmSection::create([
            'irm_document_id' => $document->id,
            'irm_reference' => $reference,
            'depth' => max(0, count($parts) - 3),
            'title' => $title,
            'effective_date' => '2026-05-31',
            'body_text' => $body,
        ]);
    }

    public function test_service_extracts_notice_and_returns_irm_sections(): void
    {
        $this->mockTaxNoticeService($this->fakeExtraction('CP504'));
        $this->seedIrmSection('5.11.1.1', 'Notice of Levy', 'levy notice intent to levy balance due collection');

        $result = app(IrsNoticeExtractionService::class)->extract(
            'CP504: Intent to levy. You owe $5,000. Respond within 30 days.',
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame('CP504', $result['notice_type']);
        $this->assertSame('critical', $result['risk_level']);
        $this->assertSame(30, $result['deadline_days']);
        $this->assertNotEmpty($result['results']);
        $this->assertSame('5.11.1.1', $result['results'][0]['irm_reference']);
        $this->assertNotEmpty($result['disclaimer']);
    }

    public function test_service_sets_irm_search_topic_from_notice_type(): void
    {
        $this->mockTaxNoticeService($this->fakeExtraction('LT11'));
        $this->seedIrmSection('5.11.2.1', 'CDP Hearing', 'notice intent to levy collection due process hearing');

        $result = app(IrsNoticeExtractionService::class)->extract(
            'LT11: Final notice of intent to levy. You have 30 days to request a CDP hearing.',
        );

        $this->assertSame('LT11', $result['notice_type']);
        $this->assertNotNull($result['irm_search_topic']);
        $this->assertStringContainsString('levy', $result['irm_search_topic']);
    }

    public function test_service_returns_empty_irm_results_for_unknown_notice_type(): void
    {
        $this->mockTaxNoticeService($this->fakeExtraction('Unknown', 'medium', null));
        // fakeExtraction with Unknown shouldn't trigger IRM search

        $result = app(IrsNoticeExtractionService::class)->extract(
            'This is some text that does not look like a known IRS notice code clearly.',
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame('Unknown', $result['notice_type']);
        $this->assertSame([], $result['results']);
        $this->assertNull($result['irm_search_topic']);
    }

    public function test_endpoint_requires_agent_tool_auth(): void
    {
        $this->postJson('/api/internal/agent-tools/irs/notice/extract', ['text' => 'CP504 notice text here.'])
            ->assertUnauthorized();
    }

    public function test_endpoint_validates_minimum_text_length(): void
    {
        $this->withToken('test-tool-key')
            ->postJson('/api/internal/agent-tools/irs/notice/extract', ['text' => 'too short'])
            ->assertUnprocessable();
    }

    public function test_endpoint_returns_extraction_with_irm_sections(): void
    {
        $this->mockTaxNoticeService($this->fakeExtraction('CP504'));
        $this->seedIrmSection('5.11.1.1', 'Notice of Levy', 'levy notice intent to levy balance due collection');

        $this->withToken('test-tool-key')
            ->postJson('/api/internal/agent-tools/irs/notice/extract', [
                'text' => 'CP504: Urgent notice of intent to levy. You owe $5,000 and must respond within 30 days.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('notice_type', 'CP504')
            ->assertJsonPath('risk_level', 'critical')
            ->assertJsonPath('results.0.irm_reference', '5.11.1.1')
            ->assertJsonStructure(['deadline_days', 'required_action', 'summary', 'disclaimer']);
    }

    public function test_endpoint_unknown_notice_type_returns_empty_results(): void
    {
        $this->mockTaxNoticeService($this->fakeExtraction('Unknown', 'medium', null));

        $this->withToken('test-tool-key')
            ->postJson('/api/internal/agent-tools/irs/notice/extract', [
                'text' => 'This notice text does not match any known IRS notice pattern at all here.',
            ])
            ->assertOk()
            ->assertJsonPath('notice_type', 'Unknown')
            ->assertJsonPath('results', []);
    }
}
