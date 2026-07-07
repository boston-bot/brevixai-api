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
use App\Services\SourceFindingMaterializationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AgentToolController extends Controller
{
    public function __construct(
        private readonly BusinessProfileContextService $businessProfileContext,
        private readonly SourceFindingMaterializationService $sourceFindingMaterialization,
    ) {}

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
                ['type' => 'create_investigation', 'requires_approval' => true,  'executable' => in_array('create_investigation', $executableTypes), 'display_name' => 'Create Investigation'],
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
            'agent_run_id' => ['nullable', 'string', 'max:255'],
            'findings' => ['required', 'array', 'max:50'],
            'findings.*.id' => ['sometimes', 'string', 'max:255'],
            'findings.*.title' => ['required', 'string', 'max:500'],
            'findings.*.severity' => ['required', 'string', 'in:info,low,medium,high,critical,warning'],
            'findings.*.summary' => ['nullable', 'string', 'max:5000'],
            'findings.*.detail' => ['nullable', 'string', 'max:10000'],
            'findings.*.confidence' => ['nullable'],
            'findings.*.confidenceScore' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'findings.*.confidence_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'findings.*.category' => ['nullable', 'string', 'max:80'],
            'findings.*.risk_type' => ['nullable', 'string', 'max:120'],
            'findings.*.reasonCode' => ['nullable', 'string', 'max:120'],
            'findings.*.reason_code' => ['nullable', 'string', 'max:120'],
            'findings.*.sourceModule' => ['nullable', 'string', 'max:120'],
            'findings.*.source_module' => ['nullable', 'string', 'max:120'],
            'findings.*.sourceRecordType' => ['nullable', 'string', 'max:120'],
            'findings.*.source_record_type' => ['nullable', 'string', 'max:120'],
            'findings.*.sourceRecordId' => ['nullable', 'string', 'max:255'],
            'findings.*.source_record_id' => ['nullable', 'string', 'max:255'],
            'findings.*.evidence' => ['nullable', 'array'],
            'findings.*.evidenceRefs' => ['nullable', 'array'],
            'findings.*.suggestedRecords' => ['nullable', 'array'],
            'findings.*.recommended_next_steps' => ['nullable', 'array'],
            'findings.*.limitations' => ['nullable', 'array'],
            'findings.*.recommendedAction' => ['nullable', 'array'],
            'findings.*.recommended_action' => ['nullable', 'array'],
        ]);

        if (! Company::where('id', $companyId)->exists()) {
            return response()->json(['error' => 'Company not found'], 404);
        }

        try {
            $context = $this->profileContext($request, $user, $companyId);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $materialized = $this->sourceFindingMaterialization->materializePayload(
                $context,
                $user,
                $this->agentFindingsProjection($data['findings'], $data['agent_run_id'] ?? null),
            );

            return response()->json([
                'stored' => (int) ($materialized['materialized']['findings'] ?? 0),
                'finding_ids' => collect($materialized['findings'] ?? [])
                    ->pluck('id')
                    ->filter()
                    ->values()
                    ->all(),
                'materialized' => $materialized['materialized'] ?? [],
                'findings' => $materialized['findings'] ?? [],
            ], 201);
        } catch (Throwable $e) {
            return $this->safeToolFailure($request, $companyId, $user->id, 'store_findings', $e);
        }
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function agentFindingsProjection(array $findings, ?string $agentRunId): array
    {
        $projectedFindings = [];
        $evidenceItems = [];
        $suggestedRecords = [];

        foreach ($findings as $index => $finding) {
            $sourceRecordType = $this->sourceRecordType($finding);
            $sourceRecordId = $this->sourceRecordId($finding, $sourceRecordType);
            $projectedFindingId = "finding:rex_agent:{$sourceRecordType}:{$sourceRecordId}";
            $evidenceRefs = $this->agentEvidenceItems($finding, $projectedFindingId, $sourceRecordId);
            $records = $this->agentSuggestedRecords($finding, $projectedFindingId);

            $projectedFindings[] = [
                'id' => $projectedFindingId,
                'category' => $this->agentFindingCategory($finding),
                'sourceModule' => (string) ($finding['sourceModule'] ?? $finding['source_module'] ?? 'rex_agent'),
                'sourceRecordType' => $sourceRecordType,
                'sourceRecordId' => $sourceRecordId,
                'title' => (string) $finding['title'],
                'summary' => $this->nullableString($finding['summary'] ?? null),
                'detail' => $this->nullableString($finding['detail'] ?? $finding['summary'] ?? null),
                'severity' => $this->canonicalFindingSeverity((string) $finding['severity']),
                'confidence' => $this->confidenceLabel($finding['confidence'] ?? $finding['confidenceScore'] ?? $finding['confidence_score'] ?? null),
                'confidenceScore' => $this->confidenceScore($finding['confidenceScore'] ?? $finding['confidence_score'] ?? $finding['confidence'] ?? null),
                'reasonCode' => $this->nullableString($finding['reasonCode'] ?? $finding['reason_code'] ?? $finding['risk_type'] ?? 'agent_finding'),
                'status' => 'new',
                'evidenceRefs' => $evidenceRefs,
                'suggestedRecords' => $records,
                'recommendedAction' => is_array($finding['recommendedAction'] ?? null)
                    ? $finding['recommendedAction']
                    : (is_array($finding['recommended_action'] ?? null)
                        ? $finding['recommended_action']
                        : [
                            'key' => 'review_agent_finding',
                            'label' => 'Review finding',
                            'requiresConfirmation' => true,
                        ]),
                'limitations' => array_values(array_filter(array_map(
                    fn (mixed $item): string => (string) $item,
                    is_array($finding['limitations'] ?? null) ? $finding['limitations'] : [],
                ))),
                'metadata' => [
                    'agent_run_id' => $agentRunId,
                    'agent_finding_index' => $index,
                ],
            ];

            $evidenceItems = array_merge($evidenceItems, $evidenceRefs);
            $suggestedRecords = array_merge($suggestedRecords, $records);
        }

        return [
            'contractVersion' => '2026-06-12',
            'filters' => ['sourceModule' => 'rex_agent'],
            'findings' => $projectedFindings,
            'evidenceItems' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
        ];
    }

    /** @param array<string, mixed> $finding */
    private function sourceRecordType(array $finding): string
    {
        $explicit = $finding['sourceRecordType'] ?? $finding['source_record_type'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return Str::slug($explicit, '_') ?: 'agent_finding';
        }

        $riskType = $finding['risk_type'] ?? $finding['reasonCode'] ?? $finding['reason_code'] ?? null;
        if (is_string($riskType) && trim($riskType) !== '') {
            return Str::slug($riskType, '_') ?: 'agent_finding';
        }

        return 'agent_finding';
    }

    /** @param array<string, mixed> $finding */
    private function sourceRecordId(array $finding, string $sourceRecordType): string
    {
        $explicit = $finding['sourceRecordId'] ?? $finding['source_record_id'] ?? $finding['id'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        return hash('sha256', implode('|', [
            'rex_agent',
            $sourceRecordType,
            (string) ($finding['title'] ?? ''),
            (string) ($finding['summary'] ?? ''),
        ]));
    }

    /**
     * @param array<string, mixed> $finding
     * @return list<array<string, mixed>>
     */
    private function agentEvidenceItems(array $finding, string $findingId, string $sourceRecordId): array
    {
        $rawEvidence = $finding['evidenceRefs'] ?? $finding['evidence'] ?? [];
        if (! is_array($rawEvidence)) {
            return [];
        }

        $items = [];
        foreach (array_values($rawEvidence) as $index => $evidence) {
            if (! is_array($evidence)) {
                continue;
            }

            $type = (string) ($evidence['evidenceType'] ?? $evidence['evidence_type'] ?? $evidence['type'] ?? 'system_summary');
            if ($type === 'recommended_next_steps') {
                continue;
            }

            $evidenceSourceRecordId = $this->nullableString(
                $evidence['sourceRecordId'] ?? $evidence['source_record_id'] ?? $evidence['id'] ?? $sourceRecordId
            );

            $items[] = [
                'id' => (string) ($evidence['id'] ?? "{$findingId}:evidence:{$index}"),
                'findingId' => $findingId,
                'evidenceType' => $type,
                'sourceType' => $this->nullableString($evidence['sourceType'] ?? $evidence['source_type'] ?? $evidence['type'] ?? 'rex_agent'),
                'sourceId' => $this->nullableString($evidence['sourceId'] ?? $evidence['source_id'] ?? null),
                'sourceRecordId' => $evidenceSourceRecordId,
                'title' => (string) ($evidence['title'] ?? $evidence['label'] ?? Str::headline($type)),
                'summary' => $this->nullableString($evidence['summary'] ?? $evidence['description'] ?? null),
                'citationLabel' => $this->nullableString($evidence['citationLabel'] ?? $evidence['citation_label'] ?? null),
                'sourceRowRange' => $this->nullableString($evidence['sourceRowRange'] ?? $evidence['source_row_range'] ?? null),
                'addedByActorType' => 'agent',
                'metadata' => $this->safeAgentMetadata($evidence, [
                    'id',
                    'type',
                    'evidenceType',
                    'evidence_type',
                    'sourceType',
                    'source_type',
                    'sourceId',
                    'source_id',
                    'sourceRecordId',
                    'source_record_id',
                    'title',
                    'label',
                    'summary',
                    'description',
                    'citationLabel',
                    'citation_label',
                    'sourceRowRange',
                    'source_row_range',
                ]),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $finding
     * @return list<array<string, mixed>>
     */
    private function agentSuggestedRecords(array $finding, string $findingId): array
    {
        $records = [];
        $explicit = $finding['suggestedRecords'] ?? $finding['suggested_records'] ?? [];
        if (is_array($explicit)) {
            foreach (array_values($explicit) as $index => $record) {
                if (! is_array($record)) {
                    continue;
                }
                $records[] = [
                    'id' => (string) ($record['id'] ?? "{$findingId}:suggested-record:{$index}"),
                    'findingId' => $findingId,
                    'recordType' => (string) ($record['recordType'] ?? $record['record_type'] ?? 'agent_finding_support'),
                    'label' => (string) ($record['label'] ?? $record['title'] ?? 'Supporting record'),
                    'reason' => $this->nullableString($record['reason'] ?? $record['description'] ?? 'Requested to support reviewer resolution of this Rex finding.'),
                    'priority' => (string) ($record['priority'] ?? 'recommended'),
                    'status' => (string) ($record['status'] ?? 'requested'),
                ];
            }
        }

        $nextSteps = $finding['recommended_next_steps'] ?? [];
        $rawEvidence = $finding['evidence'] ?? [];
        if (is_array($rawEvidence)) {
            foreach ($rawEvidence as $evidence) {
                if (is_array($evidence) && ($evidence['type'] ?? null) === 'recommended_next_steps' && is_array($evidence['steps'] ?? null)) {
                    $nextSteps = array_merge(is_array($nextSteps) ? $nextSteps : [], $evidence['steps']);
                }
            }
        }

        foreach (array_values(is_array($nextSteps) ? $nextSteps : []) as $index => $step) {
            $label = is_string($step) ? $step : (is_array($step) ? (string) ($step['label'] ?? $step['title'] ?? 'Recommended follow-up') : '');
            if ($label === '') {
                continue;
            }

            $records[] = [
                'id' => "{$findingId}:recommended-step:{$index}",
                'findingId' => $findingId,
                'recordType' => 'agent_recommended_follow_up',
                'label' => Str::limit($label, 120, ''),
                'reason' => $label,
                'priority' => 'recommended',
                'status' => 'requested',
            ];
        }

        return $records;
    }

    /** @param array<string, mixed> $finding */
    private function agentFindingCategory(array $finding): string
    {
        $category = $finding['category'] ?? null;
        if (is_string($category) && trim($category) !== '') {
            return Str::slug($category, '_') ?: 'unsure';
        }

        $text = strtolower(implode(' ', array_filter([
            $finding['risk_type'] ?? null,
            $finding['reasonCode'] ?? null,
            $finding['reason_code'] ?? null,
            $finding['title'] ?? null,
            $finding['summary'] ?? null,
        ], fn (mixed $value): bool => is_scalar($value))));

        return match (true) {
            str_contains($text, 'tax') || str_contains($text, 'irs') => 'tax',
            str_contains($text, 'payroll') => 'payroll',
            str_contains($text, 'reconcil') => 'reconciliation',
            str_contains($text, 'control') || str_contains($text, 'approval') => 'controls',
            str_contains($text, 'vendor') || str_contains($text, 'payment') || str_contains($text, 'invoice') => 'vendor_payments',
            str_contains($text, 'cash') || str_contains($text, 'burn') => 'cash_flow',
            str_contains($text, 'revenue') || str_contains($text, 'deposit') => 'revenue',
            str_contains($text, 'fraud') => 'fraud',
            str_contains($text, 'expense') || str_contains($text, 'spend') => 'expense',
            default => 'unsure',
        };
    }

    private function canonicalFindingSeverity(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high' => 'critical',
            'medium', 'warning' => 'warning',
            default => 'info',
        };
    }

    private function confidenceLabel(mixed $confidence): ?string
    {
        if (is_string($confidence) && in_array(strtolower($confidence), ['low', 'medium', 'high'], true)) {
            return strtolower($confidence);
        }

        $score = $this->confidenceScore($confidence);
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 0.75 => 'high',
            $score >= 0.4 => 'medium',
            default => 'low',
        };
    }

    private function confidenceScore(mixed $confidence): ?float
    {
        return is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $excluded
     * @return array<string, mixed>
     */
    private function safeAgentMetadata(array $metadata, array $excluded): array
    {
        $blocked = array_merge($excluded, [
            'raw',
            'raw_row',
            'raw_value',
            'raw_payload',
            'payload',
            'notice_text',
            'transaction_details',
        ]);
        $safe = [];

        foreach ($metadata as $key => $value) {
            if (in_array((string) $key, $blocked, true)) {
                continue;
            }
            $safe[$key] = is_array($value) ? $this->safeAgentMetadata($value, []) : $value;
        }

        return $safe;
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
