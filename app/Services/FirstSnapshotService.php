<?php

namespace App\Services;

use App\Models\OnboardingSession;

class FirstSnapshotService
{
    public function __construct(
        private readonly ActionPlanService $actionPlanService,
        private readonly EvidenceRequirementService $evidenceRequirements,
    ) {}

    /**
     * @param  array<string, mixed>  $requirementsPayload
     * @param  array{summary?: array<string, mixed>, sources?: list<array<string, mixed>>}  $dataSources
     * @return array<string, mixed>
     */
    public function build(
        OnboardingSession $session,
        array $requirementsPayload,
        array $dataSources,
    ): array {
        $requirements = $requirementsPayload['requirements'] ?? [];
        $readiness = $requirementsPayload['readiness'] ?? $this->evidenceRequirements->readiness($requirements);
        $missingRequired = $this->evidenceRequirements->missingEvidence($requirements, includeRecommended: false);
        $missingEvidence = $this->evidenceRequirements->missingEvidence($requirements);
        $actionPlan = $this->actionPlanService->build($session, $requirementsPayload, $dataSources);

        $hasMinimumEvidence = ($readiness['status'] ?? null) === 'ready_for_snapshot';
        $status = $hasMinimumEvidence ? 'ready' : 'not_ready';
        $confidence = $hasMinimumEvidence ? 'medium' : 'low';

        return [
            'contractVersion' => '2026-05-31',
            'status' => $status,
            'readinessScore' => $readiness['score'] ?? 0,
            'reviewScope' => [
                'primaryIntent' => $requirementsPayload['primaryIntent'] ?? EvidenceRequirementService::INTENT_UNSURE,
                'label' => $this->evidenceRequirements->intentLabel($session->primary_intent),
                'scopeMode' => (string) $session->scope_mode,
                'reviewPeriod' => [
                    'start' => $session->review_period_start?->toDateString(),
                    'end' => $session->review_period_end?->toDateString(),
                ],
            ],
            'evidenceUsed' => $dataSources['sources'] ?? [],
            'missingEvidence' => $missingEvidence,
            'riskIndicators' => $hasMinimumEvidence ? $this->getDeterministicRiskIndicators($session) : [],
            'dataQualityIssues' => $this->dataQualityIssues($readiness, $missingRequired),
            'scopeLimitations' => $this->limitations($missingRequired),
            'confidence' => $confidence,
            'recommendedNextAction' => $actionPlan['nextBestAction'],
            'upgradeGates' => [
                [
                    'featureKey' => 'full_snapshot_analysis',
                    'label' => 'Full evidence detail, exports, and continuous monitoring',
                    'minimumTier' => 'starter',
                ],
            ],
            'languageGuardrail' => 'Brevix reports risk indicators and evidence gaps. It does not provide legal, tax, accounting, audit, CPA, or law-enforcement conclusions.',
        ];
    }

    private function getDeterministicRiskIndicators(OnboardingSession $session): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('alerts')) {
            return [];
        }

        $query = \Illuminate\Support\Facades\DB::table('alerts')
            ->where('company_id', $session->company_id)
            ->where('status', 'open');
        if ($session->business_profile_id) {
            $query->where('business_profile_id', $session->business_profile_id);
        }
        
        return $query->limit(5)->get()->map(fn($alert) => [
            'id' => (string) $alert->id,
            'title' => (string) $alert->title,
            'severity' => (string) $alert->severity,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $missingRequired
     * @return list<string>
     */
    private function limitations(array $missingRequired): array
    {
        if ($missingRequired === []) {
            return [];
        }

        return array_map(
            fn (array $requirement): string => 'Missing '.$requirement['label'].' limits confidence because '.$requirement['reason'],
            $missingRequired,
        );
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  list<array<string, mixed>>  $missingRequired
     * @return list<array<string, string>>
     */
    private function dataQualityIssues(array $readiness, array $missingRequired): array
    {
        if (($readiness['status'] ?? null) === 'ready_for_snapshot') {
            return [];
        }

        if ($missingRequired === []) {
            return [[
                'issueKey' => 'evidence_processing',
                'label' => 'Required evidence is still processing',
                'description' => 'Brevix can prepare the workflow, but the snapshot should wait until required sources finish processing.',
            ]];
        }

        return [[
            'issueKey' => 'insufficient_required_evidence',
            'label' => 'Not enough required evidence',
            'description' => 'Brevix can show missing evidence and next steps, but it cannot produce a meaningful first snapshot yet.',
        ]];
    }
}
