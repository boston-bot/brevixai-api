<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessProfileAccessException;
use App\Http\Controllers\Controller;
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

        return response()->json([
            'session' => $this->sessions->contract($session, $requirementsPayload['readiness']),
        ]);
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

        return response()->json([
            'session' => $this->sessions->contract($session, $requirementsPayload['readiness']),
        ]);
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

    private function resolveContext(Request $request): BusinessProfileContext|JsonResponse
    {
        try {
            return $this->businessProfileContext->resolveForRequest($request);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
    }
}
