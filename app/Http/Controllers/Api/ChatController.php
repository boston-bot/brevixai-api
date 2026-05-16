<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * POST /api/chat/sessions
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $session = $this->chatService->createSession($companyId, $request->user()->id, $request->input('title'));
            return response()->json($session, 201);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to create session'], 500);
        }
    }

    /**
     * GET /api/chat/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $sessions = $this->chatService->listSessions($companyId);
        return response()->json($sessions);
    }

    /**
     * GET /api/chat/sessions/{sessionId}
     */
    public function show(Request $request, string $sessionId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $session = $this->chatService->getSession($companyId, $sessionId);
        if (!$session) return response()->json(['error' => 'Session not found'], 404);

        return response()->json($session);
    }

    /**
     * DELETE /api/chat/sessions/{sessionId}
     */
    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $success = $this->chatService->deleteSession($companyId, $sessionId);
        if (!$success) return response()->json(['error' => 'Session not found'], 404);

        return response()->json(['success' => true, 'id' => $sessionId]);
    }

    /**
     * POST /api/chat/sessions/{sessionId}/messages
     */
    public function sendMessage(Request $request, string $sessionId)
    {
        $companyId = $request->user()->company_id;
        $userId = $request->user()->id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $content = $request->input('content');
        if (!$content) return response()->json(['error' => 'Content is required'], 400);

        // Verify session ownership
        $session = $this->chatService->getSession($companyId, $sessionId);
        if (!$session) return response()->json(['error' => 'Session not found'], 404);

        try {
            $this->chatService->checkAndIncrementQuota($companyId);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 429);
        }

        $this->chatService->logUserMessage($companyId, $sessionId, $content);

        // Set up SSE response
        return new StreamedResponse(function () use ($companyId, $userId, $sessionId, $content) {
            echo "data: " . json_encode(['type' => 'status', 'payload' => 'Processing...']) . "\n\n";
            ob_flush();
            flush();

            // In a real implementation, this would call the Python Orchestrator API via HTTP stream.
            // For now, we will simulate the Rex response directly.
            sleep(1);
            $fakeResponse = "I have reviewed the information. How else can I assist you?";
            echo "data: " . json_encode(['type' => 'message_delta', 'payload' => $fakeResponse]) . "\n\n";
            ob_flush();
            flush();

            $this->chatService->logAssistantMessage($companyId, $sessionId, $fakeResponse, null);

        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * GET /api/chat/sessions/{sessionId}/workspace
     */
    public function workspace(Request $request, string $sessionId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $workspace = $this->chatService->getWorkspace($companyId, $sessionId);
        return response()->json($workspace);
    }

    /**
     * POST /api/chat/sessions/{sessionId}/actions/{actionId}/confirm
     */
    public function confirmAction(Request $request, string $sessionId, string $actionId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $userId = $request->user()->id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $result = $this->chatService->confirmAction($companyId, $userId, $sessionId, $actionId);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * POST /api/chat/sessions/{sessionId}/actions/{actionId}/reject
     */
    public function rejectAction(Request $request, string $sessionId, string $actionId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $userId = $request->user()->id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        try {
            $result = $this->chatService->rejectAction($companyId, $userId, $sessionId, $actionId);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * GET /api/chat/usage
     */
    public function usage(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) return response()->json(['error' => 'No company associated with account'], 403);

        $usage = $this->chatService->getUsage($companyId);
        return response()->json($usage);
    }
}
