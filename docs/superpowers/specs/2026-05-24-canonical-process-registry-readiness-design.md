# Canonical Process Registry Readiness Design

Date: 2026-05-24
Status: Approved for implementation planning

## Purpose

Phase 2 creates a Laravel-owned canonical process registry for Rex routing, deterministic process execution, agent tool advertisement, and frontend capability display.

The goal is not to make every product workflow agentic. The goal is to make Laravel the single source of truth for which processes exist, how they may be used, which routes back them, which artifact contract they return, and whether Rex or the agent service may call them.

## Scope Decision

Phase 2 should be registry-first with limited consumers:

1. Create the registry module.
2. Move Rex route metadata and agent tool metadata into the registry.
3. Make existing Rex/router/tool code read from the registry.
4. Add parity tests so route, Rex, and agent-tool contracts cannot drift.

Approval execution and LangGraph graph expansion remain later phases.

## Current Context

Current production control plane:

```text
brevixai frontend
  -> brevixai-api Laravel chat gateway
  -> deterministic Rex router when route is obvious
  -> RexOrchestratorService for read-only product lookups
  -> or brevixai-agents FastAPI/LangGraph for risk-review workflows
  -> Laravel internal agent-tool endpoints
```

Current friction:

- `RexOrchestratorService::supportedRoutes()` hard-codes Rex lookup routes.
- `RexChatRouterService` builds router prompts from that hard-coded list.
- `BrevixAgentRunner::optionalDeterministicTools()` hard-codes internal tool metadata and path strings.
- `docs/agent-process-readiness.md` tracks process readiness manually.
- Frontend capability display has no canonical backend source for what Rex/process features are available.

These modules are shallow in the architectural sense: each caller has to know too much about process keys, route names, tool paths, and safety policy. A registry module gives better locality and leverage by concentrating that interface in one place.

## Goals

1. Define one canonical Laravel interface for process metadata.
2. Remove duplicate Rex route and agent tool definitions from separate modules.
3. Classify every product surface as read-only Rex process, agent-risk process, user-only workflow, or unavailable.
4. Keep deterministic Laravel services as the source of facts and mutations.
5. Expose safe capability metadata to the frontend.
6. Add contract tests for registry-to-route, Rex-to-registry, and agent-tool-to-registry parity.

## Non-Goals

- Do not execute `agent_action_approvals` in Phase 2.
- Do not add new LangGraph graph nodes in Phase 2.
- Do not add autonomous mutations.
- Do not move every existing Laravel service behind a new executor abstraction in one pass.
- Do not advertise uploads, integrations, auth, subscriptions, onboarding, or personal finance as agent tools.
- Do not replace Phase 1 route-contract gates; the process registry complements them.

## Readiness Classes

Each process must declare one readiness class:

| Class | Meaning | Rex | Agent service | Examples |
| --- | --- | --- | --- | --- |
| `read_only_rex` | Deterministic product lookup, no mutation. | Can route directly. | Not advertised unless needed as evidence. | dashboard, analytics, alerts, AR, transactions, controls |
| `agent_risk` | Deterministic risk or recommendation process that may help agent reasoning. | May route to controlled agent workflow. | Can be advertised as an approved tool. | vendor risk, reconciliation risk, entity relationship risk, aggregate risk, alert/case recommendations |
| `user_only` | Workflow must be initiated and completed by the user. | Can explain/navigate only. | Not advertised as a tool. | uploads, integrations, auth, subscriptions, onboarding, personal finance |
| `unavailable` | Frontend/product idea exists but backend process is not ready. | Must not route as executable. | Not advertised. | future multi-client, legal escalation, unsupported workflow |

## Process Metadata Contract

Each registry entry should expose:

- `key`: stable process key.
- `label`: human-readable display name.
- `readiness_class`: one of the classes above.
- `rex_mode`: `orchestrator`, `agent`, `direct_guidance`, or `none`.
- `executor`: Laravel adapter or callable key for deterministic execution, if available.
- `route`: public Laravel route metadata if frontend/user callable.
- `internal_tool`: internal agent-tool metadata if agent callable.
- `required_tier`: minimum subscription tier, if applicable.
- `required_permission`: role or permission, if applicable.
- `requires_human_approval`: true for any process that can lead to sensitive writes.
- `artifact_type`: structured artifact type returned to Rex/frontend.
- `is_mutating`: whether the process itself mutates data.
- `test_expectation`: minimum test coverage label for the process.
- `status`: `ready`, `partial`, `blocked`, or `deprecated`.
- `notes`: short implementation note for operators and future maintainers.

