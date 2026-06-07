<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\WorkspaceMembership;
use App\Services\BusinessProfileContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly BusinessProfileContextService $contextService,
    ) {}

    /**
     * GET /api/workspaces
     * Returns all workspaces (companies) the authenticated user has access to.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $companyIds = WorkspaceMembership::where('user_id', $user->id)
            ->pluck('company_id')
            ->push($user->company_id)
            ->filter()
            ->unique()
            ->values();

        $workspaces = Company::whereIn('id', $companyIds)
            ->orderBy('name')
            ->get()
            ->map(function (Company $company) use ($user): array {
                $role = $this->contextService->workspaceRole($user, (string) $company->id);
                $profiles = $this->contextService->profilesForUser($user, (string) $company->id);

                return [
                    'id' => (string) $company->id,
                    'name' => (string) $company->name,
                    'industry' => $company->industry,
                    'entityType' => $company->entity_type,
                    'role' => $role ?: 'owner',
                    'isPrimary' => (string) $user->company_id === (string) $company->id,
                    'businessProfiles' => $profiles,
                ];
            })
            ->values();

        return response()->json(['workspaces' => $workspaces]);
    }

    /**
     * POST /api/workspaces
     * Creates a new workspace (company) and adds the current user as owner.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'entityType' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $workspace = DB::transaction(function () use ($validated, $user): array {
            $company = Company::create([
                'id' => (string) Str::uuid(),
                'name' => $validated['name'],
                'industry' => $validated['industry'] ?? null,
                'entity_type' => $validated['entityType'] ?? null,
            ]);

            Subscription::create([
                'company_id' => $company->id,
                'tier' => 'free',
                'status' => 'active',
            ]);

            $profile = $this->contextService->createDefaultProfileForWorkspace($company, $user);

            return [
                'id' => (string) $company->id,
                'name' => (string) $company->name,
                'industry' => $company->industry,
                'entityType' => $company->entity_type,
                'role' => 'owner',
                'isPrimary' => false,
                'businessProfiles' => $profile ? [[
                    'id' => (string) $profile->id,
                    'name' => (string) $profile->name,
                    'role' => 'owner',
                    'isDefault' => true,
                    'status' => 'active',
                ]] : [],
            ];
        });

        return response()->json(['workspace' => $workspace], 201);
    }
}
