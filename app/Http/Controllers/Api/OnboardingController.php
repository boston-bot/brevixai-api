<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessProfileAccessException;
use App\Http\Controllers\Controller;
use App\Models\OnboardingSession;
use App\Services\BusinessProfileContext;
use App\Services\BusinessProfileContextService;
use App\Services\DataSourceRegistryService;
use App\Services\EvidenceRequirementService;
use App\Services\OnboardingSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly BusinessProfileContextService $businessProfileContext,
        private readonly OnboardingSessionService $sessions,
        private readonly DataSourceRegistryService $dataSources,
        private readonly EvidenceRequirementService $evidenceRequirements,
    ) {}

    public function showSession(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $session = $this->sessions->getOrCreate($context, $request->user());
        $requirementsPayload = $this->evidenceRequirements->requirementsForSession(
            $session,
            $this->dataSources->forContext($context->companyId, $context->businessProfileId),
        );

        return response()->json($this->sessionPayload($session, $requirementsPayload, $context));
    }

    public function updateSession(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $request->validate([
            'primaryIntent' => ['sometimes', 'nullable', 'string', 'max:100'],
            'currentStep' => ['sometimes', 'string', Rule::in($this->sessions->allowedSteps())],
            'status' => ['sometimes', 'string', Rule::in($this->sessions->allowedStatuses())],
            'scopeMode' => ['sometimes', 'string', Rule::in($this->sessions->allowedScopeModes())],
            'scopeAcknowledged' => ['sometimes', 'boolean'],
            'reviewPeriodStart' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reviewPeriodEnd' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reviewPeriod' => ['sometimes', 'array'],
            'reviewPeriod.start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reviewPeriod.end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'businessContext' => ['sometimes', 'array'],
            'businessContext.organizationType' => ['sometimes', 'nullable', 'string', 'max:100'],
            'businessContext.industryOrActivity' => ['sometimes', 'nullable', 'string', 'max:255'],
            'businessContext.entityType' => ['sometimes', 'nullable', 'string', 'max:100'],
            'businessContext.fiscalYearStart' => ['sometimes', 'nullable', 'date_format:m-d'],
            'businessContext.approximateAnnualSpend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'businessContext.accountingSystem' => ['sometimes', 'nullable', 'string', 'max:100'],
            'businessContext.bankAccountCount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'businessContext.authorizedSignerCount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'businessContext.bookAccessCount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'businessContext.checksUsed' => ['sometimes', 'nullable', 'boolean'],
            'businessContext.employeesChanged' => ['sometimes', 'nullable', 'boolean'],
            'businessContext.contractorsChanged' => ['sometimes', 'nullable', 'boolean'],
            'businessContext.vendorsChanged' => ['sometimes', 'nullable', 'boolean'],
            'businessContext.volunteersChanged' => ['sometimes', 'nullable', 'boolean'],
            'businessContext.statedConcernSummary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'businessContext.priorActionsTaken' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'businessContext.noticeType' => ['sometimes', 'nullable', 'string', 'max:255'],
            'businessContext.noticeDeadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'businessContext.taxPeriod' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $session = $this->sessions->getOrCreate($context, $request->user());
        $session = $this->sessions->update($session, $payload, $request->user());
        $requirementsPayload = $this->evidenceRequirements->requirementsForSession(
            $session,
            $this->dataSources->forContext($context->companyId, $context->businessProfileId),
        );

        return response()->json($this->sessionPayload($session, $requirementsPayload, $context));
    }

    public function evidenceRequirements(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $session = $this->sessions->getOrCreate($context, $request->user());
        $dataSources = $this->dataSources->forContext($context->companyId, $context->businessProfileId);
        $requirementsPayload = $this->evidenceRequirements->requirementsForSession($session, $dataSources);

        return response()->json(array_merge($requirementsPayload, [
            'dataSources' => $dataSources,
        ]));
    }

    public function storeAnswer(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'answerKey' => ['sometimes', 'required_without:answers', 'string'],
            'answerValue' => ['sometimes', 'required_with:answerKey'],
            'step' => ['sometimes', 'string', 'max:100'],
            'answers' => ['sometimes', 'array'],
            'answers.primaryIntent' => ['sometimes', 'nullable', 'string', 'max:100'],
            'answers.concernSummary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'answers.businessContext' => ['sometimes', 'array'],
        ]);

        $patch = [];
        if (array_key_exists('answerKey', $payload)) {
            $patch['businessContext'] = [
                $payload['answerKey'] => $payload['answerValue'] ?? null,
            ];
        }

        $answers = $payload['answers'] ?? [];
        if (is_array($answers)) {
            if (array_key_exists('primaryIntent', $answers)) {
                $patch['primaryIntent'] = $answers['primaryIntent'];
            }

            $businessContext = [];
            if (isset($answers['businessContext']) && is_array($answers['businessContext'])) {
                $businessContext = $answers['businessContext'];
            }
            if (array_key_exists('concernSummary', $answers)) {
                $businessContext['statedConcernSummary'] = $answers['concernSummary'];
            }
            if ($businessContext !== []) {
                $patch['businessContext'] = array_merge($patch['businessContext'] ?? [], $businessContext);
            }
        }

        $step = $payload['step'] ?? null;
        if ($step === 'intent') {
            $patch['currentStep'] = OnboardingSessionService::STEP_BUSINESS_CONTEXT;
        } elseif ($step === 'context') {
            $patch['currentStep'] = OnboardingSessionService::STEP_EVIDENCE_CHECKLIST;
        }

        $request->merge($patch);

        return $this->updateSession($request);
    }

    public function updateEvidenceItem(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $request->validate([
            'status' => ['sometimes', 'string', Rule::in($this->sessions->allowedEvidenceStatuses())],
            'sourceType' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sourceId' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $session = $this->sessions->getOrCreate($context, $request->user());
        $session = $this->sessions->updateEvidenceItem($session, $id, $payload, $request->user());
        $requirementsPayload = $this->evidenceRequirements->requirementsForSession(
            $session,
            $this->dataSources->forContext($context->companyId, $context->businessProfileId),
        );

        return response()->json(array_merge($this->sessionPayload($session, $requirementsPayload, $context), [
            'status' => $payload['status'] ?? 'received',
            'id' => $id,
            'evidenceItem' => [
                'id' => $id,
                'requirementKey' => $id,
                'status' => $payload['status'] ?? 'received',
                'sourceType' => $payload['sourceType'] ?? null,
                'sourceId' => $payload['sourceId'] ?? null,
            ],
        ]));
    }

    public function complete(Request $request): JsonResponse
    {
        $request->merge(['status' => OnboardingSessionService::STATUS_COMPLETED]);
        return $this->updateSession($request);
    }

    private function resolveContext(Request $request): BusinessProfileContext|JsonResponse
    {
        try {
            return $this->businessProfileContext->resolveForRequest($request);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
    }

    private function sessionPayload(OnboardingSession $session, array $requirementsPayload, BusinessProfileContext $context): array
    {
        $dataSources = $this->dataSources->forContext($context->companyId, $context->businessProfileId);

        return [
            'session' => $this->sessions->contract($session, $requirementsPayload['readiness']),
            'evidenceRequirements' => $requirementsPayload['requirements'],
            'dataReadiness' => $requirementsPayload['readiness'],
            'dataSources' => $dataSources['sources'] ?? [],
        ];
    }
}
