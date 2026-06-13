<?php

namespace App\Http\Controllers\FraudTesting;

use App\Http\Controllers\Controller;
use App\Models\FraudTesting\FraudScenarioSubmission;
use App\Services\FraudTesting\FraudScenarioProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FraudScenarioController extends Controller
{
    /**
     * GET /api/internal/fraud-testing/scenarios
     */
    public function index(Request $request): JsonResponse
    {
        $query = FraudScenarioSubmission::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($extractionStatus = $request->query('extraction_status')) {
            $query->where('extraction_status', $extractionStatus);
        }
        if ($mockDataStatus = $request->query('mock_data_status')) {
            $query->where('mock_data_status', $mockDataStatus);
        }
        if ($reviewStatus = $request->query('review_status')) {
            $query->where('review_status', $reviewStatus);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        $scenarios = $query->orderByDesc('created_at')
            ->select(['id', 'external_scenario_id', 'title', 'source', 'severity', 'status', 'extraction_status', 'mock_data_status', 'review_status', 'created_at'])
            ->paginate(25);

        return response()->json($scenarios);
    }

    /**
     * GET /api/internal/fraud-testing/scenarios/{id}
     */
    public function show(string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        return response()->json([
            'submission' => $submission,
            'extraction' => $submission->extraction,
            'expected_indicators' => $submission->expectedIndicators,
            'expected_findings' => $submission->expectedFindings,
            'document_requests' => $submission->documentRequests,
            'investigation_questions' => $submission->investigationQuestions,
            'mock_company' => $submission->mockCompany,
            'mock_parties' => $submission->mockParties,
            'mock_transactions' => $submission->mockTransactions,
        ]);
    }

    /**
     * POST /api/internal/fraud-testing/scenarios/{id}/approve
     */
    public function approve(string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        $submission->update(['review_status' => FraudScenarioSubmission::REVIEW_STATUS_APPROVED]);

        return response()->json(['message' => 'Scenario approved', 'review_status' => $submission->review_status]);
    }

    /**
     * POST /api/internal/fraud-testing/scenarios/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        $submission->update(['review_status' => FraudScenarioSubmission::REVIEW_STATUS_REJECTED]);

        return response()->json(['message' => 'Scenario rejected', 'review_status' => $submission->review_status]);
    }

    /**
     * POST /api/internal/fraud-testing/scenarios/{id}/provision-workspace
     *
     * Creates a fresh test workspace seeded with the scenario's mock data.
     * Returns login credentials so you can immediately sign in and browse
     * the data as a normal user would.
     */
    public function provisionWorkspace(Request $request, string $id, FraudScenarioProvisionService $provisionService): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|email',
            'password' => 'nullable|string|min:8',
        ]);

        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        if ($submission->mock_data_status !== FraudScenarioSubmission::MOCK_DATA_STATUS_COMPLETED) {
            return response()->json([
                'error' => 'Mock data is not ready. Current status: ' . $submission->mock_data_status,
            ], 422);
        }

        try {
            $credentials = $provisionService->provision(
                $submission,
                email: $request->input('email'),
                password: $request->input('password'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($credentials, 201);
    }

    /**
     * POST /api/admin/fraud-testing/scenarios/{id}/provision-workspace
     *
     * Creates a fresh test workspace from mock data and links the
     * authenticated admin user to it through workspace membership.
     */
    public function provisionAdminWorkspace(Request $request, string $id, FraudScenarioProvisionService $provisionService): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'nullable|in:owner,admin',
        ]);

        $submission = FraudScenarioSubmission::find($id);
        if (! $submission) {
            return response()->json(['error' => 'Scenario not found'], 404);
        }

        if ($submission->mock_data_status !== FraudScenarioSubmission::MOCK_DATA_STATUS_COMPLETED) {
            return response()->json([
                'error' => 'Mock data is not ready. Current status: ' . $submission->mock_data_status,
            ], 422);
        }

        try {
            $provisioned = $provisionService->provision(
                $submission,
                workspaceMember: $request->user(),
                workspaceMemberRole: $validated['role'] ?? 'admin',
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'workspace' => [
                'id' => $provisioned['workspace_id'],
                'name' => $provisioned['workspace_name'],
                'role' => $validated['role'] ?? 'admin',
                'businessProfileId' => $provisioned['business_profile_id'],
                'businessProfileName' => $provisioned['business_profile_name'],
            ],
            'scenario' => [
                'id' => $provisioned['scenario_id'],
                'title' => $provisioned['scenario_title'],
            ],
            'transactionCount' => $provisioned['transaction_count'],
        ], 201);
    }
}
