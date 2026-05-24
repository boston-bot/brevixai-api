<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentActionApproval;
use App\Services\Agents\AgentActionExecutorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentApprovalController extends Controller
{
    public function __construct(private AgentActionExecutorService $executor) {}

    /**
     * POST /api/agent-approvals/{id}/approve
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $approval = AgentActionApproval::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (! $approval) {
            return response()->json(['error' => 'Approval not found'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['error' => 'This action has already been resolved.'], 409);
        }

        try {
            $result = $this->executor->execute($approval, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['error' => 'Execution failed. See the approval record for details.'], 422);
        }

        return response()->json([
            'approval_id' => $approval->id,
            'status'      => 'approved',
            'executed_at' => $approval->fresh()->executed_at,
            'result'      => [
                'resource_type' => $result->resourceType,
                'resource_id'   => $result->resourceId,
            ],
        ]);
    }

    /**
     * POST /api/agent-approvals/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $approval = AgentActionApproval::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (! $approval) {
            return response()->json(['error' => 'Approval not found'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['error' => 'This action has already been resolved.'], 409);
        }

        $approval->update([
            'status'      => 'rejected',
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
        ]);

        Log::info('agent.approval.rejected', [
            'approval_id'      => $approval->id,
            'agent_run_id'     => $approval->agent_run_id,
            'company_id'       => $approval->company_id,
            'rejector_user_id' => $request->user()->id,
            'action_type'      => $approval->action_type,
            'execution_status' => 'rejected',
        ]);

        return response()->json([
            'approval_id' => $approval->id,
            'status'      => 'rejected',
        ]);
    }
}
