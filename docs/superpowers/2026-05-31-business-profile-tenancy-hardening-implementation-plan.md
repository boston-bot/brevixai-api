# Business Profile Tenancy Hardening Implementation Plan

Date: 2026-05-31
Status: Implementation in progress
Scope: `brevixai-api`

Implementation started: 2026-06-01

## Purpose

This plan closes the gap between the approved multi-business workspace tenancy design and the current implementation.

The target invariant is:

```text
Company = workspace/account boundary
Business profile = business data boundary
```

Every business-context request must resolve an authorized business profile before reading or mutating profile-scoped data. Every profile-scoped query must filter by both `company_id` and `business_profile_id` unless the route is explicitly workspace-level.

## Non-Goals

- Do not redesign billing. Subscriptions remain workspace-scoped.
- Do not remove `company_id` from business tables.
- Do not change public pricing or plan tiers.
- Do not rewrite the ingestion pipeline beyond applying profile scope.
- Do not solve unrelated gaps such as password reset delivery, subscription status enforcement, or `.env.example` cleanup in this pass.

## Guiding Decisions

- Use `BusinessProfileContextService` and `BusinessProfileContext` as the canonical request context.
- Require explicit profile selection when a user has access to more than one active profile.
- Keep the one-profile fallback for workspaces with exactly one accessible active profile.
- Treat missing profile context on profile-scoped internal agent tools as an authorization error, not as permission to run company-wide.
- Prefer passing `BusinessProfileContext` into services over passing loose `$companyId` and `$businessProfileId` pairs when touching files already being edited.
- Keep company-wide admin routes explicit so reviewers can see when profile scope is intentionally not applied.

## Progress Tracker

### Phase 0: Final Inventory And Classification

- [ ] List every authenticated route and classify it as one of:
  - workspace-level
  - profile-scoped read
  - profile-scoped write
  - internal agent profile-scoped tool
- [ ] Confirm each profile-scoped table has `business_profile_id`.
- [ ] Identify legacy nullable `business_profile_id` handling needed for existing production rows.
- [ ] Create a route-to-service checklist in this document or a follow-up audit table.

Exit criteria:

- [ ] Every route has an explicit scope classification.
- [ ] Ambiguous routes are resolved before implementation begins.

### Phase 1: Centralize Request Context Usage

- [x] Add a small controller helper or middleware pattern for resolving `BusinessProfileContext`.
- [ ] Standardize error responses from `BusinessProfileAccessException`.
- [ ] Update controllers to resolve context before touching profile-scoped services.
- [ ] Preserve workspace-level routes that should not require profile context.
- [x] Add tests for missing profile header when a user has two profiles.

Implementation notes:

- 2026-06-01: Added `Controller::resolveBusinessProfileContext()` and applied it to dashboard and analytics controllers.
- 2026-06-01: Added regression coverage for multi-profile missing-context errors and single-profile fallback on dashboard/analytics.
- 2026-06-01: Applied the same helper to alerts, alert recommendations, cases, and case recommendations.

Primary files:

- `app/Services/BusinessProfileContextService.php`
- `app/Services/BusinessProfileContext.php`
- `app/Exceptions/BusinessProfileAccessException.php`
- `app/Http/Controllers/Api/*Controller.php`
- `routes/api.php`

Exit criteria:

- [ ] Profile-scoped API routes return `422` when a multi-profile user omits profile context.
- [ ] Profile-scoped API routes return `404` or `403` for inaccessible profiles.
- [ ] Single-profile workspaces still work without a header.

### Phase 2: Scope Core Customer-Facing APIs

- [x] Dashboard: scope summaries, trends, recent alerts, and breakdowns.
- [x] Analytics: scope performance summary, top vendors, cash flow, and trends.
- [ ] Alerts: scope list, detail, status updates, grouping, rule execution output, and recommendation review surfaces.
- [x] Cases: scope list, detail, creation, linked alerts, linked transactions, summary, and generated investigation records.
- [ ] Reconciliation: scope runs, discrepancies, detail, actions, and summary.
- [ ] Controls: scope definitions, evaluations, violations, and dashboard payloads where definitions are business-specific.
- [ ] GnuCash: scope import, purge, transactions, and status to the active profile.
- [ ] Entity graph and investigations: verify profile handling and add filters where missing.

Primary files:

- `app/Http/Controllers/Api/DashboardController.php`
- `app/Http/Controllers/Api/AnalyticsController.php`
- `app/Http/Controllers/Api/AlertController.php`
- `app/Http/Controllers/Api/CaseController.php`
- `app/Http/Controllers/Api/ReconciliationController.php`
- `app/Http/Controllers/Api/ControlsController.php`
- `app/Http/Controllers/Api/GnuCashController.php`
- `app/Services/DashboardService.php`
- `app/Services/AnalyticsService.php`
- `app/Services/AlertService.php`
- `app/Services/CaseService.php`
- `app/Services/ReconciliationService.php`
- `app/Services/ControlsService.php`
- `app/Services/GnuCashService.php`

Exit criteria:

