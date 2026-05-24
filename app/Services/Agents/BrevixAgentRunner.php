<?php

namespace App\Services\Agents;

use App\Exceptions\BrevixAgentRunFailed;
use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\AgentStep;
use Illuminate\Support\Facades\Log;
use Throwable;

class BrevixAgentRunner
{
    public const DEFAULT_MAX_RESPONSE_SIZE = 4000;

    public const MAX_RESPONSE_SIZE = 8000;

    /** @var array<int, string> */
    private const SUPPORTED_RECOMMENDED_ACTIONS = ['create_alert'];

    public function __construct(private readonly BrevixAgentClient $agentClient) {}

    /**
     * @param array{
     *     company_id: string,
     *     user_id: string,
     *     message: string,
     *     conversation_id?: string|null,
     *     requested_action?: string|null,
     *     date_range?: array<string, string>|null,
     *     max_response_size?: int|null,
     *     page_context?: mixed
     * } $input
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    public function run(array $input): array
    {
        $companyId = $input['company_id'];
        $userId = $input['user_id'];
        $message = $input['message'];
        $maxResponseSize = min(
            self::MAX_RESPONSE_SIZE,
            max(256, (int) ($input['max_response_size'] ?? self::DEFAULT_MAX_RESPONSE_SIZE))
        );

        $agentRun = AgentRun::create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'conversation_id' => $input['conversation_id'] ?? null,
            'status' => 'running',
            'input_message' => $message,
            'started_at' => now(),
        ]);

        Log::info('agent.request.received', [
            'agent_run_id' => $agentRun->id,
            'company_id' => $companyId,
            'user_id' => $userId,
            'requested_action' => $input['requested_action'] ?? 'risk_review',
        ]);

        try {
            $agentResponse = $this->agentClient->run([
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'conversation_id' => $input['conversation_id'] ?? null,
                'message' => $message,
                'requested_action' => $input['requested_action'] ?? 'risk_review',
                'date_range' => $input['date_range'] ?? null,
                'max_response_size' => $maxResponseSize,
                'page_context' => $input['page_context'] ?? (object) [],
                'optional_deterministic_tools' => $this->optionalDeterministicTools($companyId),
                'tool_policy' => $this->toolPolicy(),
            ]);

            $steps = is_array($agentResponse['steps'] ?? null) ? $agentResponse['steps'] : [];
            $this->persistSteps($agentRun, $steps);
            $actionGate = $this->persistActionApprovals($agentRun, $agentResponse['recommended_actions'] ?? []);
            $actions = $actionGate['actions'];
            $toolEndpoints = $this->toolEndpointsFromSteps($steps);
            $responseMessage = $this->boundedString($agentResponse['message'] ?? '', $maxResponseSize);
            $intent = $this->boundedString($agentResponse['intent'] ?? 'unknown', 120);
            $requiresReview = (bool) ($agentResponse['requires_review'] ?? false) || collect($actions)->contains(
                fn (array $action): bool => (bool) ($action['requires_approval'] ?? true)
            );

            $agentRun->update([
                'status' => 'completed',
                'intent' => $intent,
                'final_response' => $responseMessage,
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
                'user_id' => $userId,
                'intent' => $intent,
                'tool_endpoints_called' => $toolEndpoints,
                'action_gate_blocked' => $actionGate['blocked_count'] > 0,
            ]);

            return [
                'message' => $responseMessage,
                'intent' => $intent,
                'findings' => $this->arrayValue($agentResponse['findings'] ?? []),
                'recommended_actions' => $actions,
                'can_create_alert' => collect($actions)->contains(fn (array $action): bool => ($action['type'] ?? null) === 'create_alert'),
                'requires_review' => $requiresReview,
                'trace_id' => $agentRun->id,
                'steps' => $steps,
                'model_provider' => $agentResponse['model_provider'] ?? null,
                'model_name' => $agentResponse['model_name'] ?? null,
                'usage' => $this->arrayValue($agentResponse['usage'] ?? []),
            ];
        } catch (Throwable $e) {
            $agentRun->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::warning('agent.request.failed', [
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'error_class' => $e::class,
                'error_code' => $e->getCode() ?: null,
            ]);

            throw new BrevixAgentRunFailed($agentRun->id, $e);
        }
    }

    private function persistSteps(AgentRun $agentRun, array $steps): void
    {
        foreach ($steps as $step) {
            AgentStep::create([
                'agent_run_id' => $agentRun->id,
                'step_name' => (string) ($step['step_name'] ?? 'unknown'),
                'step_type' => (string) ($step['step_type'] ?? 'graph_node'),
                'input_payload' => $step['input_payload'] ?? null,
                'output_payload' => $step['output_payload'] ?? null,
                'status' => (string) ($step['status'] ?? 'completed'),
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
            if (! is_array($action)) {
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

            $actionType = (string) ($action['type'] ?? 'unknown');
            if (! in_array($actionType, self::SUPPORTED_RECOMMENDED_ACTIONS, true)) {
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
     * Advertise approved Laravel tool endpoints to the agent service without exposing database access.
     *
     * @return array<string, array<string, mixed>>
     */
    private function optionalDeterministicTools(string $companyId): array
    {
        return [
            'aggregate_risk_summary' => [
                'method' => 'GET',
                'path' => "/api/internal/agent-tools/company/{$companyId}/aggregate-risk-summary",
                'optional' => true,
                'deterministic' => true,
                'purpose' => 'Use during fraud or risk analysis when a cross-domain deterministic score and evidence summary would improve the response.',
                'score_authority' => 'laravel',
                'requires_user_context_header' => true,
            ],
            'alert_recommendations' => [
                'method' => 'GET',
                'path' => "/api/internal/agent-tools/company/{$companyId}/alert-recommendations",
                'optional' => true,
                'deterministic' => true,
                'purpose' => 'Use during fraud or risk analysis when deterministic alert recommendation drafts would improve the response.',
                'recommendation_authority' => 'laravel',
                'requires_user_context_header' => true,
            ],
        ];
    }

    /** @return array<string, string> */
    private function toolPolicy(): array
    {
        return [
            'database_access' => 'forbidden',
            'autonomous_actions' => 'forbidden',
            'alert_creation' => 'recommendation_only',
            'alert_recommendation_approval' => 'authenticated_user_only',
            'score_recalculation' => 'forbidden',
        ];
    }

    /**
     * @param  array<int, mixed>  $steps
     * @return array<int, string>
     */
    private function toolEndpointsFromSteps(array $steps): array
    {
        $endpoints = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $stepType = (string) ($step['step_type'] ?? '');
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
