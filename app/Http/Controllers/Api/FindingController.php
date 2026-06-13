<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SourceFindingAdapterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FindingController extends Controller
{
    public function __construct(
        private readonly SourceFindingAdapterService $sourceFindingAdapter,
    ) {}

    /**
     * GET /api/findings
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'source_module' => ['sometimes', 'string', Rule::in(SourceFindingAdapterService::SOURCE_MODULES)],
            'category' => ['sometimes', 'string', Rule::in([
                'revenue',
                'expense',
                'payroll',
                'tax',
                'fraud',
                'reconciliation',
                'controls',
                'vendor_payments',
                'cash_flow',
                'unsure',
            ])],
            'status' => ['sometimes', 'string', Rule::in([
                'new',
                'in_review',
                'needs_more_evidence',
                'reviewed',
                'dismissed',
                'escalated',
                'included_in_package',
            ])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($this->sourceFindingAdapter->list(
            companyId: $context->companyId,
            filters: $validated,
            businessProfileId: $context->businessProfileId,
        ));
    }
}
