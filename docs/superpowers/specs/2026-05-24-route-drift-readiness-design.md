# Route Drift Readiness Design

Date: 2026-05-24
Status: Approved for implementation planning

## Purpose

Phase 1 of the readiness program closes all known frontend/backend route drift before expanding Rex or the agent-service architecture.

The goal is not to make every feature agentic. The goal is to make the product coherent: every visible frontend workflow either calls a real Laravel route, uses an existing route, or is intentionally hidden/unavailable until the backend feature exists.

## Scope Decision

Prioritize demo and product readiness first, but include all known frontend/backend route drift rather than only demo-critical failures.

The later canonical process registry, human approval execution, and LangGraph expansion remain separate phases. Phase 1 may add a small route-contract gate because it directly prevents regressions in product readiness.

## Current Context

Active production Rex flow is Laravel to `brevixai-agents`. The older frontend Python/Node orchestrator path is not the production control plane.

Current relevant gaps:

- Session chat uses `/api/chat/sessions/{sessionId}/messages`.
- Standalone Rex still uses `/api/chat/agent/messages`.
- Persisted `agent_action_approvals` exist, but standalone Rex displays approval-backed recommendations as review-only local actions.
- Frontend pages call several API routes that Laravel does not expose.
- Some missing routes are real product features; others are stale wrappers around routes that already exist.

## Goals

1. Every visible frontend API call has a valid Laravel route or an explicit product decision to hide/remove it.
2. Existing deterministic Laravel workflows remain the source of truth for facts and mutations.
3. No new LLM-driven route is introduced to paper over missing deterministic product routes.
4. Contract tests prevent new frontend route drift.
5. User-facing no-data and unavailable states explain the next deterministic action instead of failing with 404s.

## Non-Goals

- Do not build the full canonical process registry in Phase 1.
- Do not broaden LangGraph beyond the current high-value risk-review path.
- Do not make uploads, integrations, auth, subscriptions, onboarding, or personal finance agent-driven.
- Do not fake legal, client-management, or report workflows with placeholder backend mutations.
- Do not execute `agent_action_approvals` in this phase; that belongs to approval execution.

## Route Drift Inventory

| Frontend call | Current state | Phase 1 treatment |
| --- | --- | --- |
| `POST /api/auth/forgot-password` | Frontend service exists; Laravel route missing. | Implement deterministic password reset request using existing `password_resets` table. Return a safe generic success response. |
| `POST /api/auth/reset-password` | Frontend service exists; Laravel route missing. | Implement token validation, password update, token used marking, and token revocation. |
| `GET /api/uploads/{id}/errors` | Frontend upload review calls it; Laravel route missing; row errors table exists. | Implement read-only validation errors endpoint scoped by company and upload. |
| `POST /api/alerts/{id}/dismiss-pattern` | Frontend calls it; Laravel supports alert status update only. | Replace frontend call with `PATCH /api/alerts/{id}` status update unless real pattern suppression is designed later. |
| `POST /api/alerts/{id}/create-case` | Frontend calls it; `POST /api/cases` already supports `alert_ids`. | Replace frontend call with `POST /api/cases` and alert payload. |
| `POST /api/transactions/{id}/create-case` | Frontend calls it; `POST /api/cases` already supports `transaction_ids`. | Replace frontend call with `POST /api/cases` and transaction payload. |
| `POST /api/reconciliation/run` | Frontend run button calls it; Laravel has read/review endpoints but no run endpoint. | Implement only if a deterministic reconciliation run module can be completed safely in this slice. Otherwise disable the button and show current-state/no-run guidance. No visible 404. |
| `GET /api/entity-graph` | Frontend page calls it; Laravel route missing; entity relationship risk service exists. | Implement read-only deterministic graph from company, vendors, users, transactions, and relationship-risk findings. |
| `GET /api/entity-graph/node/{id}` | Frontend drawer calls it; Laravel route missing. | Implement read-only node detail from the same graph module. |
| `GET /api/reports/summary` | Legacy reports page calls it; investigation report APIs already exist. | Replace legacy page behavior with investigation report flows or remove backend calls from the page. |
| `GET /api/reports/export` | Legacy reports page calls it; investigation export route already exists per investigation. | Replace with existing investigation report export flow. Do not add a fake global report export. |
| `GET /api/clients` | Accounting-firm UI calls it; no multi-client backend exists. | Hide route-dependent content or render an explicit unavailable state until multi-client support exists. |
| `POST /api/legal-escalations/from-alert/{id}` | Modal calls it; Laravel route missing; legal workflow is not implemented. | Remove backend call or route user to static legal intake with disclaimer. Do not persist a fake escalation. |
| `GET /api/cases/{id}/pdf` | Case detail page calls it; Laravel exposes investigation report generation, not case PDF jobs. | Replace with `POST /api/investigations/{id}/reports` where the case is an investigation workspace. |
| `GET /api/cases/{id}/pdf/status/{job}` | Case detail polls nonexistent case PDF job. | Remove polling and use the synchronous existing investigation report response pattern. |

