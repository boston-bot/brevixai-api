<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PersonalFinanceBudgetProfile;
use App\Models\PersonalFinanceRule;
use App\Models\PersonalFinanceTransaction;
use App\Services\PersonalFinance\PersonalFinanceAnalyticsService;
use App\Services\PersonalFinance\PersonalFinanceCategorizationService;
use App\Services\PersonalFinance\PersonalFinanceExportService;
use App\Services\PersonalFinance\PersonalFinanceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class PersonalFinanceController extends Controller
{
    public function __construct(
        private readonly PersonalFinanceImportService $importService,
        private readonly PersonalFinanceAnalyticsService $analyticsService,
        private readonly PersonalFinanceExportService $exportService,
        private readonly PersonalFinanceCategorizationService $categorizationService,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->importService->status($this->companyId($request)));
    }

    public function runImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
            'reclassify' => ['sometimes', 'boolean'],
        ]);

        try {
            return response()->json($this->importService->run(
                companyId: $this->companyId($request),
                userId: $request->user()->id,
                force: (bool) ($validated['force'] ?? false),
                reclassify: (bool) ($validated['reclassify'] ?? false),
            ));
        } catch (Throwable $e) {
            return response()->json(['error' => 'Personal finance import failed', 'detail' => $e->getMessage()], 500);
        }
    }

    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules() + [
            'category' => ['sometimes', 'string', 'max:80'],
            'person' => ['sometimes', 'string', 'max:32'],
            'merchant' => ['sometimes', 'string', 'max:120'],
            'direction' => ['sometimes', Rule::in([
                PersonalFinanceTransaction::DIRECTION_INFLOW,
                PersonalFinanceTransaction::DIRECTION_OUTFLOW,
            ])],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = $this->analyticsService
            ->transactionQuery($this->companyId($request), $validated)
            ->orderByDesc('posted_date')
            ->paginate((int) ($validated['perPage'] ?? 50));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (PersonalFinanceTransaction $transaction): array => $this->serializeTransaction($transaction))->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function updateTransaction(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'max:80'],
            'personScope' => ['sometimes', 'string', Rule::in([
                PersonalFinanceTransaction::PERSON_A,
                PersonalFinanceTransaction::PERSON_B,
                PersonalFinanceTransaction::PERSON_SHARED,
                PersonalFinanceTransaction::PERSON_EXCLUDED,
                PersonalFinanceTransaction::PERSON_UNKNOWN,
            ])],
            'normalizedMerchant' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        $transaction = PersonalFinanceTransaction::where('company_id', $this->companyId($request))->findOrFail($id);
        $category = $validated['category'] ?? $transaction->category;
        $personScope = $validated['personScope'] ?? $transaction->person_scope;
        $merchant = $validated['normalizedMerchant'] ?? $transaction->normalized_merchant;

        $transaction->update([
            'category' => $category,
            'person_scope' => $personScope,
            'normalized_merchant' => $merchant,
            'recurring_key' => $transaction->direction === PersonalFinanceTransaction::DIRECTION_OUTFLOW
                && $personScope !== PersonalFinanceTransaction::PERSON_EXCLUDED
                    ? strtolower($category.'|'.$merchant)
                    : null,
            'confidence' => 100,
        ]);

        return response()->json($this->serializeTransaction($transaction->refresh()));
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules());

        return response()->json($this->analyticsService->summary($this->companyId($request), $validated));
    }

    public function catchUp(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules() + [
            'targetAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'months' => ['required', 'integer', Rule::in([3, 6, 12])],
        ]);

        return response()->json($this->analyticsService->catchUpScenario(
            companyId: $this->companyId($request),
            targetAmount: isset($validated['targetAmount']) ? (float) $validated['targetAmount'] : null,
            months: (int) $validated['months'],
            filters: $validated,
        ));
    }

    public function rules(Request $request): JsonResponse
    {
        $this->categorizationService->ensureDefaultRules($this->companyId($request));

        $rules = PersonalFinanceRule::where('company_id', $this->companyId($request))
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PersonalFinanceRule $rule): array => $this->serializeRule($rule))
            ->groupBy('ruleType');

        return response()->json(['rules' => $rules]);
    }

    public function updateRules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.id' => ['sometimes', 'nullable', 'uuid'],
            'rules.*.ruleType' => ['required', Rule::in([
                PersonalFinanceRule::TYPE_CATEGORY,
                PersonalFinanceRule::TYPE_INCOME_SOURCE,
                PersonalFinanceRule::TYPE_MERCHANT,
                PersonalFinanceRule::TYPE_PERSON,
                PersonalFinanceRule::TYPE_EXCLUSION,
            ])],
            'rules.*.name' => ['required', 'string', 'max:160'],
            'rules.*.matchField' => ['sometimes', 'string', Rule::in(['description', 'merchant', 'normalized_merchant', 'category'])],
            'rules.*.pattern' => ['required', 'string', 'max:1000'],
            'rules.*.targetValue' => ['sometimes', 'nullable', 'string', 'max:160'],
            'rules.*.priority' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'rules.*.isActive' => ['sometimes', 'boolean'],
            'rules.*.metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $companyId = $this->companyId($request);
        $rules = DB::transaction(function () use ($companyId, $validated) {
            $keptIds = [];

            foreach ($validated['rules'] as $ruleData) {
                $rule = ! empty($ruleData['id'])
                    ? PersonalFinanceRule::where('company_id', $companyId)->find($ruleData['id'])
                    : null;
                $rule ??= new PersonalFinanceRule(['company_id' => $companyId]);

                $rule->fill([
                    'rule_type' => $ruleData['ruleType'],
                    'name' => $ruleData['name'],
                    'match_field' => $ruleData['matchField'] ?? 'description',
                    'pattern' => $ruleData['pattern'],
                    'target_value' => $ruleData['targetValue'] ?? null,
                    'priority' => $ruleData['priority'] ?? 100,
                    'is_active' => $ruleData['isActive'] ?? true,
                    'metadata' => $ruleData['metadata'] ?? [],
                ]);
                $rule->save();
                $keptIds[] = $rule->id;
            }

            PersonalFinanceRule::where('company_id', $companyId)
                ->whereNotIn('id', $keptIds)
                ->delete();

            return PersonalFinanceRule::where('company_id', $companyId)
                ->orderBy('priority')
                ->get()
                ->map(fn (PersonalFinanceRule $rule): array => $this->serializeRule($rule))
                ->all();
        });

        return response()->json(['rules' => $rules]);
    }

    public function budgets(Request $request): JsonResponse
    {
        return response()->json(['budgetProfile' => $this->serializeBudgetProfile($this->budgetProfile($this->companyId($request)))]);
    }

    public function updateBudgets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'personALabel' => ['sometimes', 'string', 'max:120'],
            'personBLabel' => ['sometimes', 'string', 'max:120'],
            'personAMonthlyAllowance' => ['sometimes', 'numeric', 'min:0'],
            'personBMonthlyAllowance' => ['sometimes', 'numeric', 'min:0'],
            'sharedMonthlyCap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'opaqueCardPaymentCap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'catchUpTargetAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'categoryCaps' => ['sometimes', 'array'],
        ]);

        $profile = $this->budgetProfile($this->companyId($request));
        $profile->update([
            'name' => $validated['name'] ?? $profile->name,
            'person_a_label' => $validated['personALabel'] ?? $profile->person_a_label,
            'person_b_label' => $validated['personBLabel'] ?? $profile->person_b_label,
            'person_a_monthly_allowance' => $validated['personAMonthlyAllowance'] ?? $profile->person_a_monthly_allowance,
            'person_b_monthly_allowance' => $validated['personBMonthlyAllowance'] ?? $profile->person_b_monthly_allowance,
            'shared_monthly_cap' => array_key_exists('sharedMonthlyCap', $validated) ? $validated['sharedMonthlyCap'] : $profile->shared_monthly_cap,
            'opaque_card_payment_cap' => array_key_exists('opaqueCardPaymentCap', $validated) ? $validated['opaqueCardPaymentCap'] : $profile->opaque_card_payment_cap,
            'catch_up_target_amount' => array_key_exists('catchUpTargetAmount', $validated) ? $validated['catchUpTargetAmount'] : $profile->catch_up_target_amount,
            'category_caps' => $validated['categoryCaps'] ?? $profile->category_caps,
        ]);

        return response()->json(['budgetProfile' => $this->serializeBudgetProfile($profile->refresh())]);
    }

    public function export(Request $request): Response|JsonResponse
    {
        $validated = $request->validate($this->filterRules() + [
            'format' => ['required', Rule::in(['pdf', 'docx'])],
            'includeTransactions' => ['sometimes', 'boolean'],
        ]);

        try {
            $export = $this->exportService->generate(
                companyId: $this->companyId($request),
                userId: $request->user()->id,
                format: $validated['format'],
                filters: $validated,
                includeTransactions: (bool) ($validated['includeTransactions'] ?? false),
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'Personal finance export failed', 'detail' => $e->getMessage()], 500);
        }

        return response($export['bytes'], 200, [
            'Content-Type' => $export['contentType'],
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function filterRules(): array
    {
        return [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }

    private function companyId(Request $request): string
    {
        $companyId = $request->user()?->company_id;
        abort_if(! $companyId, 403, 'No company associated with account');

        return $companyId;
    }

    private function budgetProfile(string $companyId): PersonalFinanceBudgetProfile
    {
        return PersonalFinanceBudgetProfile::firstOrCreate(
            ['company_id' => $companyId],
            [
                'name' => 'Default',
                'person_a_label' => 'Person A',
                'person_b_label' => 'Person B',
                'person_a_monthly_allowance' => 0,
                'person_b_monthly_allowance' => 0,
                'category_caps' => [],
                'metadata' => [],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(PersonalFinanceTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'postedDate' => $transaction->posted_date->toDateString(),
            'description' => $transaction->description,
            'normalizedMerchant' => $transaction->normalized_merchant,
            'amount' => (float) $transaction->amount,
            'direction' => $transaction->direction,
            'category' => $transaction->category,
            'personScope' => $transaction->person_scope,
            'recurringKey' => $transaction->recurring_key,
            'sourceSection' => $transaction->source_section,
            'confidence' => $transaction->confidence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRule(PersonalFinanceRule $rule): array
    {
        return [
            'id' => $rule->id,
            'ruleType' => $rule->rule_type,
            'name' => $rule->name,
            'matchField' => $rule->match_field,
            'pattern' => $rule->pattern,
            'targetValue' => $rule->target_value,
            'priority' => $rule->priority,
            'isActive' => $rule->is_active,
            'metadata' => $rule->metadata ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBudgetProfile(PersonalFinanceBudgetProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'personALabel' => $profile->person_a_label,
            'personBLabel' => $profile->person_b_label,
            'personAMonthlyAllowance' => (float) $profile->person_a_monthly_allowance,
            'personBMonthlyAllowance' => (float) $profile->person_b_monthly_allowance,
            'sharedMonthlyCap' => $profile->shared_monthly_cap !== null ? (float) $profile->shared_monthly_cap : null,
            'opaqueCardPaymentCap' => $profile->opaque_card_payment_cap !== null ? (float) $profile->opaque_card_payment_cap : null,
            'catchUpTargetAmount' => $profile->catch_up_target_amount !== null ? (float) $profile->catch_up_target_amount : null,
            'categoryCaps' => $profile->category_caps ?? [],
        ];
    }
}
