<?php

namespace App\Services\Agents;

use App\Models\AgentActionApproval;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentActionExecutorService
{
    /** @return list<string> */
    public function supportedActionTypes(): array
    {
        return ['create_alert'];
    }

    /** @throws \InvalidArgumentException|\RuntimeException */
    public function execute(AgentActionApproval $approval, User $approver): AgentActionExecutionResult
    {
        return match ($approval->action_type) {
            'create_alert' => $this->createAlert($approval, $approver),
            default => throw new \InvalidArgumentException(
                "Unsupported action type: {$approval->action_type}"
            ),
        };
    }

    private function createAlert(AgentActionApproval $approval, User $approver): AgentActionExecutionResult
    {
        $payload = $approval->action_payload ?? [];

        try {
            $result = DB::transaction(function () use ($approval, $approver, $payload): AgentActionExecutionResult {
                $alert = Alert::create([
                    'company_id' => $approval->company_id,
                    'rule_key'   => (string) ($payload['rule_key'] ?? 'agent_recommendation'),
                    'severity'   => (string) ($payload['severity'] ?? 'warning'),
                    'title'      => (string) ($payload['title'] ?? 'Agent-Recommended Alert'),
                    'detail'     => isset($payload['detail']) ? (string) $payload['detail'] : null,
                    'evidence'   => is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [],
                    'status'     => 'open',
                ]);

                $approval->update([
                    'status'      => 'approved',
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                ]);

                return new AgentActionExecutionResult('alert', $alert->id);
            });

            Log::info('agent.approval.executed', [
                'approval_id'      => $approval->id,
                'agent_run_id'     => $approval->agent_run_id,
                'company_id'       => $approval->company_id,
                'approver_user_id' => $approver->id,
                'action_type'      => $approval->action_type,
                'resource_type'    => $result->resourceType,
                'resource_id'      => $result->resourceId,
                'execution_status' => 'success',
            ]);

            return $result;
        } catch (Throwable $e) {
            // Runs after the transaction has rolled back — safe to persist failure metadata.
            $approval->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::warning('agent.approval.execution_failed', [
                'approval_id'  => $approval->id,
                'agent_run_id' => $approval->agent_run_id,
                'action_type'  => $approval->action_type,
                'company_id'   => $approval->company_id,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
