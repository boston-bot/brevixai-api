<?php

namespace App\Http\Controllers\Chat;

use App\Enums\RexProcess;
use App\Exceptions\BusinessProfileAccessException;
use App\Exceptions\BrevixAgentRunFailed;
use App\Http\Controllers\Controller;
use App\Services\Agents\BrevixAgentRunner;
use App\Services\BusinessProfileContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AgentChatController extends Controller
{
    public function store(Request $request, BrevixAgentRunner $agentRunner, BusinessProfileContextService $businessProfileContext): StreamedResponse|JsonResponse
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
            $context = $businessProfileContext->resolveForRequest($request, $companyId);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }

        $userId = $request->user()->id;
        $runInput = [
            'company_id' => $companyId,
            'business_profile_id' => $context->businessProfileId,
            'user_id' => $userId,
            'conversation_id' => $validated['conversation_id'] ?? null,
            'message' => $validated['message'],
            'requested_action' => $validated['requested_action'] ?? 'risk_review',
            'date_range' => $validated['date_range'] ?? null,
            'max_response_size' => $validated['max_response_size'] ?? BrevixAgentRunner::DEFAULT_MAX_RESPONSE_SIZE,
            'page_context' => array_merge(
                is_array($validated['page_context'] ?? null) ? $validated['page_context'] : [],
                ['business_profile_id' => $context->businessProfileId],
            ),
        ];

        // SSE streaming path: activated when the client explicitly requests it.
        // Falls back to JSON for clients that send Accept: application/json (including tests).
        $acceptHeader = $request->header('Accept', '');
        if (str_contains($acceptHeader, 'text/event-stream')) {
            return $this->streamingResponse($runInput, $agentRunner);
        }

        // JSON path (backward-compatible)
        try {
            $result = $agentRunner->run($runInput);

            return response()->json($this->jsonResponseContract($result));
        } catch (Throwable $e) {
            return response()->json([
                'message' => $this->agentUnavailableMessage(),
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

    /** @param array<string, mixed> $runInput */
    private function streamingResponse(array $runInput, BrevixAgentRunner $agentRunner): StreamedResponse
    {
        return new StreamedResponse(function () use ($agentRunner, $runInput): void {
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', 'off');

            $this->emitSse('run.started', ['companyId' => $runInput['company_id']]);

            try {
                $result = $agentRunner->runStreaming(
                    $runInput,
                    function (string $type, array $payload): void {
                        // Proxy all events from the agent stream except message.completed,
                        // which we re-emit below with the persisted trace_id attached.
                        if ($type !== 'message.completed') {
                            $this->emitSse($type, $payload);
                        }
                    }
                );

                $this->emitSse('message.completed', [
                    'routedTo' => 'agent.'.$runInput['requested_action'],
                    'traceId' => $result['trace_id'],
                    'intent' => $result['intent'] ?? null,
                    'requiresReview' => (bool) ($result['requires_review'] ?? false),
                    'canCreateAlert' => (bool) ($result['can_create_alert'] ?? false),
                    'artifactCount' => count($result['findings'] ?? []),
                    'actionCount' => count($result['recommended_actions'] ?? []),
                    'degradedToolCount' => count($result['degraded_tools'] ?? []),
                    'findings' => $result['findings'] ?? [],
                    'recommendedActions' => $result['recommended_actions'] ?? [],
                    'investigativeSynthesis' => $result['investigative_synthesis'],
                ]);
            } catch (Throwable $e) {
                $traceId = $e instanceof BrevixAgentRunFailed ? $e->agentRunId() : null;
                $this->emitSse('message.delta', ['content' => $this->agentUnavailableMessage()]);
                $this->emitSse('message.completed', [
                    'routedTo' => 'agent.'.$runInput['requested_action'],
                    'traceId' => $traceId,
                    'artifactCount' => 0,
                    'actionCount' => 0,
                    'error' => 'agent_service_unavailable',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param array<string, mixed> $result */
    private function jsonResponseContract(array $result): array
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

    private function agentUnavailableMessage(): string
    {
        return 'I could not complete the risk review right now. No alerts or cases were created. Please try again or review the dashboard manually.';
    }

    private function emitSse(string $type, array $payload): void
    {
        echo 'data: '.json_encode(['type' => $type, 'payload' => $payload])."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
