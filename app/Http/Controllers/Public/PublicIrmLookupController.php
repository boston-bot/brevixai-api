<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\IrmKnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Unauthenticated, rate-limited IRS/IRM lookup for the public-facing
 * trust-building tool. No company data is required or accepted.
 */
class PublicIrmLookupController extends Controller
{
    public function search(Request $request, IrmKnowledgeService $service): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'min:2', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            return response()->json($service->search(
                $validated['topic'],
                (int) ($validated['limit'] ?? 5)
            ));
        } catch (Throwable $e) {
            return $this->safeFailure($request, 'public_irm_search', $e);
        }
    }

    public function section(Request $request, IrmKnowledgeService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        try {
            return response()->json($service->section($validated['reference']));
        } catch (Throwable $e) {
            return $this->safeFailure($request, 'public_irm_section', $e);
        }
    }

    private function safeFailure(Request $request, string $toolName, Throwable $e): JsonResponse
    {
        Log::warning('public_tool.failed', [
            'tool_name' => $toolName,
            'tool_endpoint' => $request->method().' '.$request->path(),
            'error_class' => $e::class,
            'error_code' => $e->getCode() ?: null,
        ]);

        return response()->json([
            'error' => 'This lookup could not complete safely. Please try a different search.',
        ], 500);
    }
}
