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
    /** @throws \InvalidArgumentException|\RuntimeException */
    public function execute(AgentActionApproval $approval, User $approver): void
    {
        match ($approval->action_type) {
            'create_alert' => $this->createAlert($approval, $approver),
            default => throw new \InvalidArgumentException(
                "Unsupported action type: {$approval->action_type}"
            ),
        };
    }

    private function createAlert(AgentActionApproval $approval, User $approver): void
    {
        $payload = $approval->action_payload ?? [];

        DB::transaction(function () use ($approval, $approver, $payload): void {
            try {
                Alert::create([
                    'company_id' => $approval->company_id,
                    'rule_key' => (string) ($payload['rule_key'] ?? 'agent_recommendation'),
                    'severity' => (string) ($payload['severity'] ?? 'warning'),
                    'title' => (string) ($payload['title'] ?? 'Agent-Recommended Alert'),
                    'detail' => isset($payload['detail']) ? (string) $payload['detail'] : null,
                    'evidence' => is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [],
                    'status' => 'open',
                ]);

                $approval->update([
                    'status' => 'approved',
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                ]);
            } catch (Throwable $e) {
                $approval->update([
                    'failed_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);

                Log::warning('agent.approval.execution_failed', [
                    'approval_id' => $approval->id,
                    'action_type' => $approval->action_type,
                    'company_id' => $approval->company_id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }
}
