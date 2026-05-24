# Canonical Process Registry Design

Date: 2026-05-24
Status: Approved for implementation planning

## Purpose

Phase 2 closes the gap between what Rex can route and what the product can actually execute. Phase 1 resolved frontend/backend route drift. Phase 2 resolves the gap between the agent's capability advertisements and the backend's ability to execute, approve, and audit them.

The goal is a single source of truth for: what processes Rex can run, what tools each process advertises, what actions require approval, and whether the approval pipeline can actually execute them. No process should be advertised to the agent or the frontend if it cannot be completed end-to-end.

## Scope Decision

Phase 2 builds three things in a single slice:

1. **Process registry** — a PHP enum/config layer that names every Rex process, maps it to a mode and tool surface, and marks its readiness state.
2. **Approval execution pipeline** — wiring `agent_action_approvals` from stored-pending to approve/reject/execute with audit events.
3. **LangGraph node expansion** — adding new high-value process nodes backed by existing deterministic services.

CI parity gates verify tool advertisement, approval contract, and process readiness on every merge.

## Current State

The current system has three hard-coded routing modes and one hard-coded agent process:

- **Router** (`RexChatRouterService`) — keyword-first, LLM-fallback. Returns `{mode, route, requested_action, reason}`. Uses a dedicated router model (`LLM_ROUTER_MODEL`) separate from the main agent model.
- **Orchestrator** (`RexOrchestratorService`) — 12 deterministic routes: `dashboard`, `analytics`, `alerts`, `suspicious`, `reconciliation`, `ar`, `vendors`, `cases`, `alert_recommendations`, `case_recommendations`, `controls`, `transactions`.
- **Agent** (`BrevixAgentRunner`) — one supported agent process: `risk_review`. Calls the external `brevixai-agents` LangGraph service with 8 optional deterministic tool advertisements.

Current gaps:

- `risk_review` is the only named agent process. There is no registry; the name lives as a string literal.
- `agent_action_approvals` are stored as `pending` but never executed. Approve and reject endpoints do not exist.
- The 8 advertised tools have no formal contract. If an endpoint changes, the agent's tool advertisement silently drifts.
- The orchestrator's 12 routes are un-versioned and have no readiness state. A route with missing data returns a silently empty artifact.
- The LLM provider layer is being generalized (`LLM_PROVIDER`, `LLM_BASE_URL`, `LLM_MODEL`, `LLM_ROUTER_MODEL`, `LLM_TIMEOUT`). The process registry must be provider-agnostic.

## Goals

1. Every Rex process has a named entry in the registry with a readiness class: `available`, `preview`, or `unavailable`.
2. The agent is only advertised tools that have a valid, tested Laravel endpoint backing them.
3. `agent_action_approvals` can be approved, rejected, and executed by an authenticated user with audit events.
4. New LangGraph nodes expand Rex's coverage of high-value workflows.
5. A CI gate verifies tool advertisement parity, approval contract shape, and process readiness on every merge.

## Non-Goals

- Do not replace the external `brevixai-agents` LangGraph service. The registry lives in Laravel; execution still flows through the agent service.
- Do not build a visual workflow editor or a DSL for process definitions.
- Do not implement multi-step approval chains, parallel approvals, or escalations.
- Do not auto-execute approved actions. Human confirmation is always required before execution.
- Do not expand the registry beyond processes that have a deterministic backend service today.

## Process Registry Design

### Process Definition

Each process is a named entry in a PHP enum. The enum carries:

- **key** — the string sent as `requested_action` to the agent service.
- **mode** — `agent` (runs through LangGraph), `orchestrator` (deterministic route), or `hybrid` (orchestrator first, agent synthesis second).
- **tools** — array of tool keys advertised to the agent for this process.
- **readiness** — `available`, `preview`, or `unavailable`.
- **approvalTypes** — action types the process may produce that require user approval.

