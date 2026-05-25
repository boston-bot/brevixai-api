<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\BusinessProfileContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceMemberController extends Controller
{
    public function __construct(private readonly BusinessProfileContextService $businessProfileContext) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No workspace associated with account'], 403);
        }

        if (! $this->businessProfileContext->canManageWorkspace($request->user(), $companyId)) {
            return response()->json(['error' => 'User is not authorized to manage workspace members'], 403);
        }

        $members = WorkspaceMembership::query()
            ->where('company_id', $companyId)
            ->with('user:id,email,first_name,last_name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMembership $membership): array => $this->serializeMembership($membership))
            ->values();

        return response()->json(['members' => $members]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No workspace associated with account'], 403);
        }

        if (! $this->businessProfileContext->canManageWorkspace($request->user(), $companyId)) {
            return response()->json(['error' => 'User is not authorized to manage workspace members'], 403);
        }

        $validated = $request->validate([
            'userId' => ['required', 'uuid', 'exists:users,id'],
            'role' => ['required', 'string', Rule::in(['owner', 'admin', 'member', 'viewer'])],
        ]);

        $member = User::findOrFail($validated['userId']);
        if (! $member->company_id) {
            $member->forceFill(['company_id' => $companyId])->save();
        }

        $membership = WorkspaceMembership::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $member->id],
            [
                'role' => $validated['role'],
                'scope' => 'workspace',
                'granted_by' => $request->user()->id,
            ],
        )->load('user:id,email,first_name,last_name');

        return response()->json(['member' => $this->serializeMembership($membership)], 201);
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No workspace associated with account'], 403);
        }

        if (! $this->businessProfileContext->canManageWorkspace($request->user(), $companyId)) {
            return response()->json(['error' => 'User is not authorized to manage workspace members'], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(['owner', 'admin', 'member', 'viewer'])],
        ]);

        $membership = WorkspaceMembership::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $membership->update([
            'role' => $validated['role'],
            'granted_by' => $request->user()->id,
        ]);

        return response()->json(['member' => $this->serializeMembership($membership->load('user:id,email,first_name,last_name'))]);
    }

    private function serializeMembership(WorkspaceMembership $membership): array
    {
        return [
            'userId' => (string) $membership->user_id,
            'email' => $membership->user?->email,
            'firstName' => $membership->user?->first_name,
            'lastName' => $membership->user?->last_name,
            'role' => (string) $membership->role,
            'scope' => (string) $membership->scope,
        ];
    }
}
