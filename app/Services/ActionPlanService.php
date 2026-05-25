<?php

namespace App\Services;

use App\Models\OnboardingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ActionPlanService
{
    public function __construct(private readonly EvidenceRequirementService $evidenceRequirements) {}

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
        $missingEvidence = $this->evidenceRequirements->missingEvidence($requirements);
        $nextBestAction = $this->nextBestAction($session, $readiness, $missingEvidence);

        return [
            'currentObjective' => [
                'intent' => $requirementsPayload['primaryIntent'] ?? EvidenceRequirementService::INTENT_UNSURE,
                'label' => $this->evidenceRequirements->intentLabel($session->primary_intent),
                'reviewPeriod' => [
                    'start' => $session->review_period_start?->toDateString(),
                    'end' => $session->review_period_end?->toDateString(),
                ],
                'scopeMode' => (string) $session->scope_mode,
            ],
            'nextBestAction' => $nextBestAction,
            'evidenceReadiness' => $readiness,
            'missingEvidence' => array_values($missingEvidence),
            'openFindings' => $this->openFindings((string) $session->company_id, $session->business_profile_id ? (string) $session->business_profile_id : null),
            'openQuestions' => $this->openQuestions($missingEvidence),
            'recentSources' => array_slice($dataSources['sources'] ?? [], 0, 5),
            'dataSources' => $dataSources,
            'firstSnapshot' => [
                'status' => ($readiness['status'] ?? null) === 'ready_for_snapshot'
                    ? 'ready'
                    : 'placeholder',
                'endpoint' => '/api/reviews/first-snapshot',
                'method' => 'POST',
            ],
            'rexPrompts' => $this->rexPrompts($session, $readiness, $missingEvidence),
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  list<array<string, mixed>>  $missingEvidence
     * @return array<string, mixed>
     */
    private function nextBestAction(OnboardingSession $session, array $readiness, array $missingEvidence): array
    {
        if (! $session->primary_intent) {
            return [
                'actionKey' => 'choose_primary_intent',
                'label' => 'Choose the review objective',
                'description' => 'Start by telling Brevix why you came here so the evidence checklist can be scoped.',
                'route' => '/onboarding',
                'cta' => 'Continue onboarding',
            ];
        }

        $firstMissingRequired = $this->firstMissing($missingEvidence, 'required');
        if ($firstMissingRequired) {
            return [
                'actionKey' => 'add_required_evidence',
                'label' => 'Add '.$firstMissingRequired['label'],
                'description' => (string) $firstMissingRequired['reason'],
                'route' => $this->routeForRequirement($firstMissingRequired),
                'cta' => $this->ctaForRequirement($firstMissingRequired),
            ];
        }

        if (($readiness['status'] ?? null) === 'ready_for_snapshot') {
            return [
                'actionKey' => 'run_first_snapshot',
                'label' => 'Run the first review snapshot',
                'description' => 'Brevix has the minimum required evidence for this scoped review.',
                'route' => '/action-plan',
                'cta' => 'Run snapshot',
            ];
        }

        $firstMissingRecommended = $this->firstMissing($missingEvidence, 'recommended');
        if ($firstMissingRecommended) {
            return [
                'actionKey' => 'improve_confidence',
                'label' => 'Improve confidence with '.$firstMissingRecommended['label'],
                'description' => (string) $firstMissingRecommended['reason'],
                'route' => $this->routeForRequirement($firstMissingRecommended),
                'cta' => $this->ctaForRequirement($firstMissingRecommended),
            ];
        }

        return [
            'actionKey' => 'review_action_plan',
            'label' => 'Review the action plan',
            'description' => 'Continue from the current readiness state and review open findings or questions.',
            'route' => '/action-plan',
            'cta' => 'Review plan',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    private function firstMissing(array $requirements, string $priority): ?array
    {
        foreach ($requirements as $requirement) {
            if (($requirement['priority'] ?? null) === $priority && ($requirement['status'] ?? null) === 'missing') {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function routeForRequirement(array $requirement): string
    {
        if (in_array('manual_answer', $requirement['acceptedSourceTypes'] ?? [], true)) {
            return '/onboarding';
        }

        return '/evidence';
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function ctaForRequirement(array $requirement): string
    {
        if (in_array('manual_answer', $requirement['acceptedSourceTypes'] ?? [], true)) {
            return 'Answer question';
        }

        return 'Add evidence';
    }

    /**
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function openFindings(string $companyId, ?string $businessProfileId): array
    {
        if (! Schema::hasTable('alerts')) {
            return ['count' => 0, 'items' => []];
        }

        $query = DB::table('alerts')
            ->where('company_id', $companyId)
            ->where('status', 'open');
        if ($businessProfileId && Schema::hasColumn('alerts', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }

        $count = (int) (clone $query)->count();
        $items = $query
            ->orderByDesc(Schema::hasColumn('alerts', 'created_at') ? 'created_at' : 'id')
            ->limit(5)
            ->get()
            ->map(fn (object $alert): array => [
                'id' => (string) ($alert->id ?? ''),
                'title' => (string) ($alert->title ?? 'Open finding'),
                'severity' => (string) ($alert->severity ?? 'info'),
            ])
            ->values()
            ->all();

        return ['count' => $count, 'items' => $items];
    }

    /**
     * @param  list<array<string, mixed>>  $missingEvidence
     * @return list<array<string, mixed>>
     */
    private function openQuestions(array $missingEvidence): array
    {
        $questions = [];

        foreach ($missingEvidence as $requirement) {
            if (! in_array('manual_answer', $requirement['acceptedSourceTypes'] ?? [], true)) {
                continue;
            }

            $questions[] = [
                'questionKey' => $requirement['requirementKey'],
                'label' => $requirement['label'],
                'reason' => $requirement['reason'],
            ];
        }

        return $questions;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  list<array<string, mixed>>  $missingEvidence
     * @return list<string>
     */
    private function rexPrompts(OnboardingSession $session, array $readiness, array $missingEvidence): array
    {
        if (! $session->primary_intent) {
            return [
                'Help me choose the right review objective.',
                'What evidence should I gather first?',
            ];
        }

        if (($readiness['status'] ?? null) !== 'ready_for_snapshot' && $missingEvidence !== []) {
            return [
                'Explain why this evidence is needed.',
                'What can Brevix review with the records I have now?',
                'What is the next best step?',
            ];
        }

        return [
            'Summarize my readiness.',
            'What should I review first?',
            'Explain the current scope limitations.',
        ];
    }
}
