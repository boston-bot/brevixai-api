# Multi-Business Workspace Tenancy Design

Date: 2026-05-25
Status: Approved for implementation planning

## Purpose

Brevix needs to support one customer account/workspace with multiple business profiles underneath it. A user may connect QuickBooks, upload data, chat with Rex, and run risk workflows against a selected business profile while still belonging to the same Brevix workspace.

This design separates the workspace boundary from the business context boundary:

```text
Company = Brevix workspace/account
Business profile = one operating business inside that workspace
```

The current backend uses `users.company_id` as both account membership and tenant context. The new model keeps `companies` as the workspace and adds `business_profiles` for business-specific context.

## Scope Decision

Implement tenancy as a workspace-plus-business-profile model, not as separate `companies` per business.

Initial scope:

1. Add business profiles under a workspace.
2. Add workspace and business-profile membership tables.
3. Resolve each request to an active business profile.
4. Scope QuickBooks, uploads, Rex sessions, transactions, alerts, cases, and agent runs to the active business profile.
5. Add tier policies for free, starter, growth, and risk-advisory.
6. Backfill existing workspaces with one default business profile.

## Current Context

Current relevant implementation:

- `companies` stores customer company/account metadata.
- `users.company_id` links each user to one company and stores a single `role`.
- `subscriptions.company_id` stores the plan for the company.
- QuickBooks integration rows are company-scoped and can already hold multiple QuickBooks `realm_id` values.
- Uploads, transactions, chat sessions, chat messages, alerts, cases, recommendations, and agent runs are all company-scoped.
- Rex and internal agent tools authorize with `users.company_id`.
- Chat usage quotas are company-scoped in `chat_usage_daily`.

This supports one workspace with multiple QuickBooks realms only loosely. It does not support multiple business profiles, active context switching, or per-business user roles.

## Goals

1. Let one Brevix workspace contain one or more business profiles.
2. Let users switch active business profiles.
3. Ensure Rex always receives the selected business profile context.
4. Allow a user to have different roles per business profile.
5. Allow grantors to make access workspace-wide.
6. Keep billing and plan limits at the workspace level.
7. Add a lean `free` tier below `starter`.
8. Limit free-tier upload session file size to 25 MB.
9. Limit free-tier chat usage.
10. Limit starter workspaces to one business profile.
11. Allow growth workspaces to create many business profiles, with no hard profile limit for now.
12. Preserve existing tenant isolation during migration.

## Non-Goals

- Do not create a separate Brevix workspace for each business.
- Do not redesign billing around per-business subscriptions in this phase.
- Do not remove `company_id` from domain tables in the first migration.
- Do not build invitation email delivery unless required by a later user-management slice.
- Do not implement granular permissions beyond role-based access in this phase.
- Do not support cross-workspace business profile sharing.

## Data Model

### Business Profiles

Add `business_profiles`:

| Column | Purpose |
| --- | --- |
| `id` | UUID primary key |
| `company_id` | Owning Brevix workspace |
| `name` | Business display name |
| `legal_name` | Optional legal entity name |
| `industry` | Optional business-specific industry |
| `entity_type` | Optional legal/entity type |
| `is_default` | Marks the migrated/default profile |
| `status` | `active`, `archived` |
| timestamps | Audit trail |

Starter-tier workspaces can have at most one active business profile. Growth and higher tiers have no profile count limit for now.

### Memberships

Add `workspace_memberships`:

| Column | Purpose |
| --- | --- |
| `id` | UUID primary key |
| `company_id` | Workspace |
| `user_id` | User |
| `role` | Workspace-wide default role |
| `scope` | `workspace` |
| `granted_by` | Grantor user |
| `created_at`, `updated_at` | Audit timestamps |

Add `business_profile_memberships`:

| Column | Purpose |
| --- | --- |
| `id` | UUID primary key |
| `business_profile_id` | Business profile |
| `user_id` | User |
| `role` | Profile-specific role |
| `granted_by` | Grantor user |
| `created_at`, `updated_at` | Audit timestamps |

