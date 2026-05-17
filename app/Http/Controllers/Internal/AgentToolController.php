<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\AgentRiskAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentToolController extends Controller
{
    public function companyContext(Request $request, string $companyId): JsonResponse
    {
        $user = $this->authorizedUser($request, $companyId);
        if (!$user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

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
    }

    public function riskSummary(Request $request, string $companyId, AgentRiskAnalysisService $riskAnalysisService): JsonResponse
    {
        $user = $this->authorizedUser($request, $companyId);
        if (!$user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        if (!Company::where('id', $companyId)->exists()) {
            return response()->json(['error' => 'Company not found'], 404);
        }

        return response()->json($riskAnalysisService->riskSummary($companyId, $request->query('period')));
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

}
