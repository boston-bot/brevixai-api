<?php

namespace App\Enums;

enum RexProcess: string
{
    case RiskReview             = 'risk_review';
    case TransactionLookup      = 'transaction_lookup';
    case DashboardHealth        = 'dashboard_health';
    case RecommendationReview   = 'recommendation_review';
    case InvestigationSynthesis = 'investigation_synthesis';

    /** Routing mode: 'agent' calls BrevixAgentRunner; 'orchestrator' calls RexOrchestratorService. */
    public function mode(): string
    {
        return match ($this) {
            self::RiskReview, self::RecommendationReview, self::InvestigationSynthesis => 'agent',
            self::TransactionLookup, self::DashboardHealth => 'orchestrator',
        };
    }

    /**
     * Tool keys this process may advertise to the LangGraph agent service.
     * Only meaningful for agent-mode processes.
     *
     * @return list<string>
     */
    public function tools(): array
    {
        return match ($this) {
            self::RiskReview => [
                'company_context',
                'risk_summary',
                'vendor_risk',
                'reconciliation_risk',
                'entity_relationship_risk',
                'aggregate_risk_summary',
                'alert_recommendations',
                'case_recommendations',
            ],
            self::RecommendationReview => [
                'company_context',
                'alert_recommendations',
                'case_recommendations',
            ],
            self::InvestigationSynthesis => [
                'company_context',
                'risk_summary',
                'entity_relationship_risk',
                'alert_recommendations',
            ],
            self::TransactionLookup, self::DashboardHealth => [],
        };
    }

    public function readiness(): ProcessReadiness
    {
        return match ($this) {
            self::RiskReview, self::TransactionLookup, self::DashboardHealth, self::RecommendationReview => ProcessReadiness::Available,
            self::InvestigationSynthesis => ProcessReadiness::Preview,
        };
    }

    /**
     * Action types this process may produce that require user approval before execution.
     *
     * @return list<string>
     */
    public function approvalTypes(): array
    {
        return match ($this) {
            self::RiskReview, self::RecommendationReview, self::InvestigationSynthesis => ['create_alert'],
            self::TransactionLookup, self::DashboardHealth => [],
        };
    }

    /**
     * Return a safe default for an unknown or unavailable requested action.
     * Orchestrator-mode processes fall through to direct; agent-mode falls back to risk_review.
     */
    public static function resolveOrDefault(string $value): self
    {
        try {
            $process = self::from($value);
            if ($process->readiness() === ProcessReadiness::Unavailable) {
                return self::RiskReview;
            }
            return $process;
        } catch (\ValueError) {
            return self::RiskReview;
        }
    }

    /** @return list<self> All processes with Available readiness. */
    public static function available(): array
    {
        return array_filter(self::cases(), fn (self $p) => $p->readiness() === ProcessReadiness::Available);
    }

    /** @return list<self> All agent-mode processes that the LLM router may select. */
    public static function routableByLlm(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $p) => $p->mode() === 'agent' && $p->readiness() === ProcessReadiness::Available
        ));
    }
}