This metadata is the interface. Existing service code remains the implementation.

## Initial Process Catalog

### Read-Only Rex Processes

| Key | Executor | Artifact type | Backing route |
| --- | --- | --- | --- |
| `dashboard` | dashboard summary lookup | `dashboard_summary` | `/api/dashboard/summary` |
| `analytics` | analytics summary/vendors/cash flow lookup | `analytics_summary` | `/api/analytics/*` |
| `alerts` | open alerts lookup | `alert_list` | `/api/alerts` |
| `reconciliation` | reconciliation summary/discrepancy lookup | `reconciliation_summary` | `/api/reconciliation/*` |
| `ar` | AR aging summary lookup | `ar_aging_summary` | `/api/ar-aging/summary` |
| `vendors` | top vendor lookup | `vendor_risk_list` | `/api/analytics/vendors` |
| `cases` | open case lookup | `case_list` | `/api/cases` |
| `controls` | controls health lookup | `controls_health` | `/api/controls/health` |
| `transactions` | recent transaction lookup | `transaction_list` | `/api/transactions` |

### Agent-Risk Processes

| Key | Internal tool | Artifact type | Approval |
| --- | --- | --- | --- |
| `risk_review` | graph workflow request, not a direct tool | `agent_findings` | recommendations require approval |
| `company_context` | `/api/internal/agent-tools/companies/{companyId}/context` | `company_context` | no |
| `risk_summary` | `/api/internal/agent-tools/companies/{companyId}/risk-summary` | `risk_summary` | no |
| `vendor_risk` | `/api/internal/agent-tools/company/{companyId}/vendor-risk` | `vendor_risk` | no |
| `reconciliation_risk` | `/api/internal/agent-tools/company/{companyId}/reconciliation-risk` | `reconciliation_risk` | no |
| `entity_relationship_risk` | `/api/internal/agent-tools/company/{companyId}/entity-relationship-risk` | `entity_relationship_risk` | no |
| `aggregate_risk_summary` | `/api/internal/agent-tools/company/{companyId}/aggregate-risk-summary` | `aggregate_risk_summary` | no |
| `alert_recommendations` | `/api/internal/agent-tools/company/{companyId}/alert-recommendations` | `alert_recommendation_list` | approval required before alert creation |
| `case_recommendations` | `/api/internal/agent-tools/company/{companyId}/case-recommendations` | `case_recommendation_list` | approval required before case creation |

### User-Only Workflows

| Key | Reason |
| --- | --- |
| `uploads` | File import must remain a deterministic user workflow. Rex can explain status or next steps only. |
| `integrations` | OAuth and sync control must remain user initiated. |
| `auth` | Authentication and account recovery are user-only. |
| `subscriptions` | Billing changes are user-only. |
| `onboarding` | Product setup guidance, not an agent process. |
| `personal_finance` | Gated local/admin workflow, not in production Rex scope. |

## Module Design

### Registry Module

Add a deep module under a namespace such as:

```text
App\Services\Processes\ProcessRegistry
App\Services\Processes\ProcessDefinition
App\Services\Processes\ProcessExecutor
App\Services\Processes\Adapters\*
```

Recommended interface:

```php
all(): array
find(string $key): ?ProcessDefinition
rexRoutes(): array
orchestratorRoutes(): array
agentTools(string $companyId): array
capabilitiesFor(User $user): array
assertRouteBacked(): array
```

The first implementation can keep process definitions in PHP arrays or immutable value objects. Do not add a database table until there is a real runtime editing need.

### Executor Adapters

For Phase 2, executor adapters should be thin wrappers around existing Laravel services. The goal is locality of process metadata, not a broad rewrite.

Example adapters:

- `DashboardProcessAdapter`
- `AnalyticsProcessAdapter`
- `AlertsProcessAdapter`
- `ReconciliationProcessAdapter`
- `RecommendationsProcessAdapter`

If creating adapters would make Phase 2 too large, keep existing `RexOrchestratorService` execution methods and use the registry only for metadata in the first slice. The registry should still own route/tool availability.

### Rex Router Integration

`RexChatRouterService` should use registry-derived routes for:

- deterministic keyword route eligibility
- LLM router prompt valid routes
- validation of LLM routing decisions
- fallback decisions

The existing keyword matching can remain initially, but matched route keys must be registry keys.

### Rex Orchestrator Integration

`RexOrchestratorService::supportedRoutes()` should become registry-backed.

`handleRoute()` can initially dispatch to existing private methods, but it should reject any route not classified as `read_only_rex` or otherwise explicitly Rex-orchestrator eligible.

