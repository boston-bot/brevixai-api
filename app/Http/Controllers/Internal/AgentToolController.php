<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\AgentRiskAnalysisService;
use App\Services\Agents\AggregateRiskSummaryService;
use App\Services\Agents\AlertRecommendationService;
use App\Services\Agents\CaseRecommendationService;
use App\Services\Agents\EntityRelationshipRiskScoringService;
use App\Services\Agents\ReconciliationRiskScoringService;
use App\Services\Agents\VendorRiskScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AgentToolController extends Controller
{
    public function companyContext(Request $request, string $companyId): JsonResponse
    {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        $transactionFilterError = $this->transactionFilterValidationError($request);
        if ($transactionFilterError) {
            return $transactionFilterError;
        }

        try {
            $company = Company::find($companyId);
            if (! $company) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $payload = [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'industry' => $company->industry,
                'timezone' => config('app.timezone', 'UTC'),
                'available_data_sources' => $this->availableDataSources($companyId),
                'user_role' => $user->role,
            ];

            if ($this->shouldIncludeTransactions($request)) {
                $payload['transaction_summary'] = $this->transactionSummary($request, $companyId);
            }

            if ($this->shouldIncludeDashboard($request)) {
                $payload['dashboard_summary'] = $this->dashboardSummary($companyId);
            }

            return response()->json($payload);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'company_context', $e);
        }
    }

    public function riskSummary(Request $request, string $companyId, AgentRiskAnalysisService $riskAnalysisService): JsonResponse
    {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        $period = $request->query('period');
        if ($period !== null && (! is_string($period) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period))) {
            return response()->json(['error' => 'Invalid period. Use YYYY-MM.'], 422);
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            return response()->json($riskAnalysisService->riskSummary($companyId, $period));
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'risk_summary', $e);
        }
    }

    public function vendorRisk(Request $request, string $companyId, VendorRiskScoringService $vendorRiskService): JsonResponse
    {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        $vendorName = $request->query('vendor');

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            if ($vendorName !== null && $vendorName !== '') {
                $result = $vendorRiskService->scoreVendor($companyId, $vendorName);

                return response()->json($result);
            }

            $result = $vendorRiskService->scoreAllVendors($companyId);

            return response()->json(['vendors' => $result]);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'vendor_risk', $e);
        }
    }

    public function reconciliationRisk(
        Request $request,
        string $companyId,
        ReconciliationRiskScoringService $reconciliationRiskService
    ): JsonResponse {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $result = $reconciliationRiskService->scoreReconciliation($companyId);

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'reconciliation_risk', $e);
        }
    }

    public function entityRelationshipRisk(
        Request $request,
        string $companyId,
        EntityRelationshipRiskScoringService $entityRelationshipRiskService
    ): JsonResponse {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $result = $entityRelationshipRiskService->scoreEntityRelationships($companyId);

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'entity_relationship_risk', $e);
        }
    }

    public function aggregateRiskSummary(
        Request $request,
        string $companyId,
        AggregateRiskSummaryService $aggregateRiskSummaryService
    ): JsonResponse {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $result = $aggregateRiskSummaryService->getAggregateRiskSummary($companyId);

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'aggregate_risk_summary', $e);
        }
    }

    public function alertRecommendations(
        Request $request,
        string $companyId,
        AlertRecommendationService $alertRecommendationService
    ): JsonResponse {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $result = $alertRecommendationService->getAlertRecommendations($companyId);

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'alert_recommendations', $e);
        }
    }

    public function caseRecommendations(
        Request $request,
        string $companyId,
        CaseRecommendationService $caseRecommendationService
    ): JsonResponse {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $result = $caseRecommendationService->getCaseRecommendations($companyId);

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'case_recommendations', $e);
        }
    }

    public function transactions(Request $request, string $companyId): JsonResponse
    {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        $transactionFilterError = $this->transactionFilterValidationError($request);
        if ($transactionFilterError) {
            return $transactionFilterError;
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            return response()->json($this->transactionSummary($request, $companyId));
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'transaction_lookup', $e);
        }
    }

    public function dashboard(Request $request, string $companyId): JsonResponse
    {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            return response()->json($this->dashboardSummary($companyId));
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'dashboard_health', $e);
        }
    }

    private function shouldIncludeTransactions(Request $request): bool
    {
        return filter_var($request->query('include_transactions', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function shouldIncludeDashboard(Request $request): bool
    {
        return filter_var($request->query('include_dashboard', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function transactionFilterValidationError(Request $request): ?JsonResponse
    {
        if (! $this->shouldIncludeTransactions($request)) {
            return null;
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $field => $value) {
            if ($value !== null && (! is_string($value) || ! $this->isDateString($value))) {
                return response()->json(['error' => "Invalid {$field}. Use YYYY-MM-DD."], 422);
            }
        }

        if (is_string($dateFrom) && is_string($dateTo) && $dateFrom > $dateTo) {
            return response()->json(['error' => 'date_from must be before or equal to date_to.'], 422);
        }

        $limit = $request->query('limit');
        if ($limit !== null && filter_var($limit, FILTER_VALIDATE_INT) === false) {
            return response()->json(['error' => 'Invalid limit. Use an integer from 1 to 20.'], 422);
        }

        $limitValue = (int) ($limit ?? 10);
        if ($limitValue < 1 || $limitValue > 20) {
            return response()->json(['error' => 'Invalid limit. Use an integer from 1 to 20.'], 422);
        }

        return null;
    }

    private function isDateString(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function transactionSummary(Request $request, string $companyId): array
    {
        $limit = min(max((int) $request->query('limit', 10), 1), 20);
        $filters = [
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $query = DB::table('all_transactions')
            ->where('company_id', $companyId);

        if ($filters['date_from']) {
            $query->where('date', '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->where('date', '<=', $filters['date_to']);
        }

        $total = (clone $query)->count();
        $transactions = $query
            ->select([
                'id',
                'date',
                'vendor_customer',
                'amount',
                'type',
                'category',
                'anomaly_flag',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $transaction): array => $this->summarizeTransaction((array) $transaction))
            ->values()
            ->all();

        return [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'total' => (int) $total,
            'returned_count' => count($transactions),
            'transactions' => $transactions,
        ];
    }

    private function summarizeTransaction(array $transaction): array
    {
        return [
            'id' => (string) ($transaction['id'] ?? ''),
            'date' => $transaction['date'] ?? null,
            'vendor' => $transaction['vendor_customer'] ?? null,
            'amount' => (float) ($transaction['amount'] ?? 0),
            'type' => $transaction['type'] ?? null,
            'category' => $transaction['category'] ?? null,
            'status' => (bool) ($transaction['anomaly_flag'] ?? false) ? 'flagged' : 'completed',
            'anomaly_flag' => (bool) ($transaction['anomaly_flag'] ?? false),
        ];
    }

    private function dashboardSummary(string $companyId): array
    {
        $stats = DB::table('all_transactions')
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) AS total_transactions')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(vendor_customer), '')) AS vendors_monitored")
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) AS amount_reviewed')
            ->first();

        $openAlerts = DB::table('alerts')
            ->where('company_id', $companyId)
            ->where('status', 'open');
        $flaggedAlerts = (clone $openAlerts)->count();
        $criticalAlerts = (clone $openAlerts)->where('severity', 'critical')->count();
        $warningAlerts = (clone $openAlerts)->where('severity', 'warning')->count();

        return [
            'risk_score' => min(
                100,
                ((int) $criticalAlerts * 20)
                + ((int) $warningAlerts * 10)
                + max(0, (int) $flaggedAlerts - (int) $criticalAlerts - (int) $warningAlerts) * 4
            ),
            'total_transactions' => (int) ($stats->total_transactions ?? 0),
            'flagged_alerts' => (int) $flaggedAlerts,
            'vendors_monitored' => (int) ($stats->vendors_monitored ?? 0),
            'amount_reviewed' => (float) ($stats->amount_reviewed ?? 0),
        ];
    }

    private function authorizedUser(Request $request, string $companyId): ?User
    {
        $userId = $request->header('X-Brevix-User-Id');
        if (! $userId) {
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
            'tool_endpoint' => $request->method().' '.$request->path(),
            'company_id' => $companyId,
            'user_id' => $userId,
            'agent_request_id' => $request->header('X-Brevix-Agent-Request-Id'),
            'error_class' => $e::class,
            'error_code' => $e->getCode() ?: null,
        ]);

        return response()->json([
            'error' => 'Agent tool could not complete the request safely',
        ], 500);
    }
}