```php
enum RexProcess: string
{
    case RiskReview              = 'risk_review';
    case TransactionLookup       = 'transaction_lookup';
    case DashboardHealth         = 'dashboard_health';
    case RecommendationReview    = 'recommendation_review';
    case InvestigationSynthesis  = 'investigation_synthesis';

    public function mode(): string { ... }
    public function tools(): array { ... }
    public function readiness(): ProcessReadiness { ... }
    public function approvalTypes(): array { ... }
}

enum ProcessReadiness: string
{
    case Available   = 'available';
    case Preview     = 'preview';
    case Unavailable = 'unavailable';
}
```

The router and agent runner consult the registry before routing:

- If a requested process is `unavailable`, the router falls back to `direct` mode.
- The runner only passes `tools` that the process registry declares for that process.

### Tool Registry

Each tool entry formalizes one Laravel endpoint that the agent may call:

| Tool key | Method | Laravel endpoint | Process(es) |
| --- | --- | --- | --- |
| `company_context` | GET | `/api/internal/agent-tools/companies/{id}/context` | all |
| `risk_summary` | GET | `/api/internal/agent-tools/companies/{id}/risk-summary` | `risk_review`, `dashboard_health` |
| `vendor_risk` | GET | `/api/internal/agent-tools/company/{id}/vendor-risk` | `risk_review` |
| `reconciliation_risk` | GET | `/api/internal/agent-tools/company/{id}/reconciliation-risk` | `risk_review` |
| `entity_relationship_risk` | GET | `/api/internal/agent-tools/company/{id}/entity-relationship-risk` | `risk_review` |
| `aggregate_risk_summary` | GET | `/api/internal/agent-tools/company/{id}/aggregate-risk-summary` | `risk_review`, `dashboard_health` |
| `alert_recommendations` | GET | `/api/internal/agent-tools/company/{id}/alert-recommendations` | `recommendation_review`, `risk_review` |
| `case_recommendations` | GET | `/api/internal/agent-tools/company/{id}/case-recommendations` | `recommendation_review`, `risk_review` |
| `transaction_lookup` | GET | `/api/internal/agent-tools/company/{id}/transactions` | `transaction_lookup` |
| `dashboard_health` | GET | `/api/internal/agent-tools/company/{id}/dashboard` | `dashboard_health` |

New tools for the expanded processes require a new `AgentToolController` method and a test before the tool key is added to the registry. The CI gate rejects any tool key that has no matching Laravel route.

### Approval Execution Pipeline

The `agent_action_approvals` table already stores approved/pending/rejected state and the full `action_payload`. Phase 2 adds two authenticated endpoints and an executor service:

```
POST /api/agent-approvals/{id}/approve
POST /api/agent-approvals/{id}/reject
```

The executor (`AgentActionExecutorService`) dispatches on `action_type`:

| Action type | Executor | Side effects |
| --- | --- | --- |
| `create_alert` | `AlertService::create` | Creates alert, links to company, records audit event |

Execution contract:

- Only the user who owns the session (or a company admin) may approve or reject.
- `approved_by`, `approved_at`, `executed_at`, `failed_at`, and `error_message` are written in the executor.
- Execution is synchronous in Phase 2. A failed execution sets `failed_at` and `error_message` without re-trying.
- A rejected approval sets `rejected_by` and `rejected_at`. The action payload is retained for audit.
- Approving an already-approved or rejected action returns `409`.

### New LangGraph Process Nodes

Four new processes are added to the registry in Phase 2. Each requires a backend implementation before it can be marked `available`.

**`transaction_lookup`** (mode: `orchestrator`)
- Maps to the existing `RexOrchestratorService::transactions` route.
- Enriched with date-range and vendor filter parameters passed from the router.
- No agent service call needed; the orchestrator handles it deterministically.
- Tools: `company_context`, `transaction_lookup`.

**`dashboard_health`** (mode: `orchestrator`)
- Wraps `dashboard`, `alerts`, and `controls` orchestrator routes into a single health snapshot.
- No agent service call needed.
- Tools: `company_context`, `risk_summary`, `aggregate_risk_summary`, `dashboard_health`.

**`recommendation_review`** (mode: `agent`)
- Agent reviews pending alert and case recommendations for a company.
- Returns a synthesis with `review_recommendation` action type.
- Requires `review_recommendation` approval type added to the executor.
- Tools: `company_context`, `alert_recommendations`, `case_recommendations`.

