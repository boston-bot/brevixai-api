<?php

namespace Tests\Feature;

use App\Enums\ProcessReadiness;
use App\Enums\RexProcess;
use Tests\TestCase;

class ProcessRegistryTest extends TestCase
{
    public function test_all_cases_have_a_mode(): void
    {
        foreach (RexProcess::cases() as $process) {
            $this->assertContains($process->mode(), ['agent', 'orchestrator'], "{$process->value} must have a valid mode");
        }
    }

    public function test_all_cases_have_a_readiness(): void
    {
        foreach (RexProcess::cases() as $process) {
            $this->assertInstanceOf(ProcessReadiness::class, $process->readiness());
        }
    }

    public function test_risk_review_is_available_agent_mode(): void
    {
        $p = RexProcess::RiskReview;
        $this->assertSame('agent', $p->mode());
        $this->assertSame(ProcessReadiness::Available, $p->readiness());
        $this->assertContains('create_alert', $p->approvalTypes());
    }

    public function test_risk_review_advertises_all_eight_tools(): void
    {
        $tools = RexProcess::RiskReview->tools();
        $this->assertCount(8, $tools);
        foreach (['company_context', 'risk_summary', 'vendor_risk', 'reconciliation_risk',
                  'entity_relationship_risk', 'aggregate_risk_summary',
                  'alert_recommendations', 'case_recommendations'] as $key) {
            $this->assertContains($key, $tools);
        }
    }

    public function test_transaction_lookup_is_available_orchestrator_mode(): void
    {
        $p = RexProcess::TransactionLookup;
        $this->assertSame('orchestrator', $p->mode());
        $this->assertSame(ProcessReadiness::Available, $p->readiness());
        $this->assertEmpty($p->tools());
        $this->assertEmpty($p->approvalTypes());
    }

    public function test_dashboard_health_is_available_orchestrator_mode(): void
    {
        $p = RexProcess::DashboardHealth;
        $this->assertSame('orchestrator', $p->mode());
        $this->assertSame(ProcessReadiness::Available, $p->readiness());
        $this->assertEmpty($p->tools());
        $this->assertEmpty($p->approvalTypes());
    }

    public function test_specialized_risk_process_contracts_are_registered(): void
    {
        foreach ([
            RexProcess::ControlsReview,
            RexProcess::ReconciliationReview,
            RexProcess::EntityGraphReview,
            RexProcess::CaseManagement,
        ] as $process) {
            $this->assertSame('orchestrator', $process->mode());
            $this->assertSame(ProcessReadiness::Available, $process->readiness());
            $this->assertEmpty($process->tools());
            $this->assertEmpty($process->approvalTypes());
        }
    }

    public function test_reporting_process_is_preview_orchestrator_mode(): void
    {
        $this->assertSame('orchestrator', RexProcess::Reporting->mode());
        $this->assertSame(ProcessReadiness::Preview, RexProcess::Reporting->readiness());
        $this->assertEmpty(RexProcess::Reporting->approvalTypes());
    }

    public function test_recommendation_review_is_available_agent_mode(): void
    {
        $p = RexProcess::RecommendationReview;
        $this->assertSame('agent', $p->mode());
        $this->assertSame(ProcessReadiness::Available, $p->readiness());
        $this->assertContains('create_alert', $p->approvalTypes());
    }

    public function test_recommendation_review_tools_are_a_subset_of_risk_review_tools(): void
    {
        $reviewTools = RexProcess::RecommendationReview->tools();
        $riskTools = RexProcess::RiskReview->tools();
        foreach ($reviewTools as $tool) {
            $this->assertContains($tool, $riskTools, "Tool {$tool} must also exist in risk_review");
        }
    }

    public function test_investigation_synthesis_is_available(): void
    {
        $this->assertSame(ProcessReadiness::Available, RexProcess::InvestigationSynthesis->readiness());
        $this->assertSame('agent', RexProcess::InvestigationSynthesis->mode());
    }

    public function test_resolve_or_default_returns_process_for_valid_key(): void
    {
        $this->assertSame(RexProcess::RiskReview, RexProcess::resolveOrDefault('risk_review'));
        $this->assertSame(RexProcess::RecommendationReview, RexProcess::resolveOrDefault('recommendation_review'));
    }

    public function test_resolve_or_default_falls_back_for_unknown_key(): void
    {
        $this->assertSame(RexProcess::RiskReview, RexProcess::resolveOrDefault('unknown_process'));
    }

    public function test_resolve_or_default_falls_back_for_unavailable_process(): void
    {
        // Mark investigation_synthesis as preview — resolveOrDefault only falls back for Unavailable
        // Preview processes still resolve to themselves.
        $result = RexProcess::resolveOrDefault('investigation_synthesis');
        $this->assertSame(RexProcess::InvestigationSynthesis, $result);
    }

    public function test_available_returns_only_available_processes(): void
    {
        $available = RexProcess::available();
        foreach ($available as $p) {
            $this->assertSame(ProcessReadiness::Available, $p->readiness());
        }
        // InvestigationSynthesis is now Available (promoted from Preview)
        $this->assertContains(RexProcess::InvestigationSynthesis, $available);
        $this->assertNotContains(RexProcess::Reporting, $available);
    }

    public function test_routable_by_llm_returns_only_available_agent_processes(): void
    {
        $routable = RexProcess::routableByLlm();
        foreach ($routable as $p) {
            $this->assertSame('agent', $p->mode());
            $this->assertSame(ProcessReadiness::Available, $p->readiness());
        }
        $this->assertContains(RexProcess::RiskReview, $routable);
        $this->assertContains(RexProcess::RecommendationReview, $routable);
        // InvestigationSynthesis is now Available and agent-mode — it is routable
        $this->assertContains(RexProcess::InvestigationSynthesis, $routable);
        $this->assertNotContains(RexProcess::TransactionLookup, $routable);
    }
}
