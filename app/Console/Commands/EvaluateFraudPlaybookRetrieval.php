<?php

namespace App\Console\Commands;

use App\Services\Retrieval\FraudPlaybookRetriever;
use App\Services\Retrieval\RetrievalQuery;
use App\Services\Retrieval\RetrievalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EvaluateFraudPlaybookRetrieval extends Command
{
    protected $signature = 'retrieval:evaluate-fraud-playbooks
        {--fixture=docs/retrieval/fraud-playbook-eval.json : JSON scenario fixture path}
        {--limit=5 : Retrieval limit per scenario}';

    protected $description = 'Evaluate fraud playbook retrieval relevance against expected query scenarios.';

    public function handle(RetrievalService $retrieval): int
    {
        $fixturePath = $this->resolvePath((string) $this->option('fixture'));
        if (! File::exists($fixturePath)) {
            $this->error("Retrieval evaluation fixture not found: {$fixturePath}");

            return self::FAILURE;
        }

        $fixture = json_decode(File::get($fixturePath), true);
        if (! is_array($fixture) || ! is_array($fixture['scenarios'] ?? null)) {
            $this->error('Retrieval evaluation fixture must contain a scenarios array.');

            return self::FAILURE;
        }

        $limit = max(1, min((int) $this->option('limit'), 20));
        $failures = 0;
        $scenarioCount = 0;

        foreach ($fixture['scenarios'] as $index => $scenario) {
            if (! is_array($scenario)) {
                $failures++;
                $this->line("<fg=red>FAIL</> scenario {$index}: scenario must be an object.");
                continue;
            }

            $scenarioCount++;
            $query = trim((string) ($scenario['query'] ?? ''));
            $expectedTitle = trim((string) ($scenario['expected_top_title'] ?? ''));
            $minConfidence = trim((string) ($scenario['min_confidence'] ?? 'low'));
            $mustNotMatch = array_values(array_filter((array) ($scenario['must_not_match_titles'] ?? []), 'is_string'));

            $response = $retrieval->search(new RetrievalQuery(
                corpusId: FraudPlaybookRetriever::CORPUS_ID,
                query: $query,
                limit: $limit,
            ))->toArray();

            $top = $response['results'][0] ?? null;
            $topTitle = is_array($top) ? (string) ($top['title'] ?? '') : '';
            $topConfidence = is_array($top) ? (string) ($top['confidence'] ?? 'low') : 'low';

            $passed = $query !== ''
                && $expectedTitle !== ''
                && $topTitle === $expectedTitle
                && $this->confidenceRank($topConfidence) >= $this->confidenceRank($minConfidence)
                && ! in_array($topTitle, $mustNotMatch, true);

            if ($passed) {
                $this->line("<fg=green>PASS</> {$query} -> {$topTitle} ({$topConfidence})");
                continue;
            }

            $failures++;
            $this->line("<fg=red>FAIL</> {$query} -> {$topTitle} ({$topConfidence}); expected {$expectedTitle} >= {$minConfidence}");
        }

        if ($failures > 0) {
            $this->error("Fraud playbook retrieval evaluation failed: {$failures}/{$scenarioCount} scenario(s).");

            return self::FAILURE;
        }

        $this->info("Fraud playbook retrieval evaluation passed: {$scenarioCount}/{$scenarioCount} scenario(s).");

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function confidenceRank(string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
