<?php

namespace App\Services\Agents;

use App\Enums\RexProcess;
use App\Exceptions\BrevixAgentRunFailed;
use App\Services\Agents\AgentToolRegistry;
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
                'optional_deterministic_tools' => $this->optionalDeterministicTools($companyId, $input['requested_action'] ?? 'risk_review'),
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
                'investigative_synthesis' => is_array($agentResponse['investigative_synthesis'] ?? null) ? $agentResponse['investigative_synthesis'] : null,
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
     * Advertise only the tool endpoints declared by the process registry for this requested action.
     * Paths and metadata are sourced from AgentToolRegistry to stay in sync with the parity gate.
     *
     * @return array<string, array<string, mixed>>
     */
    private function optionalDeterministicTools(string $companyId, string $requestedAction): array
    {
        $allowedKeys = RexProcess::resolveOrDefault($requestedAction)->tools();

        $purposes = [
            'company_context'          => ['optional' => false, 'purpose' => 'Load company, data-source, user-role, dashboard, and bounded transaction context through Laravel tenant checks.', 'data_authority' => 'laravel'],
            'risk_summary'             => ['optional' => false, 'purpose' => 'Use as the primary deterministic risk score and top-driver source for risk review responses.', 'score_authority' => 'laravel'],
            'vendor_risk'              => ['optional' => true, 'purpose' => 'Use for vendor concentration, vendor onboarding, payment-pattern, and named-vendor risk analysis.', 'score_authority' => 'laravel'],
            'reconciliation_risk'      => ['optional' => true, 'purpose' => 'Use for bank-to-ledger mismatch, stale discrepancy, and reconciliation-drift analysis.', 'score_authority' => 'laravel'],
            'entity_relationship_risk' => ['optional' => true, 'purpose' => 'Use for employee/vendor overlap, shared contact data, duplicate entity, and related-party risk analysis.', 'score_authority' => 'laravel'],
            'aggregate_risk_summary'   => ['optional' => true, 'purpose' => 'Use during fraud or risk analysis when a cross-domain deterministic score and evidence summary would improve the response.', 'score_authority' => 'laravel'],
            'alert_recommendations'    => ['optional' => true, 'purpose' => 'Use during fraud or risk analysis when deterministic alert recommendation drafts would improve the response.', 'recommendation_authority' => 'laravel'],
            'case_recommendations'     => ['optional' => true, 'purpose' => 'Use during risk analysis when deterministic case recommendation drafts would improve the response.', 'recommendation_authority' => 'laravel'],
        ];

        $keys = empty($allowedKeys) ? array_keys($purposes) : $allowedKeys;

        $tools = [];
        foreach ($keys as $key) {
            $path = AgentToolRegistry::path($key, $companyId);
            if ($path === null || ! isset($purposes[$key])) {
                continue;
            }
            $tools[$key] = array_merge(
                ['method' => 'GET', 'path' => $path, 'deterministic' => true, 'requires_user_context_header' => true],
                $purposes[$key]
            );
        }

        return $tools;
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
            'tool_surface' => 'api/internal/agent-tools',
            'mutating_tools' => 'forbidden',
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
