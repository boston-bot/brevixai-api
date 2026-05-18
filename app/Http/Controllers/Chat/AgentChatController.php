<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Services\Agents\BrevixAgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class AgentChatController extends Controller
{
    private const DEFAULT_MAX_RESPONSE_SIZE = 4000;
    private const MAX_RESPONSE_SIZE = 8000;

    /** @var array<int, string> */
    private const SUPPORTED_REQUESTED_ACTIONS = ['risk_review'];

    /** @var array<int, string> */
    private const SUPPORTED_RECOMMENDED_ACTIONS = ['create_alert'];

    public function store(Request $request, BrevixAgentClient $agentClient): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'conversation_id' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'requested_action' => ['sometimes', 'string', Rule::in(self::SUPPORTED_REQUESTED_ACTIONS)],
            'date_range' => ['sometimes', 'array:start_date,end_date'],
            'date_range.start_date' => ['required_with:date_range', 'date_format:Y-m-d', 'before_or_equal:date_range.end_date'],
            'date_range.end_date' => ['required_with:date_range', 'date_format:Y-m-d', 'after_or_equal:date_range.start_date'],
            'max_response_size' => ['sometimes', 'integer', 'min:256', 'max:' . self::MAX_RESPONSE_SIZE],
            'page_context' => ['sometimes', 'array'],
            'page_context.selected_period' => ['nullable', 'date_format:Y-m'],
        ]);

        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        if ($validated['company_id'] !== $companyId) {
            return response()->json(['error' => 'Cannot run agent for another company'], 403);
        }

        $maxResponseSize = (int)($validated['max_response_size'] ?? self::DEFAULT_MAX_RESPONSE_SIZE);

        $agentRun = AgentRun::create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'conversation_id' => $validated['conversation_id'] ?? null,
            'status' => 'running',
            'input_message' => $validated['message'],
            'started_at' => now(),
        ]);

        Log::info('agent.request.received', [
            'agent_run_id' => $agentRun->id,
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'requested_action' => $validated['requested_action'] ?? 'risk_review',
        ]);

        try {
            $pageContext = $validated['page_context'] ?? (object) [];

            $agentResponse = $agentClient->run([
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'conversation_id' => $validated['conversation_id'] ?? null,
                'message' => $validated['message'],
                'requested_action' => $validated['requested_action'] ?? 'risk_review',
                'date_range' => $validated['date_range'] ?? null,
                'max_response_size' => $maxResponseSize,
                'page_context' => $pageContext,
            ]);

            $steps = is_array($agentResponse['steps'] ?? null) ? $agentResponse['steps'] : [];
            $this->persistSteps($agentRun, $steps);
            $actionGate = $this->persistActionApprovals($agentRun, $agentResponse['recommended_actions'] ?? []);
            $actions = $actionGate['actions'];
            $toolEndpoints = $this->toolEndpointsFromSteps($steps);
            $message = $this->boundedString($agentResponse['message'] ?? '', $maxResponseSize);
            $intent = $this->boundedString($agentResponse['intent'] ?? 'unknown', 120);
            $requiresReview = (bool)($agentResponse['requires_review'] ?? false) || collect($actions)->contains(
                fn (array $action): bool => (bool)($action['requires_approval'] ?? true)
            );

            $agentRun->update([
                'status' => 'completed',
                'intent' => $intent,
                'final_response' => $message,
                'model_provider' => $agentResponse['model_provider'] ?? null,
                'model_name' => $agentResponse['model_name'] ?? null,
                'tokens_input' => $agentResponse['usage']['tokens_input'] ?? null,
                'tokens_output' => $agentResponse['usage']['tokens_output'] ?? null,
                'cost_estimate' => $agentResponse['usage']['cost_estimate'] ?? null,
                'completed_at' => now(),
            ]);

            Log::info('agent.request.completed', [
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'intent' => $intent,
                'tool_endpoints_called' => $toolEndpoints,
                'action_gate_blocked' => $actionGate['blocked_count'] > 0,
            ]);

            return response()->json([
                'message' => $message,
                'intent' => $intent,
                'findings' => $this->arrayValue($agentResponse['findings'] ?? []),
                'recommended_actions' => $actions,
                'can_create_alert' => collect($actions)->contains(fn (array $action): bool => ($action['type'] ?? null) === 'create_alert'),
                'requires_review' => $requiresReview,
                'trace_id' => $agentRun->id,
            ]);
        } catch (Throwable $e) {
            $agentRun->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::warning('agent.request.failed', [
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'error_class' => $e::class,
                'error_code' => $e->getCode() ?: null,
            ]);

            return response()->json([
                'message' => 'I could not complete the risk review right now. No alerts or cases were created. Please try again or review the dashboard manually.',
                'intent' => 'agent_service_unavailable',
                'findings' => [],
                'recommended_actions' => [],
                'can_create_alert' => false,
                'requires_review' => false,
                'trace_id' => $agentRun->id,
            ], 502);
        }
    }

    private function persistSteps(AgentRun $agentRun, array $steps): void
    {
        foreach ($steps as $step) {
            AgentStep::create([
                'agent_run_id' => $agentRun->id,
                'step_name' => (string)($step['step_name'] ?? 'unknown'),
                'step_type' => (string)($step['step_type'] ?? 'graph_node'),
                'input_payload' => $step['input_payload'] ?? null,
                'output_payload' => $step['output_payload'] ?? null,
                'status' => (string)($step['status'] ?? 'completed'),
                'started_at' => $step['started_at'] ?? null,
                'completed_at' => $step['completed_at'] ?? null,
                'error_message' => $step['error_message'] ?? null,
            ]);
        }
    }

    private function persistActionApprovals(AgentRun $agentRun, array $actions): array
    {
        $persistedActions = [];
        $blockedCount = 0;

        foreach ($actions as $action) {
            if (!is_array($action)) {
                $blockedCount++;
                Log::warning('agent.action_gate.blocked', [
                    'agent_run_id' => $agentRun->id,
                    'company_id' => $agentRun->company_id,
                    'user_id' => $agentRun->user_id,
                    'action_type' => 'invalid',
                    'reason' => 'invalid_action_shape',
                ]);
                continue;
            }

            $actionType = (string)($action['type'] ?? 'unknown');
            if (!in_array($actionType, self::SUPPORTED_RECOMMENDED_ACTIONS, true)) {
                $blockedCount++;
                Log::warning('agent.action_gate.blocked', [
                    'agent_run_id' => $agentRun->id,
                    'company_id' => $agentRun->company_id,
                    'user_id' => $agentRun->user_id,
                    'action_type' => $actionType,
                    'reason' => 'unsupported_action',
                ]);
                continue;
            }

            if (($action['requires_approval'] ?? true) !== true) {
                $blockedCount++;
                Log::warning('agent.action_gate.blocked', [
                    'agent_run_id' => $agentRun->id,
                    'company_id' => $agentRun->company_id,
                    'user_id' => $agentRun->user_id,
                    'action_type' => $actionType,
                    'reason' => 'autonomous_action_requires_review',
                ]);
            }

            $action['requires_approval'] = true;
            $action['requires_review'] = true;

            $approval = AgentActionApproval::create([
                'agent_run_id' => $agentRun->id,
                'company_id' => $agentRun->company_id,
                'user_id' => $agentRun->user_id,
                'action_type' => $actionType,
                'action_payload' => $this->arrayValue($action['payload'] ?? []),
                'status' => 'pending',
            ]);

            $persistedActions[] = array_merge($action, ['approval_id' => $approval->id]);
        }

        return [
            'actions' => $persistedActions,
            'blocked_count' => $blockedCount,
        ];
    }

    private function boundedString(mixed $value, int $maxLength): string
    {
        $string = is_string($value) ? $value : '';

        if (strlen($string) <= $maxLength) {
            return $string;
        }

        return substr($string, 0, $maxLength);
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<int, mixed> $steps
     * @return array<int, string>
     */
    private function toolEndpointsFromSteps(array $steps): array
    {
        $endpoints = [];

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $stepType = (string)($step['step_type'] ?? '');
            $input = is_array($step['input_payload'] ?? null) ? $step['input_payload'] : [];
            $output = is_array($step['output_payload'] ?? null) ? $step['output_payload'] : [];
            $endpoint = $output['endpoint'] ?? $output['tool'] ?? $input['endpoint'] ?? $input['tool'] ?? null;

            if ($stepType === 'tool_call' && is_string($endpoint) && $endpoint !== '') {
                $endpoints[] = $endpoint;
            }
        }

        return array_values(array_unique($endpoints));
    }
}
