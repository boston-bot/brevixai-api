<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\IrmKnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class IrmKnowledgeController extends Controller
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
            return $this->safeFailure($request, 'irm_search', $e);
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
            return $this->safeFailure($request, 'irm_section', $e);
        }
    }

    public function noticeType(Request $request, IrmKnowledgeService $service): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            return response()->json($service->explainNoticeType(
                $validated['code'],
                (int) ($validated['limit'] ?? 5)
            ));
        } catch (Throwable $e) {
            return $this->safeFailure($request, 'irs_notice_type', $e);
        }
    }

    public function recordsChecklist(Request $request, IrmKnowledgeService $service): JsonResponse
    {
        $validated = $request->validate([
            'issue_type' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            return response()->json($service->recommendRecordsToGather(
                $validated['issue_type'],
                (int) ($validated['limit'] ?? 5)
            ));
        } catch (Throwable $e) {
            return $this->safeFailure($request, 'irs_records_checklist', $e);
        }
    }

    public function collectionRisk(Request $request, IrmKnowledgeService $service): JsonResponse
    {
        $validated = $request->validate([
            'issue_type' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            return response()->json($service->summarizeCollectionRisk(
                $validated['issue_type'],
                (int) ($validated['limit'] ?? 5)
            ));
        } catch (Throwable $e) {
            return $this->safeFailure($request, 'irs_collection_risk', $e);
        }
    }

    private function safeFailure(Request $request, string $toolName, Throwable $e): JsonResponse
    {
        Log::warning('agent_tool.failed', [
            'tool_name' => $toolName,
            'tool_endpoint' => $request->method().' '.$request->path(),
            'agent_request_id' => $request->header('X-Brevix-Agent-Request-Id'),
            'error_class' => $e::class,
            'error_code' => $e->getCode() ?: null,
        ]);

        return response()->json([
            'error' => 'Agent tool could not complete the request safely',
        ], 500);
    }
}