**`investigation_synthesis`** (mode: `agent`)
- Agent synthesizes evidence, risk signals, and activity for an investigation workspace.
- Marked `preview` until the investigation evidence tool endpoint is added.
- Tools: `company_context`, `risk_summary`, `entity_relationship_risk`, `alert_recommendations`.

### LLM Provider Layer

The router uses `LLM_ROUTER_MODEL` (typically a smaller, faster model). The agent runner uses `LLM_MODEL`. `LLM_PROVIDER` and `LLM_BASE_URL` allow pointing at any OpenAI-compatible endpoint. `LLM_TIMEOUT` is forwarded to the agent client.

The process registry does not depend on a specific provider. If a provider change causes the router to return an unrecognized mode, the registry's `available`/`unavailable` readiness state is the fallback gate, not LLM output.

## Data Flow

```text
User message
  -> RexChatRouterService.route()
       -> deterministicDecision() or LLM router call
       -> returns {mode, requested_action}
  -> RexProcess::from(requested_action)
       -> readiness check: if unavailable → fallback to direct
  -> if orchestrator: RexOrchestratorService.handleRoute()
  -> if agent:
       BrevixAgentRunner.run()
         -> passes only tools declared by RexProcess::tools()
         -> agent service runs LangGraph with advertised tools
         -> returns steps, findings, recommended_actions
       -> persistSteps(), persistActionApprovals()
       -> frontend displays recommended_actions with approval UI
  -> user approves/rejects via POST /api/agent-approvals/{id}/approve|reject
  -> AgentActionExecutorService.execute()
       -> dispatches on action_type
       -> writes audit event
```

## Error Handling

- Unknown `requested_action` not in the registry defaults to `risk_review` or `direct` mode, not a 500.
- Executor failures set `failed_at` and return a 422 with a safe error message. The approval record is not retried automatically.
- Provider-level LLM failures in the router fall back to `fallbackDecision()`. No new failure path is introduced.
- An approval for an action with an unsupported `action_type` returns 422 before any mutation.

## Test Plan

Process registry:
- Each `RexProcess` enum case resolves its mode, tools, readiness, and approval types.
- An `unavailable` process routes to `direct` mode without calling the agent service.
- Tool keys in the registry all have a matching route in `AgentToolController`.

Approval execution:
- Approve endpoint sets `approved_by`, `approved_at`, `executed_at` and produces the correct side effect.
- Reject endpoint sets `rejected_by`, `rejected_at`, no side effect.
- Approve/reject returns `404` for cross-company approval attempts.
- Double-approve and double-reject return `409`.
- `failed_at` and `error_message` are written when execution throws.
- Unauthenticated approve/reject requests return `401`.

LangGraph expansion:
- `transaction_lookup` and `dashboard_health` return deterministic orchestrator artifacts without calling the agent service.
- `recommendation_review` is accepted by the agent runner as a valid `requested_action`.
- `investigation_synthesis` is refused at the readiness gate when marked `preview` and the user has not opted into preview.

CI parity gate:
- Every tool key in the registry has a matching route in `php artisan route:list`.
- Every approval type in the registry has a matching executor case.
- No process is `available` if any of its declared tools is absent from the tool contract test.

## Release Gate

Phase 2 is complete when:

1. `RexProcess` enum covers all five named processes with correct readiness states.
2. Approve and reject endpoints exist with full test coverage.
3. At least `create_alert` executes correctly end-to-end in tests.
4. `transaction_lookup` and `dashboard_health` route deterministically.
5. The CI tool-parity gate passes on the branch.
6. The production readiness tracker marks the approval-execution finding resolved.

## Follow-On Phases

After Phase 2:

1. Add route parity, tool parity, and approval contract checks to CI as blocking gates.
2. Expand the executor to handle `review_recommendation` action type.
3. Mark `investigation_synthesis` as `available` once the investigation evidence tool endpoint is implemented and tested.
4. Consider async approval execution for high-latency action types.
