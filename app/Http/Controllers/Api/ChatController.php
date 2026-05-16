<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatService;
use App\Services\LlmService;
use App\Services\RexOrchestratorService;
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

        $llmService = app(LlmService::class);
        $orchestrator = app(RexOrchestratorService::class);

        // Set up SSE response
        return new StreamedResponse(function () use ($companyId, $userId, $sessionId, $content, $llmService, $orchestrator) {
            // Turn off output buffering for PHP/Nginx
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', 'off');

            $accumulatedContent = '';

            try {
                echo "data: " . json_encode(['type' => 'run.started', 'payload' => ['sessionId' => $sessionId]]) . "\n\n";
                ob_flush();
                flush();

                $orchestrated = $orchestrator->handle($companyId, $content);
                if ($orchestrated) {
                    echo "data: " . json_encode(['type' => 'route.selected', 'payload' => ['route' => $orchestrated['route']]]) . "\n\n";
                    ob_flush();
                    flush();

                    echo "data: " . json_encode(['type' => 'tool.started', 'payload' => ['toolName' => $orchestrated['toolName']]]) . "\n\n";
                    ob_flush();
                    flush();

                    foreach ($orchestrated['artifacts'] as $artifact) {
                        echo "data: " . json_encode(['type' => 'artifact.upsert', 'payload' => $artifact]) . "\n\n";
                        ob_flush();
                        flush();
                    }

                    echo "data: " . json_encode(['type' => 'tool.completed', 'payload' => ['toolName' => $orchestrated['toolName'], 'success' => true, 'rowCount' => count($orchestrated['artifacts'])]]) . "\n\n";
                    ob_flush();
                    flush();

                    $accumulatedContent = $orchestrated['message'];
                    echo "data: " . json_encode(['type' => 'message.delta', 'payload' => ['content' => $accumulatedContent]]) . "\n\n";
                    ob_flush();
                    flush();

                    $structuredPayload = [
                        'routedTo' => $orchestrated['route'],
                        'artifacts' => $orchestrated['artifacts'],
                    ];
                    $this->chatService->logAssistantMessage($companyId, $sessionId, $accumulatedContent, $structuredPayload);

                    echo "data: " . json_encode(['type' => 'message.completed', 'payload' => ['routedTo' => $orchestrated['route'], 'artifactCount' => count($orchestrated['artifacts']), 'actionCount' => 0]]) . "\n\n";
                    ob_flush();
                    flush();
                    return;
                }

                echo "data: " . json_encode(['type' => 'route.selected', 'payload' => ['route' => 'direct']]) . "\n\n";
                ob_flush();
                flush();

                // Fetch full session history so LLM has context
                $session = $this->chatService->getSession($companyId, $sessionId);
                $messages = array_map(function($msg) {
                    return [
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    ];
                }, $session['messages'] ?? []);

                $systemPrompt = "You are Rex, an expert AI financial auditor built into Brevix AI. You are confident, direct, and concise — like a senior partner at a forensic accounting firm. Answer the question clearly and succinctly. If the user is asking about something that requires their company data, explain that you'll need to look it up and suggest they rephrase as a specific investigation question. Keep responses under 200 words unless a detailed explanation is needed.";

                $llmService->streamChat($messages, $systemPrompt, function($chunk) use (&$accumulatedContent) {
                    $accumulatedContent .= $chunk;
                    echo "data: " . json_encode(['type' => 'message.delta', 'payload' => ['content' => $chunk]]) . "\n\n";
                    ob_flush();
                    flush();
                });

                // Finalize and persist assistant response
                $this->chatService->logAssistantMessage($companyId, $sessionId, $accumulatedContent, null);

                echo "data: " . json_encode(['type' => 'message.completed', 'payload' => ['routedTo' => 'direct', 'artifactCount' => 0, 'actionCount' => 0]]) . "\n\n";
                ob_flush();
                flush();

            } catch (\Exception $e) {
                echo "data: " . json_encode(['type' => 'error', 'payload' => ['message' => $e->getMessage()]]) . "\n\n";
                ob_flush();
                flush();
            }
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