- [ ] For two profiles in one workspace, each endpoint returns only the selected profile's data.
- [ ] Mutations cannot update records from another profile in the same workspace.
- [ ] Response contracts stay backward compatible except for required profile-context errors.

Implementation notes:

- 2026-06-01: Alerts now resolve active profile context for list/detail/status/grouping and recommendation review endpoints; approved recommendations create profile-tagged alerts. The `/api/alerts/run` output is profile-tagged, but its risk-scoring inputs still need the Phase 5 unified ledger/risk-scoring pass before this row should be marked complete.
- 2026-06-01: Cases now resolve active profile context for list/detail/create/update/events/link/unlink/summary. Linked alerts and transactions are validated against both workspace and selected profile before storing.

### Phase 3: Harden Rex And Internal Agent Tools

- [x] Require profile context for profile-scoped deterministic agent tools.
- [x] Pass the selected profile into Rex orchestrator routes backed by profile-aware services.
- [x] Update optional deterministic tool metadata to indicate profile-context requirements.
- [x] Ensure agent tool authorization validates both workspace membership and profile access.
- [x] Scope agent-created approvals, recommendations, cases, and alerts to the active profile.
- [ ] Verify investigation activity/evidence tables and routes persist/filter `business_profile_id` rather than only carrying profile metadata.
- [x] Add regression tests where an agent run for Profile A cannot read Profile B risk, alerts, transactions, or recommendations.

Primary files:

- `app/Services/RexOrchestratorService.php`
- `app/Services/Agents/BrevixAgentRunner.php`
- `app/Http/Controllers/Internal/AgentToolController.php`
- `app/Services/Agents/*Risk*Service.php`
- `app/Services/AlertRecommendationService.php`
- `app/Services/CaseRecommendationService.php`

Exit criteria:

- [x] Internal agent tools reject ambiguous profile context.
- [ ] Rex dashboard, analytics, alerts, vendors, cases, controls, and recommendations are profile-scoped.
- [x] Agent-generated recommendations, alerts, cases, and approvals carry the selected `business_profile_id`.

Implementation notes:

- 2026-06-01: Added `BusinessProfileContextService::resolveForUser()` so internal agent tools can validate `X-Brevix-User-Id` plus optional `X-Brevix-Business-Profile-Id` without relying on an authenticated HTTP user.
- 2026-06-01: Internal business-data agent tools now reject ambiguous multi-profile context, include `business_profile_id` in responses, and pass the profile into risk, vendor, reconciliation, entity relationship, aggregate, behavioral baseline, transaction-detail, dashboard, alert recommendation, case recommendation, and pending-recommendation paths.
- 2026-06-01: `BrevixAgentRunner` now advertises `requires_business_profile_context` and the `X-Brevix-Business-Profile-Id` header on optional deterministic tools.
- 2026-06-01: Rex now threads the selected profile into dashboard, analytics, alerts, vendors, transactions, dashboard health, cases, alert recommendations, and case recommendations. Reconciliation, controls, entity graph, reporting, AR, and source-specific routes still need the Phase 2/5 service-level pass before this exit row is fully complete.
- 2026-06-01: Recommendation generation, review audit history, alert approval, and case approval now filter by active profile and persist profile ids where the backing table supports them.
- 2026-06-01: Regression coverage added for ambiguous internal tool context, transaction-detail profile isolation, pending-recommendation service scoping, Rex alert-route scoping, and alert/case approval cross-profile protection.
- 2026-06-01: Verification passed with `php artisan test` — 392 tests, 1989 assertions.

### Phase 4: Query And Data Integrity Hardening

- [x] Replace raw interpolated UUID-array SQL in case creation with validated IDs and parameterized updates.
- [x] Validate linked `alert_ids.*` and `transaction_ids.*` as UUIDs.
- [x] Verify linked alert and transaction IDs belong to the same company and business profile before storing.
- [ ] Add database indexes for high-volume profile-scoped query paths where missing.
- [ ] Decide whether to make `business_profile_id` non-null on fully migrated profile-scoped tables.
- [ ] Add production data checks for null or mismatched profile IDs before tightening constraints.

Primary files:

- `app/Http/Controllers/Api/CaseController.php`
- `app/Services/CaseService.php`
- profile-related migrations

Exit criteria:

- [x] Case links cannot reference another profile's records.
- [x] No raw request values are interpolated into SQL.
- [ ] Production data can be audited before non-null constraints are considered.

Implementation notes:

- 2026-06-01: `CaseService` now normalizes UUID arrays, validates linked records against selected profile context, and writes `alert_ids` / `transaction_ids` through parameterized updates. The service keeps a SQLite-compatible fallback for the feature test suite.

### Phase 5: Cross-Source Ledger Consistency

- [ ] Create a unified profile-scoped transaction access pattern for upload, QuickBooks, and GnuCash rows.
- [ ] Move risk and recommendation inputs from upload-only `transactions` queries to the unified ledger where appropriate.
- [ ] Ensure `all_transactions` profile filters are consistently applied.
- [ ] Verify purge/delete cleanup does not delete alerts or recommendations tied to another source or profile.

Primary files:

- `database/migrations/*all_transactions*`
- `app/Services/Agents/VendorRiskScoringService.php`
- `app/Services/Agents/ReconciliationRiskScoringService.php`
- `app/Services/Agents/EntityRelationshipRiskScoringService.php`
- `app/Services/Agents/AggregateRiskSummaryService.php`
- `app/Services/UploadService.php`
- `app/Services/QboService.php`
- `app/Services/GnuCashService.php`

Exit criteria:

- [ ] Risk scoring includes the selected profile's supported data sources.
- [ ] QBO/GnuCash data does not leak into another profile's findings.
- [ ] Source-specific purge paths only affect the selected profile and source.

### Phase 6: Regression Test Matrix

- [ ] Add a shared test fixture for one workspace with Profile A and Profile B.
- [ ] Add a restricted user with access to only Profile A.
- [ ] Add an owner/admin with access to both profiles.
- [ ] Cover missing profile context for multi-profile users.
- [ ] Cover inaccessible profile context.
- [ ] Cover list/detail/update/create behavior for each core surface.
- [x] Cover Rex and internal agent tool behavior.
- [ ] Cover QBO, GnuCash, and upload data together in `all_transactions`.

Suggested test files:

- `tests/Feature/BusinessProfileTenancyTest.php` — dashboard/analytics profile-context coverage added 2026-06-01; alerts/recommendations/cases profile isolation coverage added 2026-06-01.
- `tests/Feature/AgentToolAuthorizationTest.php` — internal agent context and profile-safe error coverage.
- `tests/Feature/AgentToolEndpointTest.php` — internal tool ambiguous-context, transaction-detail isolation, and pending-recommendation service scoping coverage added 2026-06-01.
- `tests/Feature/RexChatControllerTest.php`
- `tests/Feature/AlertRecommendationServiceTest.php`
- `tests/Feature/CaseRecommendationServiceTest.php` — case approval active-profile isolation coverage added 2026-06-01.
- `tests/Feature/AlertRecommendationReviewWorkflowTest.php` — alert approval active-profile isolation coverage added 2026-06-01.
- focused new tests as needed, for example `DashboardBusinessProfileTenancyTest`

Exit criteria:

- [ ] Tests fail against the current known gaps.
- [ ] Tests pass after each phase's implementation.
- [ ] `php artisan test` passes on the Postgres-backed test setup used by the project.

## Route Classification Draft

| Surface | Expected Scope | Notes |
| --- | --- | --- |
| Auth `/me` | workspace plus accessible profiles | Should expose available profiles and active profile metadata. |
| Business profiles | workspace-level management | Specific profile show/update/delete still validates profile access. |
| Workspace members | workspace-level management | Does not require active business profile. |
| Business profile members | profile-management | Route profile ID is the context. |
| Uploads | profile-scoped | Already partially covered; expand regression coverage. |
| Integrations / QBO | profile-scoped | Status and sync should use active profile. |
| GnuCash | profile-scoped | Import and purge must not be workspace-wide. |
| Dashboard | profile-scoped | Currently company-wide. |
| Analytics | profile-scoped | Currently company-wide. |
| Alerts | profile-scoped | List, detail, status, grouping, and generated alerts. |
| Cases | profile-scoped | Creation and linked evidence need validation. |
| Reconciliation | profile-scoped | Runs, discrepancies, and actions. |
| Controls | profile-scoped unless explicitly workspace policy | Confirm whether definitions are per-business or workspace defaults. |
| Entity graph | profile-scoped | Verify current implementation and tests. |
| Investigations | profile-scoped | Verify all evidence and export paths. |
| Rex chat | profile-scoped | Already partially covered. |
| Rex deterministic routes | profile-scoped | Transactions scoped; other routes need hardening. |
| Internal agent tools | profile-scoped for business data tools | Must reject missing/ambiguous profile context. |
| Subscriptions | workspace-level | No active profile required. |
| Site content/admin content | workspace/admin-level | Out of scope for business profile data isolation. |

## Implementation Order

1. Phase 0 inventory.
2. Phase 1 context pattern.
3. Phase 2 customer-facing APIs in small PRs by surface.
4. Phase 3 Rex and agent tools.
5. Phase 4 case/data integrity hardening.
6. Phase 5 unified ledger/risk consistency.
7. Phase 6 full regression sweep.

Recommended PR slicing:

- PR 1: Context helper plus dashboard and analytics.
- PR 2: Alerts and cases.
- PR 3: Reconciliation, controls, GnuCash, entity graph, investigations verification.
- PR 4: Rex orchestrator and internal agent tools.
- PR 5: Case SQL/link validation and data integrity checks.
- PR 6: Unified ledger/risk scoring consistency.

## Completion Criteria

- [ ] Every profile-scoped route resolves `BusinessProfileContext`.
- [ ] Every profile-scoped query filters by `company_id` and `business_profile_id`.
- [x] Internal agent tools cannot run profile-scoped business data queries without profile context.
- [ ] Rex never returns cross-profile data for a selected business profile.
- [ ] Cases, alerts, recommendations, investigations, and approvals persist `business_profile_id`.
- [ ] Regression tests cover same-workspace cross-profile isolation.
- [ ] Existing single-profile workspace behavior remains compatible.
