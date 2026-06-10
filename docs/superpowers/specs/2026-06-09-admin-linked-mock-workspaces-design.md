# Admin-Linked Mock Workspaces Design

Date: 2026-06-09
Status: Pending user review after local structural review

## Purpose

Brevix needs generated fraud-scenario mock data to be usable through the normal product experience while signed in with the real admin account.

Each mock company should behave like a separate Brevix workspace/company. The admin user can switch between these workspaces, inspect the seeded transactions and findings, run product workflows, and evaluate how the platform performs against realistic scenario data without logging into throwaway accounts.

## Scope Decision

Model every mock company as a separate `companies` workspace, not as a business profile under the admin workspace.

The first release should focus on the core evaluation surfaces:

1. Dashboard/action plan context.
2. Transactions and upload history.
3. Alerts/findings and alert groups.
4. Rex/chat flows that already resolve business profile context.
5. Workspace/profile switching in the authenticated dashboard.

Secondary pages that still read `user()->company_id` directly can remain out of scope unless they block the core evaluation path. They should be documented as follow-up migration work rather than silently pretending to be workspace-aware.

## Current Context

Current relevant implementation:

- Fraud testing stores generated data in `fraud_mock_companies`, `fraud_mock_parties`, and `fraud_mock_transactions`.
- `FraudScenarioProvisionService` can create a fresh real workspace, subscription, default business profile, synthetic upload, seeded transactions, and throwaway login credentials.
- `/api/internal/fraud-testing/scenarios/{id}/provision-workspace` is protected by the internal fraud-testing token and is not part of normal admin auth.
- Auth payloads already include a `workspaces` array built from `users.company_id` plus `workspace_memberships`.
- Business-profile selection already exists on the frontend and API requests send `X-Brevix-Business-Profile-Id`.
- `BusinessProfileContextService` can read `X-Brevix-Workspace-Id`, but the frontend API client does not send that header.
- Several important API surfaces resolve workspace/profile through `BusinessProfileContextService`; some secondary controllers still read `$request->user()->company_id`.
- `AdminUserSeeder` creates the admin company/user but does not currently guarantee a default business profile and workspace membership.

## Goals

1. Let the existing admin user access provisioned mock companies as separate workspaces.
2. Keep each mock company isolated as a real tenant/workspace.
3. Seed mock data into normal product tables used by dashboard, transactions, alerts, uploads, and Rex.
4. Add a dashboard workspace switcher that sets both active workspace and active business profile context.
5. Send `X-Brevix-Workspace-Id` and `X-Brevix-Business-Profile-Id` on authenticated API calls.
6. Preserve existing throwaway credential provisioning for automated or isolated test scenarios.
7. Harden admin seeding so local admin accounts have required workspace/profile context.
8. Add tests that prove one admin token can switch between authorized mock workspaces without crossing into unauthorized workspaces.

## Non-Goals

- Do not merge all mock companies into one admin workspace.
- Do not replace fraud-testing internal token flows with public user-facing routes.
- Do not build a full mock-data management console in the first release.
- Do not promise that every legacy or secondary page is workspace-aware until controllers are migrated from `user()->company_id`.
- Do not weaken tenant authorization to make admin access global by email alone.
- Do not expose generated mock data provisioning to non-admin users.

## Backend Design

### Provisioning Service

Extend `FraudScenarioProvisionService` with an admin-link option.

Recommended interface:

```php
provision(
    FraudScenarioSubmission $submission,
    ?string $email = null,
    ?string $password = null,
    ?User $workspaceMember = null,
    string $workspaceMemberRole = 'admin',
): array
```

Behavior:

