<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TaxpayerTransparencyService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxpayerTransparencyController extends Controller
{
    public function __construct(
        private readonly TaxpayerTransparencyService $transparencyService,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $visibility = $this->transparencyService->visibility(
            $context->companyId,
            $id,
            $context->businessProfileId,
        );

        if (! $visibility) {
            return response()->json(['error' => 'Case not found'], 404);
        }

        return response()->json($visibility);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in($this->transparencyService->categories())],
            'status_key' => ['nullable', 'string', 'max:100'],
            'tax_period' => ['nullable', 'string', 'max:40'],
            'label' => ['required', 'string', 'max:180'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'source_type' => ['nullable', 'string', Rule::in($this->transparencyService->sourceTypes())],
            'source_label' => ['nullable', 'string', 'max:180'],
            'source_reference' => ['nullable', 'string', 'max:500'],
            'source_date' => ['nullable', 'date'],
            'captured_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $item = $this->transparencyService->createItem(
                $context->companyId,
                (string) $request->user()->id,
                $id,
                $validated,
                $context->businessProfileId,
            );

            return response()->json([
                'item' => $this->transparencyService->formatItem($item),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $this->safeStatus($e));
        }
    }

    private function safeStatus(Exception $e): int
    {
        return in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 500;
    }
}
