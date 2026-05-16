<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ArAgingService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArAgingController extends Controller
{
    public function __construct(private readonly ArAgingService $arAgingService)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->arAgingService->summary($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch AR aging summary'], 500);
        }
    }

    public function customers(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->arAgingService->customers($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch AR aging customers'], 500);
        }
    }

    public function invoices(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->arAgingService->invoices(
                $companyId,
                $request->integer('limit', 100),
                $request->integer('offset', 0)
            ));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch AR aging invoices'], 500);
        }
    }

    public function writeOffCandidates(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            return response()->json($this->arAgingService->writeOffCandidates($companyId));
        } catch (Exception) {
            return response()->json(['error' => 'Failed to fetch write-off candidates'], 500);
        }
    }

    public function updateInvoice(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $data = $request->validate([
            'collection_notes' => ['nullable', 'string', 'max:2000'],
            'last_contact_date' => ['nullable', 'date'],
        ]);

        $invoice = $this->arAgingService->updateInvoice($companyId, $id, $data);
        if (!$invoice) return response()->json(['error' => 'Invoice not found'], 404);

        return response()->json(['invoice' => $invoice]);
    }

    public function writeOff(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $invoice = $this->arAgingService->writeOff($companyId, $id, $data['reason']);
        if (!$invoice) return response()->json(['error' => 'Invoice not found or already closed'], 404);

        return response()->json(['invoice' => $invoice]);
    }

    private function companyId(Request $request): ?string
    {
        return $request->user()->company_id;
    }
}
