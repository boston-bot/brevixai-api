<?php

namespace App\Http\Controllers\Chat;

use App\Enums\RexProcess;
use App\Exceptions\BrevixAgentRunFailed;
use App\Http\Controllers\Controller;
use App\Services\Agents\BrevixAgentRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class AgentChatController extends Controller
{
    public function store(Request $request, BrevixAgentRunner $agentRunner): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'conversation_id' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'requested_action' => ['sometimes', 'string', Rule::in($this->supportedRequestedActions())],
            'date_range' => ['sometimes', 'array:start_date,end_date'],
            'date_range.start_date' => ['required_with:date_range', 'date_format:Y-m-d', 'before_or_equal:date_range.end_date'],
            'date_range.end_date' => ['required_with:date_range', 'date_format:Y-m-d', 'after_or_equal:date_range.start_date'],
            'max_response_size' => ['sometimes', 'integer', 'min:256', 'max:'.BrevixAgentRunner::MAX_RESPONSE_SIZE],
            'page_context' => ['sometimes', 'array'],
            'page_context.selected_period' => ['nullable', 'date_format:Y-m'],
        ]);

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        if ($validated['company_id'] !== $companyId) {
            return response()->json(['error' => 'Cannot run agent for another company'], 403);
        }

        try {
            $result = $agentRunner->run([
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'conversation_id' => $validated['conversation_id'] ?? null,
                'message' => $validated['message'],
                'requested_action' => $validated['requested_action'] ?? 'risk_review',
                'date_range' => $validated['date_range'] ?? null,
                'max_response_size' => $validated['max_response_size'] ?? BrevixAgentRunner::DEFAULT_MAX_RESPONSE_SIZE,
                'page_context' => $validated['page_context'] ?? (object) [],
            ]);

            return response()->json($this->responseContract($result));
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'I could not complete the risk review right now. No alerts or cases were created. Please try again or review the dashboard manually.',
                'intent' => 'agent_service_unavailable',
                'findings' => [],
                'recommended_actions' => [],
                'can_create_alert' => false,
                'requires_review' => false,
                'trace_id' => $e instanceof BrevixAgentRunFailed ? $e->agentRunId() : null,
                'investigative_synthesis' => null,
                'degraded_tools' => [],
            ], 502);
        }
    }

    /** @param array<string, mixed> $result */
    private function responseContract(array $result): array
    {
        return [
            'message' => $result['message'],
            'intent' => $result['intent'],
            'findings' => $result['findings'],
            'recommended_actions' => $result['recommended_actions'],
            'can_create_alert' => $result['can_create_alert'],
            'requires_review' => $result['requires_review'],
            'trace_id' => $result['trace_id'],
            'investigative_synthesis' => $result['investigative_synthesis'] ?? null,
            'degraded_tools' => $result['degraded_tools'] ?? [],
        ];
    }

    /** @return list<string> */
    private function supportedRequestedActions(): array
    {
        return array_map(
            fn (RexProcess $process): string => $process->value,
            RexProcess::routableByLlm(),
        );
    }
}
