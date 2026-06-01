<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessProfileAccessException;
use App\Services\BusinessProfileContext;
use App\Services\BusinessProfileContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function resolveBusinessProfileContext(Request $request, ?string $companyId = null): BusinessProfileContext|JsonResponse
    {
        try {
            return app(BusinessProfileContextService::class)->resolveForRequest($request, $companyId);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
    }
}
