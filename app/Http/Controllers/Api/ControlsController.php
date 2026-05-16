<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlsService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlsController extends Controller
{
    public function __construct(private readonly ControlsService $controlsService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->controlsService->controls($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch controls'], 500);
        }
    }

    public function health(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->controlsService->health($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch controls health'], 500);
        }
    }

    public function violations(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->controlsService->violations($companyId, $request->only(['resolved', 'limit'])));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch control violations'], 500);
        }
    }

    public function evaluate(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->controlsService->evaluate($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to evaluate controls'], 500);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
        ]);

        $control = $this->controlsService->updateControl($companyId, $id, $data);
        if (!$control) return response()->json(['error' => 'Control not found'], 404);

        return response()->json(['control' => $control]);
    }

    public function updateViolation(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $data = $request->validate([
            'resolved' => ['required', 'boolean'],
        ]);

        $violation = $this->controlsService->updateViolation($companyId, $id, $request->user()->id, $data);
        if (!$violation) return response()->json(['error' => 'Violation not found'], 404);

        return response()->json(['violation' => $violation]);
    }

    private function companyId(Request $request): ?string
    {
        return $request->user()->company_id;
    }
}
