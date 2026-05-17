<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\AgentActionApproval;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Services\Agents\BrevixAgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AgentChatController extends Controller
{
    public function store(Request $request, BrevixAgentClient $agentClient): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'uuid'],
            'conversation_id' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:4000'],
            'page_context' => ['sometimes', 'array'],
        ]);

        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        if (!empty($validated['company_id']) && $validated['company_id'] !== $companyId) {
            return response()->json(['error' => 'Cannot run agent for another company'], 403);
        }

        $agentRun = AgentRun::create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'conversation_id' => $validated['conversation_id'] ?? null,
            'status' => 'running',
            'input_message' => $validated['message'],
            'started_at' => now(),
        ]);

        try {
            $agentResponse = $agentClient->run([
                'agent_run_id' => $agentRun->id,
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'conversation_id' => $validated['conversation_id'] ?? null,
                'message' => $validated['message'],
                'page_context' => $validated['page_context'] ?? [],
            ]);

            $this->persistSteps($agentRun, $agentResponse['steps'] ?? []);
            $actions = $this->persistActionApprovals($agentRun, $agentResponse['recommended_actions'] ?? []);

            $agentRun->update([
                'status' => 'completed',
                'intent' => $agentResponse['intent'] ?? null,
                'final_response' => $agentResponse['message'] ?? null,
                'model_provider' => $agentResponse['model_provider'] ?? null,
                'model_name' => $agentResponse['model_name'] ?? null,
                'tokens_input' => $agentResponse['usage']['tokens_input'] ?? null,
                'tokens_output' => $agentResponse['usage']['tokens_output'] ?? null,
                'cost_estimate' => $agentResponse['usage']['cost_estimate'] ?? null,
                'completed_at' => now(),
            ]);

            return response()->json([
                'agent_run_id' => $agentRun->id,
                'message' => $agentResponse['message'] ?? '',
                'intent' => $agentResponse['intent'] ?? null,
                'findings' => $agentResponse['findings'] ?? [],
                'recommended_actions' => $actions,
                'errors' => $agentResponse['errors'] ?? [],
            ]);
        } catch (Throwable $e) {
            $agentRun->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'agent_run_id' => $agentRun->id,
                'message' => 'I could not complete the risk review right now. No alerts or cases were created. Please try again or review the dashboard manually.',
                'intent' => null,
                'findings' => [],
                'recommended_actions' => [],
                'errors' => ['agent_service_unavailable'],
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
        return array_map(function (array $action) use ($agentRun): array {
            if (!($action['requires_approval'] ?? true)) {
                return $action;
            }

            $approval = AgentActionApproval::create([
                'agent_run_id' => $agentRun->id,
                'company_id' => $agentRun->company_id,
                'user_id' => $agentRun->user_id,
                'action_type' => (string)($action['type'] ?? 'unknown'),
                'action_payload' => $action['payload'] ?? [],
                'status' => 'pending',
            ]);

            return array_merge($action, ['approval_id' => $approval->id]);
        }, $actions);
    }
}
