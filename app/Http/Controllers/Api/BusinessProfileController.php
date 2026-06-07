<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessProfileAccessException;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Services\BusinessProfileContextService;
use App\Services\PlanPolicyService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessProfileController extends Controller
{
    public function __construct(
        private readonly BusinessProfileContextService $businessProfileContext,
        private readonly PlanPolicyService $planPolicy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->businessProfileContext->requestedWorkspaceId($request)
            ?: $request->user()->company_id;

        if (! $companyId) {
            return response()->json(['error' => 'No workspace associated with account'], 403);
        }

        return response()->json([
            'businessProfiles' => $this->businessProfileContext->profilesForUser($request->user(), $companyId),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No workspace associated with account'], 403);
        }

        if (! $this->businessProfileContext->canManageWorkspace($request->user(), $companyId)) {
            return response()->json(['error' => 'User is not authorized to manage business profiles'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'entityType' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $this->planPolicy->ensureCanCreateBusinessProfile($companyId);

            $profile = BusinessProfile::create([
                'company_id' => $companyId,
                'name' => $validated['name'],
                'legal_name' => $validated['legalName'] ?? null,
                'industry' => $validated['industry'] ?? null,
                'entity_type' => $validated['entityType'] ?? null,
                'is_default' => false,
                'status' => 'active',
            ]);

            return response()->json([
                'businessProfile' => $this->serializeProfile(
                    $profile,
                    $this->businessProfileContext->workspaceRole($request->user(), $companyId),
                ),
            ], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $context = $this->businessProfileContext->contextForProfile($request->user(), $id);
            $profile = BusinessProfile::findOrFail($id);

            return response()->json(['businessProfile' => $this->serializeProfile($profile, $context->role)]);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! $this->businessProfileContext->canManageBusinessProfile($request->user(), $id)) {
            return response()->json(['error' => 'User is not authorized to manage this business profile'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'entityType' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
        ]);

        $profile = BusinessProfile::findOrFail($id);
        $profile->fill([
            'name' => $validated['name'] ?? $profile->name,
            'legal_name' => array_key_exists('legalName', $validated) ? $validated['legalName'] : $profile->legal_name,
            'industry' => array_key_exists('industry', $validated) ? $validated['industry'] : $profile->industry,
            'entity_type' => array_key_exists('entityType', $validated) ? $validated['entityType'] : $profile->entity_type,
            'status' => $validated['status'] ?? $profile->status,
        ]);
        $profile->save();

        return response()->json([
            'businessProfile' => $this->serializeProfile(
                $profile,
                $this->businessProfileContext->workspaceRole($request->user(), (string) $profile->company_id),
            ),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->businessProfileContext->canManageBusinessProfile($request->user(), $id)) {
            return response()->json(['error' => 'User is not authorized to manage this business profile'], 403);
        }

        $profile = BusinessProfile::findOrFail($id);
        $profile->update(['status' => 'archived']);

        return response()->json(['success' => true, 'id' => $id]);
    }

    private function serializeProfile(BusinessProfile $profile, ?string $role): array
    {
        return [
            'id' => (string) $profile->id,
            'workspaceId' => (string) $profile->company_id,
            'name' => (string) $profile->name,
            'legalName' => $profile->legal_name,
            'industry' => $profile->industry,
            'entityType' => $profile->entity_type,
            'role' => $role ?: 'viewer',
            'isDefault' => (bool) $profile->is_default,
            'status' => (string) $profile->status,
        ];
    }
}
