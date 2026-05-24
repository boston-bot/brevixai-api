<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class RexChatRouterService
{
    public function __construct(
        private readonly LlmService $llmService,
        private readonly RexOrchestratorService $orchestrator,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{mode: string, route: string|null, requested_action: string|null, reason: string}
     */
    public function route(string $content, array $messages = []): array
    {
        try {
            $decision = $this->llmService->completeJson(
                $this->routerMessages($content, $messages),
                $this->systemPrompt(),
                ['model' => $this->llmService->routerModel()]
            );

            return $this->normalizeDecision($decision, $content);
        } catch (Throwable $e) {
            Log::warning('rex.router.failed', [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            return $this->fallbackDecision($content);
        }
    }

    /** @param array<string, mixed> $decision */
    private function normalizeDecision(array $decision, string $content): array
    {
        $mode = (string) ($decision['mode'] ?? 'direct');
        $route = $decision['route'] ?? null;

        if (! in_array($mode, ['direct', 'orchestrator', 'agent'], true)) {
            return $this->fallbackDecision($content);
        }

        if ($mode === 'orchestrator') {
            $route = is_string($route) ? $route : null;
            if (! $route || ! in_array($route, $this->orchestrator->supportedRoutes(), true)) {
                return $this->fallbackDecision($content);
            }
        }

        if ($mode === 'agent') {
            $route = 'risk_review';
        }

        if ($mode === 'direct') {
            $route = null;
        }

        return [
            'mode' => $mode,
            'route' => $route,
            'requested_action' => $mode === 'agent' ? 'risk_review' : null,
            'reason' => (string) ($decision['reason'] ?? 'llm_route'),
        ];
    }

    /**
     * Preserve the old keyword orchestrator behavior if routing cannot call the LLM.
     *
     * @return array{mode: string, route: string|null, requested_action: string|null, reason: string}
     */
    private function fallbackDecision(string $content): array
    {
        $intent = $this->orchestrator->inferIntent($content);
        if ($intent) {
            return [
                'mode' => 'orchestrator',
                'route' => $intent,
                'requested_action' => null,
                'reason' => 'keyword_fallback',
            ];
        }

        return [
            'mode' => 'direct',
            'route' => null,
            'requested_action' => null,
            'reason' => 'direct_fallback',
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function routerMessages(string $content, array $messages): array
    {
        $history = array_slice($messages, -6);
        $last = end($history);

        if (! is_array($last) || ($last['role'] ?? null) !== 'user' || ($last['content'] ?? null) !== $content) {
            $history[] = [
                'role' => 'user',
                'content' => $content,
            ];
        }

        return $history;
    }

    private function systemPrompt(): string
    {
        $routes = implode(', ', $this->orchestrator->supportedRoutes());

        return <<<PROMPT
You are the routing brain for Rex, Brevix AI's financial-audit chat assistant.

Choose exactly one mode:
- direct: answer with general accounting, audit, product, or workflow guidance without company data.
- orchestrator: run one deterministic Laravel data lookup route. Use this for simple company-data lookups such as dashboard, analytics, open alerts, reconciliation, AR aging, vendors, cases, controls, or transactions.
- agent: kick off the controlled risk-review agent workflow. Use this for fraud, suspicious activity, anomaly review, multi-step vendor or transaction risk analysis, prompt-injection concerns, or requests that should combine tools and reasoning.

Valid orchestrator routes: {$routes}

Return only compact JSON:
{"mode":"direct|orchestrator|agent","route":"route_name_or_null","reason":"short_reason"}
PROMPT;
    }
}
