<?php

namespace App\Services;

use App\Enums\ProcessReadiness;
use App\Enums\RexProcess;
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
        $deterministicDecision = $this->deterministicDecision($content);
        if ($deterministicDecision !== null) {
            return $deterministicDecision;
        }

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

    /**
     * Prefer deterministic routing for obvious product data and risk-review requests.
     * This avoids spending an LLM call when local routing is already high confidence.
     *
     * @return array{mode: string, route: string|null, requested_action: string|null, reason: string}|null
     */
    private function deterministicDecision(string $content): ?array
    {
        if ($this->isRiskReviewRequest($content)) {
            return $this->agentDecision(RexProcess::RiskReview, 'keyword_agent_risk_review');
        }

        if ($this->isRecommendationReviewRequest($content)) {
            return $this->agentDecision(RexProcess::RecommendationReview, 'keyword_agent_recommendation_review');
        }

        $intent = $this->orchestrator->inferIntent($content);
        if ($intent) {
            return [
                'mode' => 'orchestrator',
                'route' => $intent,
                'requested_action' => null,
                'reason' => 'keyword_orchestrator_route',
            ];
        }

        return null;
    }

    private function isRiskReviewRequest(string $content): bool
    {
        $text = strtolower($content);

        foreach ([
            'fraud',
            'suspicious',
            'anomaly',
            'anomalies',
            'unusual',
            'duplicate',
            'threshold',
            'split payment',
            'split payments',
            'ghost vendor',
            'shell vendor',
            'shell company',
            'kickback',
            'embezzle',
            'risk review',
            'vendor risk',
            'reconciliation risk',
            'entity relationship',
            'investigate',
        ] as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }

    private function isRecommendationReviewRequest(string $content): bool
    {
        $text = strtolower($content);

        foreach ([
            'pending recommendation',
            'review recommendation',
            'approve recommendation',
            'agent recommendation',
            'pending approval',
        ] as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $decision */
    private function normalizeDecision(array $decision, string $content): array
    {
        $mode = (string) ($decision['mode'] ?? 'direct');

        if (! in_array($mode, ['direct', 'orchestrator', 'agent'], true)) {
            return $this->fallbackDecision($content);
        }

        if ($mode === 'orchestrator') {
            $route = is_string($decision['route'] ?? null) ? $decision['route'] : null;
            if (! $route || ! in_array($route, $this->orchestrator->supportedRoutes(), true)) {
                return $this->fallbackDecision($content);
            }
            return [
                'mode' => 'orchestrator',
                'route' => $route,
                'requested_action' => null,
                'reason' => (string) ($decision['reason'] ?? 'llm_route'),
            ];
        }

        if ($mode === 'agent') {
            $requestedAction = is_string($decision['requested_action'] ?? null)
                ? $decision['requested_action']
                : RexProcess::RiskReview->value;

            $process = RexProcess::resolveOrDefault($requestedAction);

            // An unavailable process routes to direct rather than failing.
            if ($process->readiness() === ProcessReadiness::Unavailable) {
                return [
                    'mode' => 'direct',
                    'route' => null,
                    'requested_action' => null,
                    'reason' => 'process_unavailable_fallback',
                ];
            }

            return $this->agentDecision($process, (string) ($decision['reason'] ?? 'llm_route'));
        }

        return [
            'mode' => 'direct',
            'route' => null,
            'requested_action' => null,
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

    /** @return array{mode: string, route: string, requested_action: string, reason: string} */
    private function agentDecision(RexProcess $process, string $reason): array
    {
        return [
            'mode' => 'agent',
            'route' => $process->value,
            'requested_action' => $process->value,
            'reason' => $reason,
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
        $orchestratorRoutes = implode(', ', $this->orchestrator->supportedRoutes());
        $agentProcesses = implode(', ', array_map(
            fn (RexProcess $p) => $p->value,
            RexProcess::routableByLlm()
        ));

        return <<<PROMPT
You are the routing brain for Rex, Brevix AI's financial-audit chat assistant.

Choose exactly one mode:
- direct: answer with general accounting, audit, product, or workflow guidance without company data.
- orchestrator: run one deterministic Laravel data lookup route. Use this for simple company-data lookups such as dashboard, analytics, open alerts, reconciliation, AR aging, vendors, cases, controls, or transactions.
- agent: kick off a controlled agent workflow. Use this for fraud, suspicious activity, anomaly review, multi-step vendor or transaction risk analysis, or requests that should combine tools and reasoning.

Valid orchestrator routes: {$orchestratorRoutes}

Valid agent processes (use as "requested_action"): {$agentProcesses}

Return only compact JSON:
{"mode":"direct|orchestrator|agent","route":"route_name_or_null","requested_action":"process_key_or_null","reason":"short_reason"}
PROMPT;
    }
}
