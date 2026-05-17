<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\AgentRiskAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AgentToolController extends Controller
{
    public function companyContext(Request $request, string $companyId): JsonResponse
    {
        if (!Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (!$user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        try {
            $company = Company::find($companyId);
            if (!$company) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            return response()->json([
                'company_id' => $company->id,
                'company_name' => $company->name,
                'industry' => $company->industry,
                'timezone' => config('app.timezone', 'UTC'),
                'available_data_sources' => $this->availableDataSources($companyId),
                'user_role' => $user->role,
            ]);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'company_context', $e);
        }
    }

    public function riskSummary(Request $request, string $companyId, AgentRiskAnalysisService $riskAnalysisService): JsonResponse
    {
        if (!Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (!$user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        $period = $request->query('period');
        if ($period !== null && (!is_string($period) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period))) {
            return response()->json(['error' => 'Invalid period. Use YYYY-MM.'], 422);
        }

        try {
            if (!Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            return response()->json($riskAnalysisService->riskSummary($companyId, $period));
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'risk_summary', $e);
        }
    }

    private function authorizedUser(Request $request, string $companyId): ?User
    {
        $userId = $request->header('X-Brevix-User-Id');
        if (!$userId) {
            return null;
        }

        return User::where('id', $userId)
            ->where('company_id', $companyId)
            ->first();
    }

    private function availableDataSources(string $companyId): array
    {
        $sources = [];
        if (Upload::where('company_id', $companyId)->exists()) {
            $sources[] = 'file_upload';
        }

        return $sources;
    }

    private function safeToolFailure(Request $request, string $companyId, string $userId, string $toolName, Throwable $e): JsonResponse
    {
        Log::warning('agent_tool.failed', [
            'tool_name' => $toolName,
            'tool_endpoint' => $request->method() . ' ' . $request->path(),
            'company_id' => $companyId,
            'user_id' => $userId,
            'agent_request_id' => $request->header('X-Brevix-Agent-Request-Id'),
            'error_class' => $e::class,
        ]);

        return response()->json([
            'error' => 'Agent tool could not complete the request safely',
        ], 500);
    }
}
