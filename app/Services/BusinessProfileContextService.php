<?php

namespace App\Services;

use App\Exceptions\BusinessProfileAccessException;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileMembership;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessProfileContextService
{
    /** @return list<array{id: string, name: string, role: string, isDefault: bool, status: string}> */
    public function profilesForUser(User $user, ?string $companyId = null): array
    {
        if (! Schema::hasTable('business_profiles')) {
            return [];
        }

        $workspaceId = $companyId ?: $this->workspaceIdForUser($user);
        if (! $workspaceId) {
            return [];
        }

        $workspaceRole = $this->workspaceRole($user, $workspaceId);
        $profileOverrides = $this->profileMembershipRoles($user, $workspaceId);

        if ($workspaceRole) {
            return BusinessProfile::query()
                ->where('company_id', $workspaceId)
                ->where('status', 'active')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (BusinessProfile $profile): array => [
                    'id' => (string) $profile->id,
                    'name' => (string) $profile->name,
                    'role' => $profileOverrides[$profile->id] ?? $workspaceRole,
                    'isDefault' => (bool) $profile->is_default,
                    'status' => (string) $profile->status,
                ])
                ->values()
                ->all();
        }

        if (! Schema::hasTable('business_profile_memberships')) {
            return [];
        }

        return BusinessProfile::query()
            ->select('business_profiles.*', 'business_profile_memberships.role as membership_role')
            ->join('business_profile_memberships', 'business_profile_memberships.business_profile_id', '=', 'business_profiles.id')
            ->where('business_profiles.company_id', $workspaceId)
            ->where('business_profiles.status', 'active')
            ->where('business_profile_memberships.user_id', $user->id)
            ->orderByDesc('business_profiles.is_default')
            ->orderBy('business_profiles.name')
            ->get()
            ->map(fn (BusinessProfile $profile): array => [
                'id' => (string) $profile->id,
                'name' => (string) $profile->name,
                'role' => (string) $profile->membership_role,
                'isDefault' => (bool) $profile->is_default,
                'status' => (string) $profile->status,
            ])
            ->values()
            ->all();
    }

    public function resolveForRequest(Request $request, ?string $companyId = null): BusinessProfileContext
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new BusinessProfileAccessException('Unauthenticated.', 401);
        }

        $workspaceId = $companyId ?: $this->workspaceIdForUser($user);
        if (! $workspaceId) {
            throw new BusinessProfileAccessException('No workspace associated with account.', 403);
        }

        return $this->resolveForUser($user, $workspaceId, $this->requestedBusinessProfileId($request));
    }

    public function resolveForUser(User $user, string $companyId, ?string $businessProfileId = null): BusinessProfileContext
    {
        if (! Schema::hasTable('business_profiles')) {
            return new BusinessProfileContext($companyId, null, (string) ($this->workspaceRole($user, $companyId) ?? $user->role ?? 'owner'));
        }

        if ($businessProfileId) {
            return $this->contextForProfile($user, $businessProfileId, $companyId);
        }

        $profiles = $this->profilesForUser($user, $companyId);

        if (count($profiles) === 1) {
            $profile = $profiles[0];

            return new BusinessProfileContext(
                $companyId,
                $profile['id'],
                $profile['role'],
                $profile['name'],
            );
        }

        if (count($profiles) === 0) {
            throw new BusinessProfileAccessException('No active business profile is available for this workspace.', 403);
        }

        throw new BusinessProfileAccessException('Select an active business profile before continuing.', 422);
    }

    public function contextForProfile(User $user, string $businessProfileId, ?string $companyId = null): BusinessProfileContext
    {
        if (! Schema::hasTable('business_profiles')) {
            $workspaceId = $companyId ?: $this->workspaceIdForUser($user);

            return new BusinessProfileContext((string) $workspaceId, null, (string) ($user->role ?? 'owner'));
        }

        $profile = BusinessProfile::where('id', $businessProfileId)
            ->where('status', 'active')
            ->first();

        if (! $profile) {
            throw new BusinessProfileAccessException('Business profile not found.', 404);
        }

        $workspaceId = $companyId ?: (string) $profile->company_id;
        if ((string) $profile->company_id !== $workspaceId) {
            throw new BusinessProfileAccessException('Business profile not found.', 404);
        }

        $role = $this->effectiveRole($user, $profile);
        if (! $role) {
            throw new BusinessProfileAccessException('User is not authorized for this business profile.', 403);
        }

        return new BusinessProfileContext($workspaceId, (string) $profile->id, $role, (string) $profile->name);
    }

    public function canManageWorkspace(User $user, string $companyId): bool
    {
        return in_array($this->workspaceRole($user, $companyId), ['owner', 'admin'], true);
    }

    public function canManageBusinessProfile(User $user, string $businessProfileId): bool
    {
        try {
            $context = $this->contextForProfile($user, $businessProfileId);
        } catch (BusinessProfileAccessException) {
            return false;
        }

        return in_array($context->role, ['owner', 'admin'], true);
    }

    public function createDefaultProfileForWorkspace(Company $company, User $owner): ?BusinessProfile
    {
        if (! Schema::hasTable('business_profiles')) {
            return null;
        }

        return DB::transaction(function () use ($company, $owner): BusinessProfile {
            $profile = BusinessProfile::firstOrCreate(
                ['company_id' => $company->id, 'is_default' => true],
                [
                    'name' => $company->name,
                    'industry' => $company->industry,
                    'entity_type' => $company->entity_type,
                    'status' => 'active',
                ],
            );

            if (Schema::hasTable('workspace_memberships')) {
                WorkspaceMembership::updateOrCreate(
                    ['company_id' => $company->id, 'user_id' => $owner->id],
                    [
                        'role' => $owner->role ?: 'owner',
                        'scope' => 'workspace',
                        'granted_by' => $owner->id,
                    ],
                );
            }

            return $profile;
        });
    }

    public function workspaceRole(User $user, string $companyId): ?string
    {
        if (Schema::hasTable('workspace_memberships')) {
            $membership = WorkspaceMembership::where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->first();

            if ($membership) {
                return (string) $membership->role;
            }
        }

        if ((string) $user->company_id === $companyId) {
            return (string) ($user->role ?: 'owner');
        }

        return null;
    }

    private function effectiveRole(User $user, BusinessProfile $profile): ?string
    {
        if (Schema::hasTable('business_profile_memberships')) {
            $profileMembership = BusinessProfileMembership::where('business_profile_id', $profile->id)
                ->where('user_id', $user->id)
                ->first();

            if ($profileMembership) {
                return (string) $profileMembership->role;
            }
        }

        return $this->workspaceRole($user, (string) $profile->company_id);
    }

    private function workspaceIdForUser(User $user): ?string
    {
        if ($user->company_id) {
            return (string) $user->company_id;
        }

        if (Schema::hasTable('workspace_memberships')) {
            $membership = WorkspaceMembership::where('user_id', $user->id)
                ->orderBy('created_at')
                ->first();

            return $membership ? (string) $membership->company_id : null;
        }

        return null;
    }

    private function requestedBusinessProfileId(Request $request): ?string
    {
        $header = $request->header('X-Brevix-Business-Profile-Id');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $camel = $request->input('businessProfileId');
        if (is_string($camel) && $camel !== '') {
            return $camel;
        }

        $snake = $request->input('business_profile_id');
        if (is_string($snake) && $snake !== '') {
            return $snake;
        }

        return null;
    }

    /** @return array<string, string> */
    private function profileMembershipRoles(User $user, string $companyId): array
    {
        if (! Schema::hasTable('business_profile_memberships')) {
            return [];
        }

        return BusinessProfileMembership::query()
            ->select('business_profile_memberships.business_profile_id', 'business_profile_memberships.role')
            ->join('business_profiles', 'business_profiles.id', '=', 'business_profile_memberships.business_profile_id')
            ->where('business_profiles.company_id', $companyId)
            ->where('business_profiles.status', 'active')
            ->where('business_profile_memberships.user_id', $user->id)
            ->pluck('business_profile_memberships.role', 'business_profile_memberships.business_profile_id')
            ->map(fn (string $role): string => $role)
            ->all();
    }
}
