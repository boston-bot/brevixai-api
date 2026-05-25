<?php

namespace App\Http\Controllers\Api;

use App\Enums\RexProcess;
use App\Exceptions\BusinessProfileAccessException;
use App\Exceptions\BrevixAgentRunFailed;
use App\Http\Controllers\Controller;
use App\Services\Agents\BrevixAgentRunner;
use App\Services\BusinessProfileContextService;
use App\Services\ChatService;
use App\Services\LlmService;
use App\Services\RexChatRouterService;
use App\Services\RexOrchestratorService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService, ?BusinessProfileContextService $businessProfileContext = null)
    {
        $this->chatService = $chatService;
        $this->businessProfileContext = $businessProfileContext ?: app(BusinessProfileContextService::class);
    }

    private BusinessProfileContextService $businessProfileContext;

    /**
     * POST /api/chat/sessions
     */
    public function store(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        try {
            $session = $this->chatService->createSession($context->companyId, $request->user()->id, $request->input('title'), $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $sessions = $this->chatService->listSessions($context->companyId, $context->businessProfileId);

        return response()->json($sessions);
    }

    /**
     * GET /api/chat/sessions/{sessionId}
     */
    public function show(Request $request, string $sessionId): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $session = $this->chatService->getSession($context->companyId, $sessionId, $context->businessProfileId);
        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->json($session);
    }

    /**
     * DELETE /api/chat/sessions/{sessionId}
     */
    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $success = $this->chatService->deleteSession($context->companyId, $sessionId, $context->businessProfileId);
        if (! $success) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->json(['success' => true, 'id' => $sessionId]);
    }

    /**
     * POST /api/chat/sessions/{sessionId}/messages
     */
    public function sendMessage(Request $request, string $sessionId)
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;
        $companyId = $context->companyId;
        $businessProfileId = $context->businessProfileId;
        $userId = $request->user()->id;

        $content = $request->input('content');
        if (! $content) {
            return response()->json(['error' => 'Content is required'], 400);
        }

        // Verify session ownership
        $session = $this->chatService->getSession($companyId, $sessionId, $businessProfileId);
        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        try {
            $this->chatService->checkAndIncrementQuota($companyId, $businessProfileId);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 429);
        }

        $this->chatService->logUserMessage($companyId, $sessionId, $content, $businessProfileId);

        $llmService = app(LlmService::class);
        $chatRouter = app(RexChatRouterService::class);
        $orchestrator = app(RexOrchestratorService::class);
        $agentRunner = app(BrevixAgentRunner::class);

        // Set up SSE response
        return new StreamedResponse(function () use ($companyId, $businessProfileId, $userId, $sessionId, $content, $llmService, $chatRouter, $orchestrator, $agentRunner) {
            // Turn off output buffering for PHP/Nginx
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', 'off');

            $accumulatedContent = '';

            try {
                $this->emitSse('run.started', ['sessionId' => $sessionId]);

                $session = $this->chatService->getSession($companyId, $sessionId, $businessProfileId);
                $messages = $this->messagesForLlm($session['messages'] ?? []);
                $routeDecision = $chatRouter->route($content, $messages);

                if ($routeDecision['mode'] === 'orchestrator') {
                    $orchestrated = $orchestrator->handleRoute($companyId, (string) $routeDecision['route'], $businessProfileId);
                    if ($orchestrated) {
                        $this->emitOrchestratedResult($companyId, $sessionId, $orchestrated, $routeDecision['reason'], $businessProfileId);

                        return;
                    }
                }

                if ($routeDecision['mode'] === 'agent') {
                    $this->emitAgentResult(
                        $companyId,
                        $userId,
                        $sessionId,
                        $content,
                        $agentRunner,
                        $routeDecision['reason'],
                        (string) ($routeDecision['requested_action'] ?? RexProcess::RiskReview->value),
                        $businessProfileId,
                    );

                    return;
                }

                $this->emitSse('route.selected', ['route' => 'direct', 'reason' => $routeDecision['reason']]);

                $llmService->streamChat($messages, $this->rexSystemPrompt(), function ($chunk) use (&$accumulatedContent) {
                    $accumulatedContent .= $chunk;
                    $this->emitSse('message.delta', ['content' => $chunk]);
                });

                // Finalize and persist assistant response
                $this->chatService->logAssistantMessage($companyId, $sessionId, $accumulatedContent, null, $businessProfileId);

                $this->emitSse('message.completed', ['routedTo' => 'direct', 'artifactCount' => 0, 'actionCount' => 0]);

            } catch (Throwable $e) {
                $this->emitSse('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function emitOrchestratedResult(string $companyId, string $sessionId, array $orchestrated, string $reason, ?string $businessProfileId = null): void
    {
        $this->emitSse('route.selected', ['route' => $orchestrated['route'], 'reason' => $reason]);
        $this->emitSse('tool.started', ['toolName' => $orchestrated['toolName']]);

        foreach ($orchestrated['artifacts'] as $artifact) {
            $this->emitSse('artifact.upsert', $artifact);
        }

        $this->emitSse('tool.completed', [
            'toolName' => $orchestrated['toolName'],
            'success' => true,
            'rowCount' => count($orchestrated['artifacts']),
        ]);

        $content = $orchestrated['message'];
        $this->emitSse('message.delta', ['content' => $content]);

        $structuredPayload = [
            'routedTo' => $orchestrated['route'],
            'artifacts' => $orchestrated['artifacts'],
        ];
        $this->chatService->logAssistantMessage($companyId, $sessionId, $content, $structuredPayload, $businessProfileId);

        $this->emitSse('message.completed', [
            'routedTo' => $orchestrated['route'],
            'artifactCount' => count($orchestrated['artifacts']),
            'actionCount' => 0,
        ]);
    }

    private function emitAgentResult(
        string $companyId,
        string $userId,
        string $sessionId,
        string $content,
        BrevixAgentRunner $agentRunner,
        string $reason,
        string $requestedAction = 'risk_review',
        ?string $businessProfileId = null,
    ): void {
        $requestedAction = RexProcess::resolveOrDefault($requestedAction)->value;
        $route = 'agent.'.$requestedAction;
        $toolName = 'agent.'.$requestedAction;

        $this->emitSse('route.selected', ['route' => $route, 'reason' => $reason]);
        $this->emitSse('tool.started', ['toolName' => $toolName]);

        try {
            $agentResult = $agentRunner->run([
                'company_id' => $companyId,
                'business_profile_id' => $businessProfileId,
                'user_id' => $userId,
                'conversation_id' => $sessionId,
                'message' => $content,
                'requested_action' => $requestedAction,
                'max_response_size' => BrevixAgentRunner::DEFAULT_MAX_RESPONSE_SIZE,
                'page_context' => ['business_profile_id' => $businessProfileId],
            ]);

            $artifact = $this->agentArtifact($agentResult);
            $this->emitSse('artifact.upsert', $artifact);
            $this->emitSse('tool.completed', [
                'toolName' => $toolName,
                'success' => true,
                'rowCount' => count($agentResult['findings'] ?? []),
            ]);

            $message = (string) ($agentResult['message'] ?? '');
            $this->emitSse('message.delta', ['content' => $message]);

            $actions = is_array($agentResult['recommended_actions'] ?? null) ? $agentResult['recommended_actions'] : [];
            $structuredPayload = [
                'routedTo' => $route,
                'agentRunId' => $agentResult['trace_id'] ?? null,
                'intent' => $agentResult['intent'] ?? null,
                'artifacts' => [$artifact],
                'actions' => $actions,
                'requiresReview' => (bool) ($agentResult['requires_review'] ?? false),
                'degradedTools' => $agentResult['degraded_tools'] ?? [],
            ];
            $this->chatService->logAssistantMessage($companyId, $sessionId, $message, $structuredPayload, $businessProfileId);

            $this->emitSse('message.completed', [
                'routedTo' => $route,
                'artifactCount' => 1,
                'actionCount' => count($actions),
                'traceId' => $agentResult['trace_id'] ?? null,
                'degradedToolCount' => count($agentResult['degraded_tools'] ?? []),
            ]);
        } catch (Throwable $e) {
            $traceId = $e instanceof BrevixAgentRunFailed ? $e->agentRunId() : null;
            $message = $this->agentUnavailableMessage();

            $this->emitSse('tool.completed', [
                'toolName' => $toolName,
                'success' => false,
                'rowCount' => 0,
                'traceId' => $traceId,
            ]);
            $this->emitSse('message.delta', ['content' => $message]);

            $this->chatService->logAssistantMessage($companyId, $sessionId, $message, [
                'routedTo' => $route,
                'intent' => 'agent_service_unavailable',
                'agentRunId' => $traceId,
                'artifacts' => [],
                'actions' => [],
                'requiresReview' => false,
            ], $businessProfileId);

            $this->emitSse('message.completed', [
                'routedTo' => $route,
                'artifactCount' => 0,
                'actionCount' => 0,
                'traceId' => $traceId,
                'error' => 'agent_service_unavailable',
            ]);
        }
    }

    /** @param array<string, mixed> $agentResult */
    private function agentArtifact(array $agentResult): array
    {
        $traceId = (string) ($agentResult['trace_id'] ?? uniqid('agent-run-'));

        return [
            'id' => 'agent-findings-'.$traceId,
            'type' => 'agent_findings',
            'title' => 'Risk Review Findings',
            'data' => [
                'intent' => $agentResult['intent'] ?? 'unknown',
                'findings' => $agentResult['findings'] ?? [],
                'recommendedActions' => $agentResult['recommended_actions'] ?? [],
                'requiresReview' => (bool) ($agentResult['requires_review'] ?? false),
                'degradedTools' => $agentResult['degraded_tools'] ?? [],
                'traceId' => $traceId,
            ],
            'sourceRefs' => [],
        ];
    }

    /** @param array<int, mixed> $messages */
    private function messagesForLlm(array $messages): array
    {
        return array_map(function ($msg): array {
            return [
                'role' => in_array($msg['role'] ?? 'user', ['system', 'user', 'assistant'], true) ? $msg['role'] : 'user',
                'content' => (string) ($msg['content'] ?? ''),
            ];
        }, array_slice($messages, -20));
    }

    private function rexSystemPrompt(): string
    {
        return 'You are Rex, Brevix AI\'s financial intelligence orchestration layer. Answer only product, data-source, and risk-workflow questions in direct mode. Route company-data questions to deterministic Brevix services instead of inventing balances, vendors, transactions, alerts, or fraud findings. Do not provide legal, tax, accounting, audit-opinion, CPA, investment, law-enforcement, or attorney-client services. Do not conclude fraud occurred or present outputs as a professional opinion. If a request needs company data, explain which data source or workflow Rex needs next. Keep responses under 200 words unless a detailed explanation is needed.';
    }

    private function agentUnavailableMessage(): string
    {
        return 'I could not complete the risk review right now. No alerts or cases were created. Please try again or review the dashboard manually.';
    }

    private function emitSse(string $type, array $payload): void
    {
        echo 'data: '.json_encode(['type' => $type, 'payload' => $payload])."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * GET /api/chat/sessions/{sessionId}/workspace
     */
    public function workspace(Request $request, string $sessionId): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $workspace = $this->chatService->getWorkspace($context->companyId, $sessionId, $context->businessProfileId);

        return response()->json($workspace);
    }

    /**
     * POST /api/chat/sessions/{sessionId}/actions/{actionId}/confirm
     */
    public function confirmAction(Request $request, string $sessionId, string $actionId): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;
        $companyId = $context->companyId;
        $userId = $request->user()->id;

        try {
            $result = $this->chatService->confirmAction($companyId, $userId, $sessionId, $actionId, $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;
        $companyId = $context->companyId;
        $userId = $request->user()->id;

        try {
            $result = $this->chatService->rejectAction($companyId, $userId, $sessionId, $actionId, $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) return $context;

        $usage = $this->chatService->getUsage($context->companyId, $context->businessProfileId);

        return response()->json($usage);
    }

    private function resolveContext(Request $request): \App\Services\BusinessProfileContext|JsonResponse
    {
        try {
            return $this->businessProfileContext->resolveForRequest($request);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
    }
}