## Design

### Product Route Decisions

Each route is classified before code changes:

- **Implement** when Laravel already has the underlying data model or service and the feature is part of the current product surface.
- **Replace** when an existing route already expresses the workflow with a better contract.
- **Hide or mark unavailable** when the frontend page is ahead of the backend product.

This avoids adding shallow Laravel controllers that only satisfy frontend URLs without real product behavior.

### Route Contract Gate

Add a small route-contract test module instead of the full process registry.

The module compares frontend API calls against Laravel routes:

- Extract frontend `apiFetch('/api/...')` string literals and direct authenticated `fetch(`${API_BASE_URL}/api/...`)` calls.
- Normalize dynamic segments such as `${id}`, `${sessionId}`, and encoded path values to `{param}`.
- Compare normalized frontend paths and HTTP methods against `php artisan route:list --json`.
- Allow explicit exceptions only when a frontend call is intentionally external, hidden behind an unavailable state, or pending a documented future product.

The gate should live as a release check, not a runtime dependency.

### Deterministic Backend Additions

Small backend modules may be added where the product exists:

- Auth password reset handlers in `AuthController` or a small auth service.
- Upload validation issue reader in `UploadService` and `UploadController`.
- Entity graph read model behind a dedicated Laravel service and controller.

These are deterministic user workflows, not Rex or LangGraph processes.

### Frontend Replacements

Replace stale frontend calls with existing backend workflows:

- Alert-to-case and transaction-to-case use `POST /api/cases`.
- Alert pattern dismissal uses existing alert status update until pattern suppression exists.
- Legacy case PDF job calls move to the investigation report export model where applicable.
- Legacy global report endpoints are removed or redirected to investigation exports.

### Unavailable Product Surfaces

For routes without a real backend product, the frontend should show intentional UI state:

- Client management: available only after multi-client/accounting-firm backend support.
- Legal escalation: link to static intake/disclaimer or remove the action.
- Reconciliation run: disable if deterministic run execution is not implemented in this slice.

The user should see clear next steps, not an API error.

## Data Flow

```text
Frontend route call
  -> route contract gate validates it exists or is intentionally unavailable
  -> Laravel route handles deterministic read/write workflow
  -> Laravel service owns facts, mutations, tenancy, and audit where applicable
  -> frontend renders success, no-data, or unavailable state
```

Rex and the agent service are not on the critical path for Phase 1 route drift.

## Error Handling

- Missing company context returns `403`.
- Missing resources return `404`.
- Unsupported workflow state returns `409` or a disabled frontend action.
- Password reset request always returns a generic success message to avoid account enumeration.
- Newly added routes must avoid leaking raw exception details.

## Test Plan

Backend tests:

- Password reset request and reset success/failure cases.
- Upload row errors are company-scoped and tied to the current validation run.
- Entity graph list and node detail are company-scoped.
- Any implemented reconciliation run behavior is covered by no-data, success, and authorization tests.

Frontend tests:

- Replaced alert create-case and transaction create-case calls use `POST /api/cases`.
- Removed legacy reports and case PDF calls no longer execute missing endpoints.
- Client/legal/unavailable states do not call missing routes.
- Upload validation review displays row errors from the new endpoint.

Contract tests:

- Frontend API literals must match Laravel route-list output or an explicit allowlist entry.
- The allowlist must include a reason and expiry/removal note.

## Release Gate

Phase 1 is complete when:

1. The route-contract gate passes.
2. `rg` finds no unapproved frontend calls to missing Laravel API routes.
3. Product tests cover each newly implemented route.
4. Frontend tests cover each replaced or hidden route-dependent UI.
5. The production readiness tracker marks the route-drift finding resolved with command output.

## Follow-On Phases

After Phase 1:

1. Build the canonical process registry for Rex routing, agent tool advertisement, capability display, and process readiness classes.
2. Wire `agent_action_approvals` into approve/reject/execute flows with audit events.
3. Expand LangGraph only with high-value process nodes such as `risk_review`, `transaction_lookup`, `dashboard_health`, `recommendation_review`, and possibly `investigation_synthesis`.
4. Add route parity, agent tool parity, and Rex artifact/approval contract checks to CI.
