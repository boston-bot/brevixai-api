<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntityGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityGraphController extends Controller
{
    public function __construct(private EntityGraphService $graphService) {}

    /**
     * GET /api/entity-graph
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $graph = $this->graphService->getGraph($companyId);

        return response()->json($graph);
    }

    /**
     * GET /api/entity-graph/node/{id}
     */
    public function node(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $detail = $this->graphService->getNode($companyId, $id);
        if (! $detail) {
            return response()->json(['error' => 'Node not found'], 404);
        }

        return response()->json($detail);
    }
}
