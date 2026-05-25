<?php

namespace App\Services\Agents;

use App\Models\AgentActionApproval;
use App\Models\Alert;
use App\Models\AuditCase;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AgentActionExecutorService
{
    /** @return list<string> */
    public function supportedActionTypes(): array
    {
        return ['create_alert', 'create_case', 'flag_transaction', 'escalate_review'];
    }

    /** @throws \InvalidArgumentException|\RuntimeException */
    public function execute(AgentActionApproval $approval, User $approver): AgentActionExecutionResult
    {
        return match ($approval->action_type) {
            'create_alert'      => $this->createAlert($approval, $approver),
            'create_case'       => $this->createCase($approval, $approver),
            'flag_transaction'  => $this->flagTransaction($approval, $approver),
            'escalate_review'   => $this->escalateReview($approval, $approver),
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
                $attributes = [
                    'company_id'              => $approval->company_id,
                    'rule_key'                => (string) ($payload['rule_key'] ?? 'agent_recommendation'),
                    'severity'                => (string) ($payload['severity'] ?? 'warning'),
                    'title'                   => (string) ($payload['title'] ?? 'Agent-Recommended Alert'),
                    'detail'                  => isset($payload['detail']) ? (string) $payload['detail'] : null,
                    'evidence'                => is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [],
                    'status'                  => 'open',
                    // Evidence contract fields from agent finding
                    'source_system'           => 'brevix_agent',
                    'reason_codes'            => is_array($payload['reason_codes'] ?? null) ? $payload['reason_codes'] : [],
                    'confidence_score'        => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
                    'evidence_refs'           => is_array($payload['evidence_refs'] ?? null) ? $payload['evidence_refs'] : [],
                    'comparison_window'       => is_array($payload['comparison_window'] ?? null) ? $payload['comparison_window'] : null,
                    'source_recommendation_id' => isset($payload['source_recommendation_id'])
                        ? (string) $payload['source_recommendation_id']
                        : null,
                ];
                if ($approval->business_profile_id && Schema::hasColumn('alerts', 'business_profile_id')) {
                    $attributes['business_profile_id'] = $approval->business_profile_id;
                }

                $alert = Alert::create($attributes);

                $approval->update([
                    'status'      => 'approved',
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                ]);

                return new AgentActionExecutionResult('alert', $alert->id);
            });

            $this->logExecution($approval, $approver, $result, 'success');

            // Fire notification best-effort — never fail the primary operation
            try {
                $company = Company::find($approval->company_id);
                $createdAlert = Alert::find($result->resourceId);
                if ($company && $createdAlert) {
                    app(NotificationService::class)->notifyOnAlertCreated($createdAlert, $company);
                }
            } catch (Throwable) {
                // Notification failure is non-fatal
            }

            return $result;
        } catch (Throwable $e) {
            $this->logFailure($approval, $e);
            throw $e;
        }
    }

    private function createCase(AgentActionApproval $approval, User $approver): AgentActionExecutionResult
    {
        $payload = $approval->action_payload ?? [];

        try {
            $result = DB::transaction(function () use ($approval, $approver, $payload): AgentActionExecutionResult {
                $attributes = [
                    'company_id'            => $approval->company_id,
                    'title'                 => (string) ($payload['title'] ?? 'Agent-Recommended Investigation'),
                    'description'           => isset($payload['description']) ? (string) $payload['description'] : null,
                    'status'                => 'open',
                    'severity'              => (string) ($payload['severity'] ?? 'medium'),
                    'investigation_status'  => AuditCase::INVESTIGATION_STATUS_OPEN,
                    'investigation_priority' => (string) ($payload['priority'] ?? AuditCase::INVESTIGATION_PRIORITY_MEDIUM),
                ];
                if ($approval->business_profile_id && Schema::hasColumn('audit_cases', 'business_profile_id')) {
                    $attributes['business_profile_id'] = $approval->business_profile_id;
                }

                $case = AuditCase::create($attributes);

                $approval->update([
                    'status'      => 'approved',
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                ]);

                return new AgentActionExecutionResult('case', $case->id);
            });

            $this->logExecution($approval, $approver, $result, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logFailure($approval, $e);
            throw $e;
        }
    }

    private function flagTransaction(AgentActionApproval $approval, User $approver): AgentActionExecutionResult
    {
        $payload = $approval->action_payload ?? [];
        $transactionId = (string) ($payload['transaction_id'] ?? '');

        if (! $transactionId) {
            throw new \InvalidArgumentException('flag_transaction requires a transaction_id in the payload.');
        }

        try {
            $result = DB::transaction(function () use ($approval, $approver, $transactionId): AgentActionExecutionResult {
                $transaction = Transaction::where('id', $transactionId)
                    ->where('company_id', $approval->company_id);
                if ($approval->business_profile_id && Schema::hasColumn('transactions', 'business_profile_id')) {
                    $transaction->where('business_profile_id', $approval->business_profile_id);
                }
                $transaction = $transaction->firstOrFail();

                // Mark the transaction with an anomaly flag and reason if not already flagged
                $transaction->update([
                    'anomaly_flag'   => true,
                    'anomaly_reason' => $transaction->anomaly_reason ?? 'Flagged via agent recommendation for manual review.',
                ]);

                // Record the review action in transaction_reviews (upsert to avoid duplicate errors)
                DB::table('transaction_reviews')->upsert(
                    [
                        'company_id'     => $approval->company_id,
                        'transaction_id' => $transactionId,
                        'marked_by'      => $approver->id,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ],
                    ['company_id', 'transaction_id'],
                    ['marked_by', 'updated_at'],
                );

                $approval->update([
                    'status'      => 'approved',
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                ]);

                return new AgentActionExecutionResult('transaction', $transaction->id);
            });

            $this->logExecution($approval, $approver, $result, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logFailure($approval, $e);
            throw $e;
        }
    }

    private function escalateReview(AgentActionApproval $approval, User $approver): AgentActionExecutionResult
    {
        $payload = $approval->action_payload ?? [];
        $caseId = (string) ($payload['case_id'] ?? '');

        if (! $caseId) {
            throw new \InvalidArgumentException('escalate_review requires a case_id in the payload.');
        }

        try {
            $result = DB::transaction(function () use ($approval, $approver, $caseId): AgentActionExecutionResult {
                $case = AuditCase::where('id', $caseId)
                    ->where('company_id', $approval->company_id);
                if ($approval->business_profile_id && Schema::hasColumn('audit_cases', 'business_profile_id')) {
                    $case->where('business_profile_id', $approval->business_profile_id);
                }
                $case = $case->firstOrFail();

                $case->update([
                    'investigation_status' => AuditCase::INVESTIGATION_STATUS_ESCALATED,
                ]);

                $approval->update([
                    'status'      => 'approved',
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'executed_at' => now(),
                ]);

                return new AgentActionExecutionResult('case', $case->id);
            });

            $this->logExecution($approval, $approver, $result, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logFailure($approval, $e);
            throw $e;
        }
    }

    private function logExecution(AgentActionApproval $approval, User $approver, AgentActionExecutionResult $result, string $status): void
    {
        Log::info('agent.approval.executed', [
            'approval_id'      => $approval->id,
            'agent_run_id'     => $approval->agent_run_id,
            'company_id'       => $approval->company_id,
            'approver_user_id' => $approver->id,
            'action_type'      => $approval->action_type,
            'resource_type'    => $result->resourceType,
            'resource_id'      => $result->resourceId,
            'execution_status' => $status,
        ]);
    }

    private function logFailure(AgentActionApproval $approval, Throwable $e): void
    {
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
    }
}
