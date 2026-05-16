<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GnuCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GnuCashController extends Controller
{
    protected $gnuCashService;

    public function __construct(GnuCashService $gnuCashService)
    {
        $this->gnuCashService = $gnuCashService;
    }

    public function status(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $status = $this->gnuCashService->getStatus($companyId);
        return response()->json($status);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        $companyId = $request->user()->company_id;
        
        try {
            $result = $this->gnuCashService->importFile($companyId, $request->file('file'));
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function purge(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $this->gnuCashService->purgeData($companyId);
        return response()->json(['success' => true]);
    }
}
