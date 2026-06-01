<?php

namespace App\Services\Agents;

use App\Enums\RexProcess;
use App\Exceptions\BrevixAgentRunFailed;
use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class BrevixAgentRunner
{
    public const DEFAULT_MAX_RESPONSE_SIZE = 4000;

    public const MAX_RESPONSE_SIZE = 8000;

    /** @var array<int, string> */
    public const SUPPORTED_RECOMMENDED_ACTIONS = ['create_alert', 'create_case', 'flag_transaction', 'escalate_review'];

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
        $businessProfileId = is_string($input['business_profile_id'] ?? null) ? $input['business_profile_id'] : null;
        $userId = $input['user_id'];
        $message = $input['message'];
        $maxResponseSize = min(
            self::MAX_RESPONSE_SIZE,
            max(256, (int) ($input['max_response_size'] ?? self::DEFAULT_MAX_RESPONSE_SIZE))
        );

        $agentRunAttributes = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'conversation_id' => $input['conversation_id'] ?? null,
            'status' => 'running',
            'input_message' => $message,
            'started_at' => now(),
        ];
        if ($businessProfileId && Schema::hasColumn('agent_runs', 'business_profile_id')) {
            $agentRunAttributes['business_profile_id'] = $businessProfileId;
        }

        $agentRun = AgentRun::create($agentRunAttributes);

        Log::info('agent.request.received', [
            'agent_run_id' => $agentRun->id,
            'company_id' => $companyId,
            'user_id' => $userId,
            'requested_action' => $input['requested_action'] ?? 'risk_review',
        ]);

        try {
            $conversationHistory = $this->loadConversationHistory($input['conversation_id'] ?? null, $companyId, $businessProfileId);

            $agentResponse = $this->agentClient->run([
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'business_profile_id' => $businessProfileId,
                'user_id' => $userId,
                'conversation_id' => $input['conversation_id'] ?? null,
                'message' => $message,
                'conversation_history' => $conversationHistory,
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

            $degradedTools = $this->degradedTools($agentResponse, $steps);

            Log::info('agent.request.completed', [
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'intent' => $intent,
                'tool_endpoints_called' => $toolEndpoints,
                'action_gate_blocked' => $actionGate['blocked_count'] > 0,
                'degraded_tools' => $degradedTools,
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
                'degraded_tools' => $degradedTools,
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

    /**
     * Run the agent with SSE streaming, calling $emitEvent for each event received.
     *
     * Persists the AgentRun and related records when the stream completes.
     *
     * @param  array{company_id: string, user_id: string, message: string, conversation_id?: string|null, requested_action?: string|null, page_context?: mixed}  $input
     * @param  callable(string $type, array $payload): void  $emitEvent
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    public function runStreaming(array $input, callable $emitEvent): array
    {
        $companyId = $input['company_id'];
        $businessProfileId = is_string($input['business_profile_id'] ?? null) ? $input['business_profile_id'] : null;
        $userId = $input['user_id'];
        $message = $input['message'];
        $maxResponseSize = min(
            self::MAX_RESPONSE_SIZE,
            max(256, (int) ($input['max_response_size'] ?? self::DEFAULT_MAX_RESPONSE_SIZE))
        );

        $agentRunAttributes = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'conversation_id' => $input['conversation_id'] ?? null,
            'status' => 'running',
            'input_message' => $message,
            'started_at' => now(),
        ];
        if ($businessProfileId && Schema::hasColumn('agent_runs', 'business_profile_id')) {
            $agentRunAttributes['business_profile_id'] = $businessProfileId;
        }

        $agentRun = AgentRun::create($agentRunAttributes);

        Log::info('agent.stream.started', [
            'agent_run_id' => $agentRun->id,
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);

        try {
            $conversationHistory = $this->loadConversationHistory($input['conversation_id'] ?? null, $companyId, $businessProfileId);

            $assembled = $this->agentClient->runStream([
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'business_profile_id' => $businessProfileId,
                'user_id' => $userId,
                'conversation_id' => $input['conversation_id'] ?? null,
                'message' => $message,
                'conversation_history' => $conversationHistory,
                'requested_action' => $input['requested_action'] ?? 'risk_review',
                'page_context' => $input['page_context'] ?? (object) [],
                'optional_deterministic_tools' => $this->optionalDeterministicTools($companyId, $input['requested_action'] ?? 'risk_review'),
                'tool_policy' => $this->toolPolicy(),
            ], $emitEvent);

            // $assembled comes from the message.completed payload
            $steps = is_array($assembled['steps'] ?? null) ? $assembled['steps'] : [];
            $rawActions = is_array($assembled['recommendedActions'] ?? null) ? $assembled['recommendedActions'] : [];
            $intent = $this->boundedString($assembled['intent'] ?? 'unknown', 120);
            $responseMessage = $this->boundedString($assembled['message'] ?? '', $maxResponseSize);

            $this->persistSteps($agentRun, $steps);
            $actionGate = $this->persistActionApprovals($agentRun, $rawActions);
            $actions = $actionGate['actions'];

            $agentRun->update([
                'status' => 'completed',
                'intent' => $intent,
                'final_response' => $responseMessage,
                'model_provider' => $assembled['modelProvider'] ?? null,
                'model_name' => $assembled['modelName'] ?? null,
                'tokens_input' => $assembled['usage']['tokens_input'] ?? null,
                'tokens_output' => $assembled['usage']['tokens_output'] ?? null,
                'cost_estimate' => $assembled['usage']['cost_estimate'] ?? null,
                'completed_at' => now(),
            ]);

            $degradedTools = $this->arrayValue($assembled['degradedTools'] ?? []);

            Log::info('agent.stream.completed', [
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'intent' => $intent,
            ]);

            $requiresReview = collect($actions)->contains(
                fn (array $action): bool => (bool) ($action['requires_approval'] ?? true)
            );

            return [
                'message' => $responseMessage,
                'intent' => $intent,
                'findings' => $this->arrayValue($assembled['findings'] ?? []),
                'recommended_actions' => $actions,
                'can_create_alert' => collect($actions)->contains(fn (array $action): bool => ($action['type'] ?? null) === 'create_alert'),
                'requires_review' => $requiresReview,
                'trace_id' => $agentRun->id,
                'investigative_synthesis' => is_array($assembled['investigativeSynthesis'] ?? null) ? $assembled['investigativeSynthesis'] : null,
                'degraded_tools' => array_values(array_filter($degradedTools, fn (mixed $t): bool => is_array($t))),
            ];
        } catch (Throwable $e) {
            $agentRun->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::warning('agent.stream.failed', [
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'error_class' => $e::class,
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

            $approvalAttributes = [
                'agent_run_id' => $agentRun->id,
                'company_id' => $agentRun->company_id,
                'user_id' => $agentRun->user_id,
                'action_type' => $actionType,
                'action_payload' => $this->arrayValue($action['payload'] ?? []),
                'status' => 'pending',
            ];
            if ($agentRun->business_profile_id && Schema::hasColumn('agent_action_approvals', 'business_profile_id')) {
                $approvalAttributes['business_profile_id'] = $agentRun->business_profile_id;
            }

            $approval = AgentActionApproval::create($approvalAttributes);

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
            'company_context' => ['optional' => false, 'purpose' => 'Load company, data-source, user-role, dashboard, and bounded transaction context through Laravel tenant checks.', 'data_authority' => 'laravel'],
            'risk_summary' => ['optional' => false, 'purpose' => 'Use as the primary deterministic risk score and top-driver source for risk review responses.', 'score_authority' => 'laravel'],
            'vendor_risk' => ['optional' => true, 'purpose' => 'Use for vendor concentration, vendor onboarding, payment-pattern, and named-vendor risk analysis.', 'score_authority' => 'laravel'],
            'reconciliation_risk' => ['optional' => true, 'purpose' => 'Use for bank-to-ledger mismatch, stale discrepancy, and reconciliation-drift analysis.', 'score_authority' => 'laravel'],
            'entity_relationship_risk' => ['optional' => true, 'purpose' => 'Use for employee/vendor overlap, shared contact data, duplicate entity, and related-party risk analysis.', 'score_authority' => 'laravel'],
            'aggregate_risk_summary' => ['optional' => true, 'purpose' => 'Use during fraud or risk analysis when a cross-domain deterministic score and evidence summary would improve the response.', 'score_authority' => 'laravel'],
            'alert_recommendations' => ['optional' => true, 'purpose' => 'Use during fraud or risk analysis when deterministic alert recommendation drafts would improve the response.', 'recommendation_authority' => 'laravel'],
            'case_recommendations' => ['optional' => true, 'purpose' => 'Use during risk analysis when deterministic case recommendation drafts would improve the response.', 'recommendation_authority' => 'laravel'],
            'pending_recommendations' => ['optional' => true, 'purpose' => 'Use to surface pending alert and case recommendations so the response can acknowledge open items awaiting user action.', 'recommendation_authority' => 'laravel'],
            'transaction_detail' => ['optional' => true, 'purpose' => 'Use to fetch specific transaction records by UUID when the user references a known transaction ID. Returns amount, date, vendor, type, and anomaly data.', 'data_authority' => 'laravel'],
        ];

        $keys = empty($allowedKeys) ? array_keys($purposes) : $allowedKeys;

        // Cross-cutting tools fire for every intent in the agent graph; always advertise them
        // regardless of which process-specific tool list is active.
        foreach (['pending_recommendations', 'transaction_detail'] as $crossKey) {
            if (! in_array($crossKey, $keys, true)) {
                $keys[] = $crossKey;
            }
        }

        $tools = [];
        foreach ($keys as $key) {
            $path = AgentToolRegistry::path($key, $companyId);
            if ($path === null || ! isset($purposes[$key])) {
                continue;
            }
            $tools[$key] = array_merge(
                [
                    'method' => 'GET',
                    'path' => $path,
                    'deterministic' => true,
                    'requires_user_context_header' => true,
                    'requires_business_profile_context' => true,
                    'business_profile_header' => 'X-Brevix-Business-Profile-Id',
                ],
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

    /**
     * @param  array<string, mixed>  $agentResponse
     * @param  array<int, mixed>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function degradedTools(array $agentResponse, array $steps): array
    {
        if (is_array($agentResponse['degraded_tools'] ?? null) && $agentResponse['degraded_tools'] !== []) {
            return array_values(array_filter(
                $agentResponse['degraded_tools'],
                fn (mixed $tool): bool => is_array($tool),
            ));
        }

        $degraded = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $status = (string) ($step['status'] ?? '');
            if (! in_array($status, ['failed', 'error', 'degraded'], true)) {
                continue;
            }

            $input = is_array($step['input_payload'] ?? null) ? $step['input_payload'] : [];
            $output = is_array($step['output_payload'] ?? null) ? $step['output_payload'] : [];
            $tool = $output['tool'] ?? $output['endpoint'] ?? $input['tool'] ?? $input['endpoint'] ?? $step['step_name'] ?? 'unknown_tool';

            $degraded[] = [
                'tool' => (string) $tool,
                'error_class' => (string) ($output['error_class'] ?? 'ToolUnavailable'),
                'message' => (string) ($step['error_message'] ?? $output['error'] ?? 'Optional deterministic tool was unavailable.'),
                'affected_confidence' => true,
            ];
        }

        return $degraded;
    }

    /**
     * Load the last 8 messages from the chat session to provide conversation context to the agent.
     * Returns null if no session ID is provided or no messages exist.
     *
     * @return list<array{role: string, content: string}>|null
     */
    private function loadConversationHistory(?string $conversationId, string $companyId, ?string $businessProfileId = null): ?array
    {
        if (! $conversationId || ! Str::isUuid($conversationId)) {
            return null;
        }

        $messages = ChatMessage::whereHas('session', function ($query) use ($conversationId, $companyId, $businessProfileId): void {
            $query->where('id', $conversationId)->where('company_id', $companyId);
            if ($businessProfileId && Schema::hasColumn('chat_sessions', 'business_profile_id')) {
                $query->where('business_profile_id', $businessProfileId);
            }
        })
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn (ChatMessage $m): array => [
                'role' => (string) $m->role,
                'content' => (string) $m->content,
            ])
            ->all();

        return count($messages) > 0 ? $messages : null;
    }
}