Effective role:

```text
profile membership role if present
otherwise workspace membership role
otherwise no access
```

Workspace-wide grants are stored in `workspace_memberships`. Profile-specific overrides are stored in `business_profile_memberships`.

### Existing Tables

Add nullable `business_profile_id` to business-context tables, then backfill and enforce:

- `integrations`
- `qbo_transactions`
- `uploads`
- `transactions`
- `budget_lines`
- `invoices`
- `alerts`
- `alert_groups`
- `audit_cases`
- `chat_sessions`
- `chat_messages`
- `chat_usage_daily`
- `rex_pending_actions`
- `agent_runs`
- `agent_action_approvals`
- recommendation and investigation tables that currently carry `company_id`

Keep `company_id` during the transition. It remains the workspace boundary and makes cross-workspace filtering explicit. `business_profile_id` becomes the business context boundary.

## Roles

Use the existing role vocabulary first:

| Role | Initial permissions |
| --- | --- |
| `owner` | Manage workspace, members, billing, business profiles, integrations, data, Rex, approvals |
| `admin` | Manage business profiles, integrations, data, Rex, approvals, and profile users where granted |
| `member` | Use assigned business profiles, upload data, chat with Rex, review assigned workflows |
| `viewer` | Read assigned business profile data and Rex artifacts, no mutations |

Existing `users.role` remains as a compatibility fallback during migration. New authorization code should use membership-derived effective role.

## Active Business Context

Authenticated APIs should resolve an active business profile before touching business-context data.

Accepted context sources, in priority order:

1. Explicit route parameter for profile-management endpoints.
2. `X-Brevix-Business-Profile-Id` header for general app APIs.
3. Request body field where a header is awkward, such as creation endpoints.
4. User's only accessible active profile as a fallback.

If the user has multiple accessible profiles and no active profile is supplied, return `422` with a safe error asking the client to select a business profile.

The resolver must validate:

- business profile exists
- profile belongs to the authenticated user's workspace
- user has workspace-wide or profile-specific access
- subscription tier allows the requested operation

## API Contract

### Auth

`GET /api/auth/me` should return:

```json
{
  "id": "user-id",
  "email": "owner@example.com",
  "workspace": {
    "id": "company-id",
    "name": "Acme Holdings",
    "role": "owner"
  },
  "businessProfiles": [
    {
      "id": "profile-id",
      "name": "Acme Retail",
      "role": "owner",
      "isDefault": true
    }
  ],
  "activeBusinessProfileId": "profile-id"
}
```

### Business Profiles

Add protected endpoints:

```text
GET    /api/business-profiles
POST   /api/business-profiles
GET    /api/business-profiles/{id}
PATCH  /api/business-profiles/{id}
DELETE /api/business-profiles/{id}
```

Delete should archive by default. Hard delete can be a later administrative operation.

### Memberships

Add protected endpoints:

```text
GET  /api/workspace/members
POST /api/workspace/members
PATCH /api/workspace/members/{userId}

GET  /api/business-profiles/{id}/members
POST /api/business-profiles/{id}/members
PATCH /api/business-profiles/{id}/members/{userId}
DELETE /api/business-profiles/{id}/members/{userId}
```

The grantor chooses workspace-wide or profile-specific access. Workspace-wide grants apply across all active profiles unless a profile override exists.

## QuickBooks Context

QuickBooks credentials and connected realms should be profile-scoped:

```text
workspace/company -> business profile -> QuickBooks realm(s)
```

QBO OAuth state should carry both `company_id` and `business_profile_id`. Callback handling should consume the state and write the integration row for that profile. Sync, disconnect, purge, and status endpoints should all resolve the active business profile and filter by both workspace and profile.

The existing `realm_id` remains the external QuickBooks company identifier. `business_profile_id` is the Brevix context that decides which Rex/company data the realm belongs to.