- Always create a new `companies` row for the mock company.
- Always create a risk-advisory active subscription so evaluation features are unlocked.
- Always create a default business profile for the mock workspace.
- Always seed a synthetic promoted upload and transaction rows from the scenario mock data.
- When `$workspaceMember` is provided, create a `workspace_memberships` row for that user and the new mock workspace.
- Preserve the current throwaway user path when no `$workspaceMember` is supplied.
- Return `workspace_id`, `workspace_name`, `business_profile_id`, `business_profile_name`, `scenario_id`, `scenario_title`, and `transaction_count`.
- Return generated `email` and `password` only for the throwaway user path.

The service should be idempotent only at the membership layer. Each provision call still creates a fresh mock workspace because repeated test runs should not mutate prior evaluation data.

### Admin-Authenticated Provision Route

Add an authenticated admin route for linking a completed fraud scenario to the current admin user.

Recommended route:

```text
POST /api/admin/fraud-testing/scenarios/{id}/provision-workspace
```

Middleware:

```text
auth:sanctum, admin
```

Request:

```json
{
  "role": "admin"
}
```

The role is optional and must be limited to `owner` or `admin`. The default is `admin`.

Response:

```json
{
  "workspace": {
    "id": "uuid",
    "name": "Mock Company LLC",
    "role": "admin",
    "businessProfileId": "uuid",
    "businessProfileName": "Mock Company LLC"
  },
  "scenario": {
    "id": "uuid",
    "title": "Ghost Employee Test"
  },
  "transactionCount": 250
}
```

The existing internal route remains available for token-based agents and automation.

### Auth And Workspace Payloads

`GET /api/auth/me` and login payloads should continue returning `workspaces`, but each workspace should include enough profile metadata for immediate switching:

```json
{
  "id": "workspace-id",
  "name": "Mock Company LLC",
  "role": "admin",
  "isPrimary": false,
  "businessProfiles": [
    {
      "id": "profile-id",
      "name": "Mock Company LLC",
      "role": "admin",
      "isDefault": true,
      "status": "active"
    }
  ]
}
```

Top-level `businessProfiles` can remain scoped to the primary/current workspace for backward compatibility. The frontend should prefer the selected workspace's embedded profiles when available.

### Admin Seeder Hardening

Update `AdminUserSeeder` so the seeded admin company has:

- `has_completed_onboarding = true`
- an active subscription
- a default business profile
- a workspace membership for the admin user

Use `BusinessProfileContextService::createDefaultProfileForWorkspace` after the user is saved.

### Workspace-Aware Controller Boundary

Core evaluation endpoints should resolve context through `BusinessProfileContextService` and honor `X-Brevix-Workspace-Id`:

- dashboard summary
- action plan
- uploads
- transactions
- alerts and alert groups
- chat/Rex sessions
- onboarding/session context where needed

Controllers that still use `$request->user()->company_id` should be listed in follow-up work unless they are on the selected core evaluation path.

## Frontend Design

### API Client

Add workspace context beside business-profile context:

- `setWorkspaceId(workspaceId: string | null)`
- `getWorkspaceId(): string | null`
- `WORKSPACE_HEADER = 'X-Brevix-Workspace-Id'`

Persist selected workspace id in localStorage on web, matching the current business profile storage behavior.

`buildRequestHeaders` should include `X-Brevix-Workspace-Id` when selected and continue including `X-Brevix-Business-Profile-Id` when selected.

### Auth Context

Extend `User` and `AuthContext` types to include workspaces.

Add:

- `workspaces`
- `activeWorkspace`
- `activeWorkspaceId`
- `selectWorkspace(workspaceId: string)`

Selection behavior:

1. Validate selected workspace exists in the authenticated user's `workspaces`.
2. Persist selected workspace id.
3. Choose the workspace's default profile when present.
4. If no default exists and one active profile exists, select that profile.
5. If multiple profiles exist, clear profile selection and let the existing business-profile prompt handle it.
6. Refresh dashboard data through normal route renders and API calls.

When a restored workspace id is no longer available, fall back to the primary workspace or first accessible workspace.

### Dashboard Switcher

Add a compact workspace switcher to the dashboard top bar.

Requirements:

- Show current workspace name.
- List every workspace returned by auth/workspaces.
- Mark the selected workspace.
- On selection, call `selectWorkspace`.
- Avoid mixing workspace and business-profile labels when each workspace has only one default profile.
- Keep the existing user menu and business-profile prompt behavior.

This switcher is operational UI, not a marketing or admin content page.

## Data Flow

```text
Admin signs in
  -> auth payload includes primary admin workspace plus mock workspace memberships
  -> dashboard switcher selects a mock workspace
  -> frontend stores active workspace id and default business profile id
  -> API calls include X-Brevix-Workspace-Id and X-Brevix-Business-Profile-Id
  -> Laravel resolves membership and profile context
  -> services query seeded normal product tables for that mock workspace
```

Provisioning flow:

```text
Completed fraud scenario
  -> admin provision endpoint
  -> FraudScenarioProvisionService creates real mock workspace
  -> service links current admin user via workspace_memberships
  -> service seeds upload and transactions
  -> admin refreshes auth/workspace list and selects the mock workspace
```

## Authorization And Isolation

- Workspace membership is required for every selected workspace.
- `X-Brevix-Workspace-Id` must never grant access by itself.
- Non-members receive `403` for context resolution or `404` where existence should be hidden.
- Admin role allows provisioning only through authenticated admin middleware.
- Internal fraud-testing token routes remain separate from user-authenticated admin routes.
- Scenario mock data is copied into normal workspace tables; original fraud-testing tables remain internal source data.

## Error Handling

- Scenario not found returns `404`.
- Scenario mock data not completed returns `422`.
- Scenario without a mock company returns `422`.
- Non-admin provision attempts return `403`.
- Workspace switch to an unavailable workspace throws a frontend error and does not update persisted context.
- Missing or ambiguous business profile context uses the existing profile prompt path.

## Test Plan

Backend tests:

- Admin provision route requires auth and admin role.
- Admin provision route rejects incomplete mock data.
- Admin provision route creates a new company, subscription, default profile, upload, and transactions.
- Admin provision route creates workspace membership for the current admin user.
- Provision response omits throwaway password when linking the existing admin user.
- Existing internal provision route still returns generated credentials.
- `AdminUserSeeder` creates a default business profile and workspace membership.
- A selected `X-Brevix-Workspace-Id` lets a member access seeded transactions for that mock workspace.
- The same selected workspace header is rejected for a non-member.

Frontend tests:

- API client includes `X-Brevix-Workspace-Id` after selecting a workspace.
- Workspace selection persists and restores from localStorage.
- Workspace selection chooses the default business profile.
- Top bar switcher renders accessible workspaces and marks the active workspace.
- Selecting a mock workspace updates request headers for subsequent product calls.

Manual verification:

- Seed or provision at least two completed mock scenarios.
- Sign in once with admin credentials.
- Switch between mock companies.
- Confirm dashboard, transactions, findings, uploads, and Rex use the selected company data.

## Release Gate

This work is complete when:

1. Existing throwaway provisioning still works.
2. Admin-linked provisioning creates separate mock workspaces linked to the admin user.
3. The signed-in dashboard can switch between at least two mock companies without logging out.
4. Core evaluation API calls include workspace and profile headers.
5. Tenant isolation tests prove unauthorized workspace headers do not leak data.
6. Remaining `user()->company_id` controller surfaces are documented as follow-up work if not migrated in this slice.

## Follow-Up Work

After the first release:

1. Add an admin UI for listing completed scenarios and provisioning them from the app.
2. Migrate remaining secondary controllers from direct `user()->company_id` reads to `BusinessProfileContextService`.
3. Add provenance fields or tags to provisioned mock workspaces so they can be filtered, archived, or reset cleanly.
4. Generate alerts, recommendations, or investigation artifacts during provisioning when those evaluation workflows require precomputed state.
5. Add bulk provisioning for all approved completed scenarios.