This prevents user-only workflows from becoming Rex-executable by accident.

### Agent Tool Advertisement

`BrevixAgentRunner::optionalDeterministicTools()` should be replaced by registry-derived metadata.

The registry should generate the same shape currently sent to the agent service:

- method
- path with `companyId`
- optional
- deterministic
- purpose
- authority fields
- user-context-header requirement

The registry should also own `tool_policy` or at least expose the process-derived policy values used by `BrevixAgentRunner`.

### Capability Endpoint

Add an authenticated endpoint such as:

```text
GET /api/processes/capabilities
```

The endpoint returns frontend-safe metadata only:

- key
- label
- readiness class
- status
- rex availability
- required tier
- required permission
- artifact type
- unavailable reason, when relevant

It must not expose internal agent service secrets, database details, or privileged internal route headers.

## Data Flow

```text
ProcessRegistry
  -> RexChatRouterService valid routes and deterministic eligibility
  -> RexOrchestratorService supported routes and artifact contracts
  -> BrevixAgentRunner internal deterministic tool advertisement
  -> /api/processes/capabilities frontend capability display
  -> parity tests for Laravel routes and internal agent tools
```

Laravel remains the data authority. The registry describes process availability; existing services still compute facts and perform approved deterministic workflows.

## Safety Rules

- `user_only` and `unavailable` processes must never appear in `agentTools()`.
- `is_mutating=true` processes must require human approval unless explicitly blocked from Rex/agent use.
- Agent tools must use only `/api/internal/agent-tools/*`.
- Agent tools must require the existing agent-tool middleware and user context header.
- Rex orchestrator routes must be read-only.
- A process cannot be marked `ready` if its backing route does not exist.

## Error Handling

- Unknown process key returns `404` from capability/process lookup endpoints.
- Registry route parity failures are test failures, not runtime failures.
- If a process is blocked by tier or permission, capabilities should expose unavailable status with a safe reason.
- If a registry-backed executor fails, preserve existing safe API error behavior and structured logs.

## Test Plan

### Unit Tests

- Registry contains all expected initial process keys.
- `read_only_rex` entries appear in Rex route lists.
- `user_only` and `unavailable` entries do not appear in agent tool metadata.
- Approval-required recommendation processes cannot be marked autonomous.
- Generated agent tool paths include the requested company ID and no database credentials.

### Feature Tests

- `GET /api/processes/capabilities` is authenticated and company scoped.
- Capabilities output omits internal secrets and unsafe route metadata.
- Rex router valid routes are registry-derived.
- `BrevixAgentRunner` sends registry-derived deterministic tools with the same external contract expected by the agent service.

### Contract Tests

- Every registry public route exists in `php artisan route:list --json`.
- Every registry internal tool route exists in `php artisan route:list --json`.
- Every advertised agent tool maps to a registry `agent_risk` process.
- Every Rex orchestrator route maps to a registry `read_only_rex` process.
- The process catalog and docs stay aligned, either by snapshot test or by generated documentation check.

## Migration Strategy

1. Add registry definitions without changing runtime behavior.
2. Add parity tests against current hard-coded Rex and agent-tool metadata.
3. Switch `RexOrchestratorService::supportedRoutes()` to registry.
4. Switch `RexChatRouterService` valid route prompt and validation to registry.
5. Switch `BrevixAgentRunner` tool advertisement to registry.
6. Add capability endpoint.
7. Update `docs/agent-process-readiness.md` to point to the registry as the source of truth.

This order keeps behavior stable while moving one consumer at a time.

## Release Gate

Phase 2 is complete when:

1. Registry includes the initial read-only, agent-risk, user-only, and unavailable process classes.
2. Rex route lists are registry-backed.
3. Agent tool advertisement is registry-backed.
4. Capability endpoint returns safe frontend metadata.
5. Route parity and agent tool parity tests pass.
6. Existing Rex and agent chat feature tests pass without contract regressions.
7. `docs/agent-process-readiness.md` no longer acts as the manual source of truth.

## Follow-On Phases

Phase 3 should wire `agent_action_approvals` into real approve/reject/execute flows with audit events.

Phase 4 should teach the LangGraph service to consume the process/tool registry and add only high-value graph nodes such as:

- `risk_review`
- `transaction_lookup`
- `dashboard_health`
- `recommendation_review`
- possibly `investigation_synthesis`

Phase 5 should add CI gates for route parity, agent tool parity, Rex artifact contract stability, and approval contract stability.
