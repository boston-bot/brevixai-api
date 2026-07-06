<?php

namespace Tests\Feature;

use App\Models\Fraud\InvestigationPlaybook;
use App\Models\Fraud\RetrievalFeedback;
use App\Services\Retrieval\FraudPlaybookRetriever;
use App\Services\Retrieval\RetrievalQuery;
use App\Services\Retrieval\RetrievalService;
use Database\Seeders\InvestigationPlaybookSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression-tests the seeded production corpus against the committed
 * evaluation fixture, so corpus or ranking changes that break real user
 * queries fail CI instead of production.
 */
class FraudPlaybookCorpusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $this->seed(InvestigationPlaybookSeeder::class);
    }

    public function test_seeder_loads_the_full_corpus_idempotently(): void
    {
        $firstCount = InvestigationPlaybook::count();
        $this->assertGreaterThanOrEqual(15, $firstCount);

        // Re-running must not duplicate.
        $this->seed(InvestigationPlaybookSeeder::class);
        $this->assertSame($firstCount, InvestigationPlaybook::count());
    }

    public function test_evaluation_command_passes_against_the_seeded_corpus(): void
    {
        $this->artisan('retrieval:evaluate-fraud-playbooks')
            ->expectsOutputToContain('retrieval evaluation passed')
            ->assertExitCode(0);
    }

    public function test_lay_language_query_expansion_reaches_the_right_playbook(): void
    {
        $response = app(RetrievalService::class)->search(new RetrievalQuery(
            corpusId: FraudPlaybookRetriever::CORPUS_ID,
            query: 'my bookkeeper quit suddenly',
            limit: 3,
        ))->toArray();

        $this->assertSame('ok', $response['status']);
        $this->assertSame('fraud_playbooks:v2', $response['corpus_version']);
        $this->assertSame(
            'Bookkeeper transition and segregation of duties review',
            $response['results'][0]['title']
        );
        $this->assertNotEmpty($response['metadata']['expanded_terms']);
    }

    public function test_feedback_boost_lifts_consistently_helpful_playbooks(): void
    {
        $baseline = app(RetrievalService::class)->search(new RetrievalQuery(
            corpusId: FraudPlaybookRetriever::CORPUS_ID,
            query: 'vendor payments look wrong',
            limit: 5,
        ))->toArray();

        $this->assertSame('ok', $baseline['status']);
        $this->assertGreaterThanOrEqual(2, count($baseline['results']));

        // Give the #2 result strong feedback and the #1 result poor feedback.
        $first = $baseline['results'][0];
        $second = $baseline['results'][1];

        foreach ([1, 1, 1] as $score) {
            RetrievalFeedback::create([
                'playbook_id' => (int) $first['source_id'],
                'query_text' => 'vendor payments look wrong',
                'relevance_score' => $score,
            ]);
        }
        foreach ([5, 5, 5] as $score) {
            RetrievalFeedback::create([
                'playbook_id' => (int) $second['source_id'],
                'query_text' => 'vendor payments look wrong',
                'relevance_score' => $score,
            ]);
        }

        $boosted = app(RetrievalService::class)->search(new RetrievalQuery(
            corpusId: FraudPlaybookRetriever::CORPUS_ID,
            query: 'vendor payments look wrong',
            limit: 5,
        ))->toArray();

        $this->assertTrue($boosted['metadata']['feedback_boost_applied']);

        $firstResult = collect($boosted['results'])->firstWhere('source_id', $first['source_id']);
        $secondResult = collect($boosted['results'])->firstWhere('source_id', $second['source_id']);

        $this->assertNotNull($firstResult);
        $this->assertNotNull($secondResult);
        $this->assertLessThan((float) $first['relevance_score'], (float) $firstResult['relevance_score']);
        $this->assertGreaterThan((float) $second['relevance_score'], (float) $secondResult['relevance_score']);
    }
}
