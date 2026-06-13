<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\BusinessProfileAccessException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use App\Services\Agents\AgentActionExecutorService;
use App\Services\Agents\AgentRiskAnalysisService;
use App\Services\Agents\AggregateRiskSummaryService;
use App\Services\Agents\AlertRecommendationService;
use App\Services\Agents\BehavioralBaselineService;
use App\Services\Agents\CaseRecommendationService;
use App\Services\Agents\EntityRelationshipRiskScoringService;
use App\Services\Agents\ReconciliationRiskScoringService;
use App\Services\Agents\VendorRiskScoringService;
use App\Services\BusinessProfileContext;
use App\Services\BusinessProfileContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AgentToolController extends Controller
{
    public function __construct(private readonly BusinessProfileContextService $businessProfileContext) {}

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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $payload = [
                'company_id' => $company->id,
                'company_user_id' => $user->id,
                'business_profile_id' => $context->businessProfileId,
                'company_name' => $company->name,
                'industry' => $company->industry,
                'timezone' => config('app.timezone', 'UTC'),
                'available_data_sources' => $this->availableDataSources($context->companyId, $context->businessProfileId),
                'user_role' => $user->role,
            ];

            if ($this->shouldIncludeTransactions($request)) {
                $payload['transaction_summary'] = $this->transactionSummary($request, $context->companyId, $context->businessProfileId);
            }

            if ($this->shouldIncludeDashboard($request)) {
                $payload['dashboard_summary'] = $this->dashboardSummary($context->companyId, $context->businessProfileId);
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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            return response()->json($riskAnalysisService->riskSummary($context->companyId, $period, $context->businessProfileId));
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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            if ($vendorName !== null && $vendorName !== '') {
                $result = $vendorRiskService->scoreVendor($context->companyId, $vendorName, $context->businessProfileId);

                return response()->json($result);
            }

            $result = $vendorRiskService->scoreAllVendors($context->companyId, $context->businessProfileId);

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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $result = $reconciliationRiskService->scoreReconciliation($context->companyId, $context->businessProfileId);

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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $result = $entityRelationshipRiskService->scoreEntityRelationships($context->companyId, $context->businessProfileId);

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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $result = $aggregateRiskSummaryService->getAggregateRiskSummary($context->companyId, $context->businessProfileId);

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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $result = $alertRecommendationService->getAlertRecommendations($context->companyId, $context->businessProfileId);

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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $result = $caseRecommendationService->getCaseRecommendations($context->companyId, $context->businessProfileId);

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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            return response()->json($this->transactionSummary($request, $context->companyId, $context->businessProfileId));
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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            return response()->json($this->dashboardSummary($context->companyId, $context->businessProfileId));
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'dashboard_health', $e);
        }
    }

    public function processRegistry(Request $request, AgentActionExecutorService $executorService): JsonResponse
    {
        try {
            $executableTypes = $executorService->supportedActionTypes();

            $actionTypes = [
                ['type' => 'create_alert',    'requires_approval' => true,  'executable' => in_array('create_alert', $executableTypes),    'display_name' => 'Create Alert'],
                ['type' => 'draft_case',       'requires_approval' => true,  'executable' => in_array('draft_case', $executableTypes),       'display_name' => 'Draft Case'],
                ['type' => 'send_email',       'requires_approval' => true,  'executable' => in_array('send_email', $executableTypes),       'display_name' => 'Send Email'],
                ['type' => 'flag_transaction', 'requires_approval' => true,  'executable' => in_array('flag_transaction', $executableTypes), 'display_name' => 'Flag Transaction'],
                ['type' => 'finalize_case',    'requires_approval' => true,  'executable' => in_array('finalize_case', $executableTypes),    'display_name' => 'Finalize Case'],
                ['type' => 'update_case',      'requires_approval' => true,  'executable' => in_array('update_case', $executableTypes),      'display_name' => 'Update Case'],
                ['type' => 'review_dashboard', 'requires_approval' => false, 'executable' => false, 'display_name' => 'Review Dashboard'],
                ['type' => 'review_findings',  'requires_approval' => false, 'executable' => false, 'display_name' => 'Review Findings'],
            ];

            return response()->json(['action_types' => $actionTypes]);
        } catch (Throwable $e) {
            Log::warning('agent_tool.failed', [
                'tool_name' => 'process_registry',
                'tool_endpoint' => $request->method().' '.$request->path(),
                'agent_request_id' => $request->header('X-Brevix-Agent-Request-Id'),
                'error_class' => $e::class,
            ]);

            return response()->json(['error' => 'Agent tool could not complete the request safely'], 500);
        }
    }

    public function pendingRecommendations(
        Request $request,
        string $companyId,
        AlertRecommendationService $alertRecommendationService,
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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $alertResult = $alertRecommendationService->getAlertRecommendations($context->companyId, $context->businessProfileId);
            $caseResult = $caseRecommendationService->getCaseRecommendations($context->companyId, $context->businessProfileId);

            return response()->json([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'alert_recommendations' => $alertResult['recommended_alerts'] ?? [],
                'case_recommendations' => $caseResult['case_recommendations'] ?? [],
            ]);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'pending_recommendations', $e);
        }
    }

    public function transactionDetail(Request $request, string $companyId): JsonResponse
    {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        $ids = $request->query('ids');
        if (! is_array($ids) || count($ids) === 0) {
            return response()->json(['error' => 'ids must be a non-empty array of transaction UUIDs'], 422);
        }

        if (count($ids) > 20) {
            return response()->json(['error' => 'Maximum 20 transaction IDs per request'], 422);
        }

        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                return response()->json(['error' => 'Each id must be a valid UUID'], 422);
            }
        }

        try {
            if (! Company::where('id', $companyId)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $transactions = Transaction::where('company_id', $companyId)
                ->when(
                    $context->businessProfileId && Schema::hasColumn('transactions', 'business_profile_id'),
                    fn ($query) => $query->where('business_profile_id', $context->businessProfileId),
                )
                ->whereIn('id', $ids)
                ->get()
                ->map(function (Transaction $t) use ($context, $request): array {
                    $vendorName = $t->vendor_customer ?? '';
                    $vendorId = $vendorName ? md5($context->companyId . '|vendor|' . strtolower(trim($vendorName))) : null;
                    return [
                        'id' => (string) $t->id,
                        'company_id' => $context->companyId,
                        'company_user_id' => $request->header('X-Brevix-User-Id') ?? 'system',
                        'vendor_id' => $vendorId,
                        'approved_by' => md5($context->companyId . '|approver|' . $t->id),
                        'document_id' => md5($context->companyId . '|document|' . $t->id),
                        'bank_account_id' => md5($context->companyId . '|bank_account|default'),
                        'date' => $t->date,
                        'vendor' => $vendorName ?: null,
                        'amount' => (float) $t->amount,
                        'type' => $t->type,
                        'category' => $t->category,
                        'payment_method' => $t->payment_method,
                        'anomaly_flag' => (bool) $t->anomaly_flag,
                        'anomaly_reason' => $t->anomaly_reason,
                        'memo' => $t->memo,
                    ];
                })
                ->values()
                ->all();

            return response()->json([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'requested_count' => count($ids),
                'found_count' => count($transactions),
                'transactions' => $transactions,
            ]);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'transaction_detail', $e);
        }
    }

    public function behavioralBaseline(
        Request $request,
        string $companyId,
        BehavioralBaselineService $behavioralBaselineService
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

            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            return response()->json($behavioralBaselineService->scoreDeviation($context->companyId, $context->businessProfileId));
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'behavioral_baseline', $e);
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
            return response()->json(['error' => 'Invalid limit. Use an integer from 1 to 500.'], 422);
        }

        $limitValue = (int) ($limit ?? 10);
        if ($limitValue < 1 || $limitValue > 500) {
            return response()->json(['error' => 'Invalid limit. Use an integer from 1 to 500.'], 422);
        }

        return null;
    }

    private function isDateString(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function transactionSummary(Request $request, string $companyId, ?string $businessProfileId = null): array
    {
        $limit = min(max((int) $request->query('limit', 10), 1), 500);
        $filters = [
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $query = DB::table('all_transactions')
            ->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

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
            ->map(fn (object $transaction): array => $this->summarizeTransaction((array) $transaction, $companyId, $request->header('X-Brevix-User-Id')))
            ->values()
            ->all();

        return [
            'business_profile_id' => $businessProfileId,
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'total' => (int) $total,
            'returned_count' => count($transactions),
            'transactions' => $transactions,
        ];
    }

    private function summarizeTransaction(array $transaction, string $companyId, ?string $userId = null): array
    {
        $vendorName = $transaction['vendor_customer'] ?? '';
        $vendorId = $vendorName ? md5($companyId . '|vendor|' . strtolower(trim($vendorName))) : null;

        return [
            'id' => (string) ($transaction['id'] ?? ''),
            'company_id' => $companyId,
            'company_user_id' => $userId ?? 'system',
            'vendor_id' => $vendorId,
            'approved_by' => md5($companyId . '|approver|' . ($transaction['id'] ?? '')),
            'document_id' => md5($companyId . '|document|' . ($transaction['id'] ?? '')),
            'bank_account_id' => md5($companyId . '|bank_account|default'),
            'date' => $transaction['date'] ?? null,
            'vendor' => $vendorName ?: null,
            'amount' => (float) ($transaction['amount'] ?? 0),
            'type' => $transaction['type'] ?? null,
            'category' => $transaction['category'] ?? null,
            'status' => (bool) ($transaction['anomaly_flag'] ?? false) ? 'flagged' : 'completed',
            'anomaly_flag' => (bool) ($transaction['anomaly_flag'] ?? false),
        ];
    }

    private function dashboardSummary(string $companyId, ?string $businessProfileId = null): array
    {
        $stats = DB::table('all_transactions')
            ->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('all_transactions', 'business_profile_id')) {
            $stats->where('business_profile_id', $businessProfileId);
        }

        $stats = $stats
            ->selectRaw('COUNT(*) AS total_transactions')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(vendor_customer), '')) AS vendors_monitored")
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) AS amount_reviewed')
            ->first();

        $openAlerts = DB::table('alerts')
            ->where('company_id', $companyId)
            ->where('status', 'open');
        if ($businessProfileId && Schema::hasColumn('alerts', 'business_profile_id')) {
            $openAlerts->where('business_profile_id', $businessProfileId);
        }
        $flaggedAlerts = (clone $openAlerts)->count();
        $criticalAlerts = (clone $openAlerts)->where('severity', 'critical')->count();
        $warningAlerts = (clone $openAlerts)->where('severity', 'warning')->count();

        return [
            'business_profile_id' => $businessProfileId,
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

    public function storeFindings(Request $request, string $companyId): JsonResponse
    {
        if (! Str::isUuid($companyId)) {
            return response()->json(['error' => 'Invalid company id'], 422);
        }

        $user = $this->authorizedUser($request, $companyId);
        if (! $user) {
            return response()->json(['error' => 'User is not authorized for this company'], 403);
        }

        $data = $request->validate([
            'agent_run_id'          => ['nullable', 'string', 'max:255'],
            'findings'              => ['required', 'array', 'max:50'],
            'findings.*.title'      => ['required', 'string', 'max:500'],
            'findings.*.severity'   => ['required', 'string', 'in:info,low,medium,high,critical'],
            'findings.*.summary'    => ['nullable', 'string', 'max:5000'],
            'findings.*.confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'findings.*.evidence'   => ['nullable', 'array'],
        ]);

        if (! Company::where('id', $companyId)->exists()) {
            return response()->json(['error' => 'Company not found'], 404);
        }

        try {
            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $agentRunId = $data['agent_run_id'] ?? null;
            $runUuid = ($agentRunId && Str::isUuid($agentRunId)) ? $agentRunId : null;
            $upserted = [];

            foreach ($data['findings'] as $finding) {
                // Dedup key: same finding title from rex_agent for the same company.
                // We update evidence/confidence on repeat but never reopen a dismissed alert.
                $alert = \App\Models\Alert::firstOrNew([
                    'company_id'    => $context->companyId,
                    'title'         => $finding['title'],
                    'source_system' => 'rex_agent',
                ]);

                $alert->fill([
                    'rule_key'                 => 'agent_finding',
                    'severity'                 => $finding['severity'],
                    'detail'                   => $finding['summary'] ?? null,
                    'evidence'                 => $finding['evidence'] ?? null,
                    'confidence_score'         => isset($finding['confidence']) ? round((float) $finding['confidence'], 4) : null,
                    'source_recommendation_id' => $runUuid,
                    'priority_score'           => $this->severityToPriority($finding['severity']),
                ]);

                if (! $alert->exists) {
                    $alert->status = 'open';
                    if ($context->businessProfileId) {
                        $alert->business_profile_id = $context->businessProfileId;
                    }
                }

                $alert->save();
                $upserted[] = (string) $alert->id;
            }

            return response()->json(['stored' => count($upserted), 'alert_ids' => $upserted]);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'store_findings', $e);
        }
    }

    private function severityToPriority(string $severity): int
    {
        return match ($severity) {
            'critical' => 90,
            'high'     => 75,
            'medium'   => 50,
            'low'      => 30,
            default    => 10,
        };
    }

    private function authorizedUser(Request $request, string $companyId): ?User
    {
        $userId = $request->header('X-Brevix-User-Id');
        if (! $userId) {
            return null;
        }

        $user = User::where('id', $userId)->first();
        if (! $user) {
            return null;
        }

        if ((string) $user->company_id === $companyId) {
            return $user;
        }

        return $this->businessProfileContext->workspaceRole($user, $companyId) ? $user : null;
    }

    private function availableDataSources(string $companyId, ?string $businessProfileId = null): array
    {
        $sources = [];
        $uploadQuery = Upload::where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('uploads', 'business_profile_id')) {
            $uploadQuery->where('business_profile_id', $businessProfileId);
        }

        if ($uploadQuery->exists()) {
            $sources[] = 'file_upload';
        }

        return $sources;
    }

    private function businessProfileId(Request $request): ?string
    {
        $businessProfileId = $request->header('X-Brevix-Business-Profile-Id');

        return is_string($businessProfileId) && $businessProfileId !== '' ? $businessProfileId : null;
    }

    private function profileContext(Request $request, User $user, string $companyId): BusinessProfileContext|JsonResponse
    {
        try {
            return $this->businessProfileContext->resolveForUser($user, $companyId, $this->businessProfileId($request));
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
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
