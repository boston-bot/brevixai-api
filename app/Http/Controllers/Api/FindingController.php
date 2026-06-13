<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FindingService;
use App\Services\SourceFindingAdapterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class FindingController extends Controller
{
    public function __construct(
        private readonly FindingService $findings,
        private readonly SourceFindingAdapterService $sourceFindingAdapter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $filters = $request->validate([
            'category' => ['sometimes', 'string', 'max:80'],
            'source_module' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'max:80'],
            'severity' => ['sometimes', 'string', Rule::in(['info', 'warning', 'critical'])],
            'reviewer_status' => ['sometimes', 'string', Rule::in(['pending', 'reviewed', 'dismissed'])],
            'investigation_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (! Schema::hasTable('findings')) {
            return response()->json($this->sourceFindingAdapter->list(
                companyId: $context->companyId,
                filters: $filters,
                businessProfileId: $context->businessProfileId,
            ));
        }

        return response()->json($this->findings->list($context, $filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        if (! Schema::hasTable('findings')) {
            return response()->json(['error' => 'Finding not found'], 404);
        }

        $payload = $this->findings->show($context, $id);
        if (! $payload) {
            return response()->json(['error' => 'Finding not found'], 404);
        }

        return response()->json($payload);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:80'],
            'reviewer_status' => ['sometimes', 'string', Rule::in(['pending', 'reviewed', 'dismissed'])],
            'reviewerStatus' => ['sometimes', 'string', Rule::in(['pending', 'reviewed', 'dismissed'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        if (! Schema::hasTable('findings')) {
            return response()->json(['error' => 'Finding not found'], 404);
        }

        try {
            $finding = $this->findings->review($context, $request->user(), $id, $validated);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }

        if (! $finding) {
            return response()->json(['error' => 'Finding not found'], 404);
        }

        return response()->json(['finding' => $this->findings->payload($finding)]);
    }

    public function createInvestigation(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'category' => ['sometimes', 'string', 'max:80'],
            'priority' => ['sometimes', 'string', 'max:80'],
            'scopeStatement' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scope_statement' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scopeLimitations' => ['sometimes', 'array'],
        ]);

        if (! Schema::hasTable('findings')) {
            return response()->json(['error' => 'Finding not found'], 404);
        }

        try {
            $investigation = $this->findings->createInvestigation($context, $request->user(), $id, $validated);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }

        if (! $investigation) {
            return response()->json(['error' => 'Finding not found'], 404);
        }

        return response()->json([
            'investigation' => $this->findings->investigationPayload($context, $investigation),
        ], 201);
    }

    private function safeStatus(Throwable $e): int
    {
        return in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 500;
    }
}
