<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfileMembership;
use App\Models\User;
use App\Services\BusinessProfileContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessProfileMemberController extends Controller
{
    public function __construct(private readonly BusinessProfileContextService $businessProfileContext) {}

    public function index(Request $request, string $businessProfileId): JsonResponse
    {
        if (! $this->businessProfileContext->canManageBusinessProfile($request->user(), $businessProfileId)) {
            return response()->json(['error' => 'User is not authorized to manage business profile members'], 403);
        }

        $members = BusinessProfileMembership::query()
            ->where('business_profile_id', $businessProfileId)
            ->with('user:id,email,first_name,last_name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (BusinessProfileMembership $membership): array => $this->serializeMembership($membership))
            ->values();

        return response()->json(['members' => $members]);
    }

    public function store(Request $request, string $businessProfileId): JsonResponse
    {
        if (! $this->businessProfileContext->canManageBusinessProfile($request->user(), $businessProfileId)) {
            return response()->json(['error' => 'User is not authorized to manage business profile members'], 403);
        }

        $validated = $request->validate([
            'userId' => ['required', 'uuid', 'exists:users,id'],
            'role' => ['required', 'string', Rule::in(['owner', 'admin', 'member', 'viewer'])],
        ]);

        $member = User::findOrFail($validated['userId']);
        $membership = BusinessProfileMembership::updateOrCreate(
            ['business_profile_id' => $businessProfileId, 'user_id' => $member->id],
            [
                'role' => $validated['role'],
                'granted_by' => $request->user()->id,
            ],
        )->load('user:id,email,first_name,last_name');

        return response()->json(['member' => $this->serializeMembership($membership)], 201);
    }

    public function update(Request $request, string $businessProfileId, string $userId): JsonResponse
    {
        if (! $this->businessProfileContext->canManageBusinessProfile($request->user(), $businessProfileId)) {
            return response()->json(['error' => 'User is not authorized to manage business profile members'], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(['owner', 'admin', 'member', 'viewer'])],
        ]);

        $membership = BusinessProfileMembership::where('business_profile_id', $businessProfileId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $membership->update([
            'role' => $validated['role'],
            'granted_by' => $request->user()->id,
        ]);

        return response()->json(['member' => $this->serializeMembership($membership->load('user:id,email,first_name,last_name'))]);
    }

    public function destroy(Request $request, string $businessProfileId, string $userId): JsonResponse
    {
        if (! $this->businessProfileContext->canManageBusinessProfile($request->user(), $businessProfileId)) {
            return response()->json(['error' => 'User is not authorized to manage business profile members'], 403);
        }

        BusinessProfileMembership::where('business_profile_id', $businessProfileId)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['success' => true]);
    }

    private function serializeMembership(BusinessProfileMembership $membership): array
    {
        return [
            'userId' => (string) $membership->user_id,
            'email' => $membership->user?->email,
            'firstName' => $membership->user?->first_name,
            'lastName' => $membership->user?->last_name,
            'role' => (string) $membership->role,
            'scope' => 'business_profile',
        ];
    }
}
