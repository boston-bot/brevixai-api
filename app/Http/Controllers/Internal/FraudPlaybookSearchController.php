<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Fraud\InvestigationPlaybook;
use App\Models\Fraud\RetrievalFeedback;
use Illuminate\Http\JsonResponse;

class FraudPlaybookSearchController extends Controller
{
    /**
     * Search for fraud playbooks.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:20',
        ]);

        $queryStr = $request->input('query');
        $limit = $request->input('limit', 5);

        // Standard text-based search as a baseline
        $playbooks = InvestigationPlaybook::where('is_active', true)
            ->where(function ($query) use ($queryStr) {
                $query->where('title', 'LIKE', '%' . $queryStr . '%')
                      ->orWhere('category', 'LIKE', '%' . $queryStr . '%')
                      ->orWhere('intent_key', 'LIKE', '%' . $queryStr . '%');
            })
            ->with(['source'])
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $playbooks
        ]);
    }

    /**
     * Store retrieval feedback for a playbook.
     */
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
            // 'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Feedback submitted successfully',
            'data' => $feedback
        ]);
    }
}