## Rex And Agent Context

Rex chat should be profile-scoped:

- chat sessions include `business_profile_id`
- chat messages include `business_profile_id`
- chat usage is tracked by workspace and optionally profile, depending on quota policy
- orchestrator lookups receive both `company_id` and `business_profile_id`
- agent runner input includes both identifiers
- internal agent tools validate membership against the requested profile

Rex system and tool context should include the active business profile name and workspace name so answers can distinguish:

```text
Workspace: Acme Holdings
Active business profile: Acme Retail
```

## Tier Policy

Add `free` to subscription tier constraints and plan policy.

Initial policy:

| Tier | Business profiles | Upload file size | Chat usage |
| --- | --- | --- | --- |
| `free` | 1 | 25 MB | very limited daily quota |
| `starter` | 1 | existing standard limit | limited paid quota |
| `growth` | many | existing standard limit | higher quota |
| `risk-advisory` | many | existing standard limit | highest quota |

Implementation details:

- Free upload session creation rejects `fileSizeBytes > 25 * 1024 * 1024`.
- Upload completion re-checks the stored object size and fails safely if the actual object exceeds the tier limit.
- Business profile creation checks the active profile count against plan policy.
- Chat quota uses the same plan policy service as upload/profile limits.
- Default signup can create `free` unless the request explicitly asks for a paid tier.

Exact chat numbers can be set in implementation config, but `free` must be materially below `starter`.

## Migration Plan

1. Create `business_profiles`, `workspace_memberships`, and `business_profile_memberships`.
2. Add nullable `business_profile_id` columns and indexes to business-context tables.
3. For each existing `companies` row, create one default business profile.
4. For each existing user with `company_id`, create a workspace membership using `users.role`.
5. Backfill existing business-context rows to the workspace default profile.
6. Update reads and writes to resolve active business profile.
7. Make `business_profile_id` non-null on fully migrated tables.
8. Keep compatibility fallbacks until all tests and clients use the new context.

Migration should not delete or rewrite unrelated existing data.

## Error Handling

| Case | Status | Behavior |
| --- | --- | --- |
| User has no workspace membership | `403` | Safe account access error |
| Multiple profiles and no active profile | `422` | Ask client to select a business profile |
| Profile belongs to another workspace | `404` | Hide existence |
| User lacks profile access | `403` | Safe authorization error |
| Starter creates second active profile | `422` | Plan limit error |
| Free upload exceeds 25 MB claim | `422` | Reject before presigned URL |
| Free upload actual object exceeds 25 MB | `422` or failed upload status | Reject after storage stat |
| Rex session profile mismatch | `404` | Hide session existence |

## Testing

Membership and context:

- User with workspace-wide role can access all workspace profiles.
- User with only profile membership can access that profile and no others.
- Profile-specific role overrides workspace role.
- Cross-workspace profile access is rejected.
- Multiple-profile user without active profile receives `422`.

Business profiles:

- Free and starter cannot create a second active profile.
- Growth can create multiple active profiles.
- Archived profiles cannot be selected as active context.

QuickBooks:

- QBO OAuth state binds to the selected business profile.
- QBO status only returns integrations for the active profile.
- QBO sync writes transactions to the active profile.
- Disconnect and purge cannot affect another profile.

Uploads:

- Free upload session rejects claimed files over 25 MB.
- Free upload completion rejects actual stored objects over 25 MB.
- Upload listing is profile-scoped.
- Promoted transactions inherit `business_profile_id`.

Rex and agent tools:

- Chat sessions are created under the active profile.
- Listing sessions only returns sessions for the active profile.
- Agent runner receives `business_profile_id`.
- Internal agent tools reject unauthorized profile access.
- Rex responses are based on the selected profile's data only.

Regression:

- Existing single-company users receive one default profile.
- Existing tests using only `users.company_id` continue to pass during compatibility phase.
- Company-scoped queries still include `company_id` to preserve workspace isolation.
