<?php

namespace App\Http\Controllers\FraudTesting;

use App\Http\Controllers\Controller;
use App\Models\FraudTesting\FraudGenerationRun;
use App\Models\FraudTesting\FraudScenarioSubmission;
use App\Services\FraudTesting\FraudScenarioExtractionService;
use App\Services\FraudTesting\FraudScenarioMockDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FraudScenarioAgentController extends Controller
{
    public function __construct(
        protected readonly FraudScenarioExtractionService $extractionService,
        protected readonly FraudScenarioMockDataService $mockDataService,
    ) {}

    /**
     * GET /api/internal/fraud-scenarios/pending
     */
    public function pending(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query('limit', 10)), 50);

        $scenarios = FraudScenarioSubmission::where('extraction_status', FraudScenarioSubmission::EXTRACTION_STATUS_PENDING)
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'external_scenario_id', 'title', 'narrative', 'source', 'severity']);

        return response()->json(['data' => $scenarios]);
    }

    /**
     * POST /api/internal/fraud-scenarios/{id}/claim
     */
    public function claim(string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        if ($submission->extraction_status !== FraudScenarioSubmission::EXTRACTION_STATUS_PENDING) {
            return response()->json(['error' => 'Scenario is not in pending state', 'extraction_status' => $submission->extraction_status], 409);
        }

        $run = $this->extractionService->claimScenario($submission);

        return response()->json([
            'scenario_id' => $submission->id,
            'run_id' => $run->id,
            'extraction_status' => $submission->fresh()->extraction_status,
        ]);
    }

    /**
     * POST /api/internal/fraud-scenarios/{id}/extraction
     */
    public function saveExtraction(Request $request, string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        $request->validate([
            'structured_payload' => 'required|array',
            'fraud_category' => 'nullable|string',
            'industry' => 'nullable|string',
            'actor_type' => 'nullable|string',
            'concealment_method' => 'nullable|string',
            'summary' => 'nullable|string',
            'confidence_score' => 'nullable|numeric|min:0|max:1',
            'model_name' => 'nullable|string',
            'prompt_version' => 'nullable|string',
            'expected_indicators' => 'nullable|array',
            'expected_findings' => 'nullable|array',
            'document_requests' => 'nullable|array',
            'investigation_questions' => 'nullable|array',
        ]);

        $extraction = $this->extractionService->saveExtraction($submission, $request->all());

        return response()->json([
            'extraction_id' => $extraction->id,
            'scenario_id' => $submission->id,
            'extraction_status' => FraudScenarioSubmission::EXTRACTION_STATUS_COMPLETED,
        ]);
    }

    /**
     * POST /api/internal/fraud-scenarios/{id}/mock-data
     */
    public function saveMockData(Request $request, string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        $request->validate([
            'mock_company' => 'required|array',
            'mock_company.company_name' => 'required|string',
            'parties' => 'nullable|array',
            'transactions' => 'nullable|array',
        ]);

        $mockCompany = $this->mockDataService->saveMockData($submission, $request->all());

        return response()->json([
            'mock_company_id' => $mockCompany->id,
            'scenario_id' => $submission->id,
            'mock_data_status' => FraudScenarioSubmission::MOCK_DATA_STATUS_COMPLETED,
            'party_count' => count($request->input('parties', [])),
            'transaction_count' => count($request->input('transactions', [])),
        ]);
    }

    /**
     * GET /api/internal/fraud-scenarios/{id}/extraction-prompt
     * Returns the extraction prompt with the scenario narrative embedded — paste into ChatGPT.
     */
    public function extractionPrompt(string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        $template = file_get_contents(resource_path('prompts/fraud_testing/extraction_v1.md'));
        $prompt = str_replace('{{NARRATIVE}}', $submission->narrative ?? '', $template);

        return response()->json([
            'scenario_id' => $submission->id,
            'external_scenario_id' => $submission->external_scenario_id,
            'title' => $submission->title,
            'extraction_status' => $submission->extraction_status,
            'prompt' => $prompt,
        ]);
    }

    /**
     * GET /api/internal/fraud-scenarios/{id}/mock-data-prompt
     * Returns the mock data prompt with the saved extraction embedded — paste into ChatGPT.
     */
    public function mockDataPrompt(string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::with([
            'extraction',
            'expectedIndicators',
            'expectedFindings',
            'documentRequests',
            'investigationQuestions',
        ])->find($id);

        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        if (! $submission->extraction) {
            return response()->json(['error' => 'Extraction not completed for this scenario'], 422);
        }

        $extraction = $submission->extraction;

        $extractionJson = json_encode([
            'fraud_category' => $extraction->fraud_category,
            'industry' => $extraction->industry,
            'actor_type' => $extraction->actor_type,
            'concealment_method' => $extraction->concealment_method,
            'summary' => $extraction->summary,
            'confidence_score' => $extraction->confidence_score,
            'structured_payload' => $extraction->structured_payload,
            'expected_indicators' => $submission->expectedIndicators->map(fn ($i) => [
                'indicator_key' => $i->indicator_key,
                'indicator_name' => $i->indicator_name,
                'indicator_category' => $i->indicator_category,
                'description' => $i->description,
                'severity' => $i->severity,
                'data_needed' => $i->data_needed,
                'should_detect' => $i->should_detect,
            ])->values()->all(),
            'expected_findings' => $submission->expectedFindings->map(fn ($f) => [
                'finding_key' => $f->finding_key,
                'finding_title' => $f->finding_title,
                'finding_description' => $f->finding_description,
                'expected_risk_score' => $f->expected_risk_score,
                'expected_confidence' => $f->expected_confidence,
                'recommended_action' => $f->recommended_action,
                'expected_user_message' => $f->expected_user_message,
            ])->values()->all(),
            'document_requests' => $submission->documentRequests->map(fn ($d) => [
                'document_name' => $d->document_name,
                'why_needed' => $d->why_needed,
                'priority' => $d->priority,
                'expected_issue_found' => $d->expected_issue_found,
            ])->values()->all(),
            'investigation_questions' => $submission->investigationQuestions->map(fn ($q) => [
                'question' => $q->question,
                'asked_to' => $q->asked_to,
                'why_question_matters' => $q->why_question_matters,
                'priority' => $q->priority,
            ])->values()->all(),
        ], JSON_PRETTY_PRINT);

        $template = file_get_contents(resource_path('prompts/fraud_testing/mock_data_v1.md'));
        $prompt = str_replace('{{STRUCTURED_EXTRACTION_JSON}}', $extractionJson, $template);

        return response()->json([
            'scenario_id' => $submission->id,
            'external_scenario_id' => $submission->external_scenario_id,
            'title' => $submission->title,
            'mock_data_status' => $submission->mock_data_status,
            'prompt' => $prompt,
        ]);
    }

    /**
     * POST /api/internal/fraud-scenarios/{id}/fail
     */
    public function fail(Request $request, string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        $request->validate([
            'stage' => 'required|in:extraction,mock_data',
            'error_message' => 'required|string',
            'errors' => 'nullable|array',
        ]);

        $this->extractionService->markFailed(
            $submission,
            $request->input('stage'),
            $request->input('error_message'),
            $request->input('errors', []),
        );

        return response()->json(['message' => 'Failure recorded', 'scenario_id' => $submission->id]);
    }

    /**
     * POST /api/internal/fraud-scenarios/{id}/mock-data-claim
     */
    public function claimMockData(string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        $submission->update(['mock_data_status' => FraudScenarioSubmission::MOCK_DATA_STATUS_PROCESSING]);

        $run = FraudGenerationRun::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scenario_submission_id' => $submission->id,
            'run_type' => FraudGenerationRun::RUN_TYPE_MOCK_DATA_GENERATION,
            'status' => FraudGenerationRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        return response()->json([
            'scenario_id' => $submission->id,
            'run_id' => $run->id,
            'mock_data_status' => $submission->fresh()->mock_data_status,
        ]);
    }
}
