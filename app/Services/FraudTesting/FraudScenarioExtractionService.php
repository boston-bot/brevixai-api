<?php

namespace App\Services\FraudTesting;

use App\Models\FraudTesting\FraudDocumentRequest;
use App\Models\FraudTesting\FraudExpectedFinding;
use App\Models\FraudTesting\FraudExpectedIndicator;
use App\Models\FraudTesting\FraudGenerationRun;
use App\Models\FraudTesting\FraudInvestigationQuestion;
use App\Models\FraudTesting\FraudScenarioExtraction;
use App\Models\FraudTesting\FraudScenarioSubmission;
use Illuminate\Support\Str;

class FraudScenarioExtractionService
{
    public function claimScenario(FraudScenarioSubmission $submission): FraudGenerationRun
    {
        $submission->update(['extraction_status' => FraudScenarioSubmission::EXTRACTION_STATUS_PROCESSING]);

        return FraudGenerationRun::create([
            'id' => (string) Str::uuid(),
            'scenario_submission_id' => $submission->id,
            'run_type' => FraudGenerationRun::RUN_TYPE_EXTRACTION,
            'status' => FraudGenerationRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function saveExtraction(FraudScenarioSubmission $submission, array $data): FraudScenarioExtraction
    {
        FraudScenarioExtraction::where('scenario_submission_id', $submission->id)->delete();

        $extraction = FraudScenarioExtraction::create([
            'id' => (string) Str::uuid(),
            'scenario_submission_id' => $submission->id,
            'fraud_category' => $data['fraud_category'] ?? null,
            'industry' => $data['industry'] ?? null,
            'actor_type' => $data['actor_type'] ?? null,
            'concealment_method' => $data['concealment_method'] ?? null,
            'summary' => $data['summary'] ?? null,
            'structured_payload' => $data['structured_payload'] ?? [],
            'confidence_score' => $data['confidence_score'] ?? null,
            'model_name' => $data['model_name'] ?? null,
            'prompt_version' => $data['prompt_version'] ?? null,
        ]);

        $this->upsertExpectedIndicators($submission->id, $data['expected_indicators'] ?? []);
        $this->upsertExpectedFindings($submission->id, $data['expected_findings'] ?? []);
        $this->upsertDocumentRequests($submission->id, $data['document_requests'] ?? []);
        $this->upsertInvestigationQuestions($submission->id, $data['investigation_questions'] ?? []);

        $submission->update(['extraction_status' => FraudScenarioSubmission::EXTRACTION_STATUS_COMPLETED]);

        FraudGenerationRun::where('scenario_submission_id', $submission->id)
            ->where('run_type', FraudGenerationRun::RUN_TYPE_EXTRACTION)
            ->where('status', FraudGenerationRun::STATUS_RUNNING)
            ->update(['status' => FraudGenerationRun::STATUS_COMPLETED, 'completed_at' => now()]);

        return $extraction;
    }

    public function markFailed(FraudScenarioSubmission $submission, string $stage, string $errorMessage, array $errors = []): void
    {
        $statusField = $stage === 'mock_data' ? 'mock_data_status' : 'extraction_status';
        $submission->update([$statusField => FraudScenarioSubmission::EXTRACTION_STATUS_FAILED]);

        FraudGenerationRun::where('scenario_submission_id', $submission->id)
            ->where('status', FraudGenerationRun::STATUS_RUNNING)
            ->update([
                'status' => FraudGenerationRun::STATUS_FAILED,
                'completed_at' => now(),
                'errors' => array_merge(['message' => $errorMessage], $errors),
            ]);
    }

    private function upsertExpectedIndicators(string $submissionId, array $items): void
    {
        FraudExpectedIndicator::where('scenario_submission_id', $submissionId)->delete();

        foreach ($items as $item) {
            FraudExpectedIndicator::create([
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $submissionId,
                'indicator_key' => $item['indicator_key'] ?? '',
                'indicator_name' => $item['indicator_name'] ?? '',
                'indicator_category' => $item['indicator_category'] ?? null,
                'description' => $item['description'] ?? null,
                'severity' => $item['severity'] ?? null,
                'data_needed' => $item['data_needed'] ?? null,
                'should_detect' => $item['should_detect'] ?? true,
            ]);
        }
    }

    private function upsertExpectedFindings(string $submissionId, array $items): void
    {
        FraudExpectedFinding::where('scenario_submission_id', $submissionId)->delete();

        foreach ($items as $item) {
            FraudExpectedFinding::create([
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $submissionId,
                'finding_key' => $item['finding_key'] ?? '',
                'finding_title' => $item['finding_title'] ?? '',
                'finding_description' => $item['finding_description'] ?? '',
                'expected_risk_score' => $item['expected_risk_score'] ?? null,
                'expected_confidence' => $item['expected_confidence'] ?? null,
                'recommended_action' => $item['recommended_action'] ?? null,
                'expected_user_message' => $item['expected_user_message'] ?? null,
            ]);
        }
    }

    private function upsertDocumentRequests(string $submissionId, array $items): void
    {
        FraudDocumentRequest::where('scenario_submission_id', $submissionId)->delete();

        foreach ($items as $item) {
            FraudDocumentRequest::create([
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $submissionId,
                'document_name' => $item['document_name'] ?? '',
                'why_needed' => $item['why_needed'] ?? null,
                'priority' => $item['priority'] ?? null,
                'expected_issue_found' => $item['expected_issue_found'] ?? null,
            ]);
        }
    }

    private function upsertInvestigationQuestions(string $submissionId, array $items): void
    {
        FraudInvestigationQuestion::where('scenario_submission_id', $submissionId)->delete();

        foreach ($items as $item) {
            FraudInvestigationQuestion::create([
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $submissionId,
                'question' => $item['question'] ?? '',
                'asked_to' => $item['asked_to'] ?? null,
                'why_question_matters' => $item['why_question_matters'] ?? null,
                'priority' => $item['priority'] ?? null,
            ]);
        }
    }
}
