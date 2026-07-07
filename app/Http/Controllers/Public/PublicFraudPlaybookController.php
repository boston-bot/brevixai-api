<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Fraud\RetrievalFeedback;
use App\Services\Retrieval\FraudPlaybookRetriever;
use App\Services\Retrieval\RetrievalQuery;
use App\Services\Retrieval\RetrievalService;
use App\Support\ProfessionalServicesDisclaimer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unauthenticated, rate-limited fraud playbook lookup for the public-facing
 * trust-building tool. No company data is required or accepted.
 */
class PublicFraudPlaybookController extends Controller
{
    public function search(Request $request, RetrievalService $retrieval): JsonResponse
    {
        $request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:20',
        ]);

        $response = $retrieval->search(new RetrievalQuery(
            corpusId: FraudPlaybookRetriever::CORPUS_ID,
            query: (string) $request->input('query'),
            limit: (int) $request->input('limit', 5),
        ))->toArray();

        $response['data'] = array_map(
            fn (array $result): array => $result['document'],
            $response['results']
        );

        return response()->json($response);
    }

    public function feedback(Request $request): JsonResponse
    {
        $request->validate([
            'playbook_id' => 'required|exists:investigation_playbooks,id',
            'query_text' => 'required|string',
            'relevance_score' => 'required|integer|min:0|max:5',
            'user_feedback' => 'nullable|string',
        ]);

        $feedback = RetrievalFeedback::create([
            'playbook_id' => $request->input('playbook_id'),
            'query_text' => $request->input('query_text'),
            'relevance_score' => $request->input('relevance_score'),
            'user_feedback' => $request->input('user_feedback'),
        ]);

        return response()->json([
            'message' => 'Feedback submitted successfully',
            'data' => $feedback,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ]);
    }
}
