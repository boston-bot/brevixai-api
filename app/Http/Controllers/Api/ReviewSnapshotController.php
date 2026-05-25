<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessProfileAccessException;
use App\Http\Controllers\Controller;
use App\Services\BusinessProfileContext;
use App\Services\BusinessProfileContextService;
use App\Services\DataSourceRegistryService;
use App\Services\EvidenceRequirementService;
use App\Services\FirstSnapshotService;
use App\Services\OnboardingSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewSnapshotController extends Controller
{
    public function __construct(
        private readonly BusinessProfileContextService $businessProfileContext,
        private readonly OnboardingSessionService $sessions,
        private readonly DataSourceRegistryService $dataSources,
        private readonly EvidenceRequirementService $evidenceRequirements,
        private readonly FirstSnapshotService $firstSnapshot,
    ) {}

    public function firstSnapshot(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $session = $this->sessions->getOrCreate($context, $request->user());
        $dataSources = $this->dataSources->forContext($context->companyId, $context->businessProfileId);
        $requirementsPayload = $this->evidenceRequirements->requirementsForSession($session, $dataSources);

        return response()->json($this->firstSnapshot->placeholder($session, $requirementsPayload, $dataSources));
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
