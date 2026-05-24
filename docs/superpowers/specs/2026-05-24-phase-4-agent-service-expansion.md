# Agent Service Expansion Design

Date: 2026-05-24
Status: Approved for implementation planning

## Purpose

Phase 4 carefully expands the brevixai-agents service to close three gaps left after Phase 3.

First, the agent still hardcodes its approval gate. The Laravel process registry (Phase 2) is the authoritative source for which action types require approval, but the agent reads `ORCHESTRATOR_APPROVAL_REQUIRED_TOOLS` from the environment and never consults it. That divergence means an action type added to the registry is not automatically reflected in the agent's gating logic.

Second, two intents the router already classifies — `transaction_lookup` and `dashboard_health` — have no real node. Both fall through `fraud_analyzer_node` silently with a skip guard. Users who ask about a specific transaction or the dashboard state get generic fraud-analysis output, not the data they asked for.

Third, tool errors accumulate in `errors` state and disappear. Callers cannot tell whether `tool_results` is complete or partial. Partial data should be visible.

The principle stays unchanged:

```text
Agent reads. Agent recommends. User approves. Laravel executes.
```

Phase 4 does not add any writes to the agent.

## Scope Decision

Phase 4 should expand the agent graph precisely, without touching the approval execution path that Phase 3 owns.

In scope:

1. Replace hardcoded approval gating with registry-derived metadata from Laravel.
2. Add conditional routing from `context_loader` by intent.
3. Implement `transaction_lookup_node` as a thin, read-only tool-call node.
4. Implement `dashboard_health_node` as a thin, read-only tool-call node.
5. Implement `recommendation_review_node` as a read-only node that surfaces pending recommendations for all intents.
6. Add `degraded_tools: list[str]` to `BrevixAgentState` and expose it in `AgentRunResponse`.
7. Forward `agent_run_id` as `X-Agent-Run-Id` on every `LaravelToolClient` request.

## Current State

Relevant files and their roles as of Phase 3:

- `app/graph.py` — `build_graph()` constructs a linear 7-node `StateGraph(BrevixAgentState)` with no conditional edges.
- `app/models.py` — `BrevixAgentState` TypedDict holds `intent`, `tool_results`, `findings`, `errors`, `steps`, and related fields.
- `app/tools/laravel.py` — `LaravelToolClient` has six methods: `company_context`, `risk_summary`, `vendor_risk`, `reconciliation_risk`, `entity_relationship_risk`, `aggregate_risk_summary`. All are GET calls via `httpx.AsyncClient`. None forward a trace header.
- `app/config.py` — `Settings` exposes `approval_required_tools_list` parsed from the `ORCHESTRATOR_APPROVAL_REQUIRED_TOOLS` env var. This is the only source of approval gating metadata.
- `action_gate_node()` in `graph.py` — reads `settings.approval_required_tools_list` directly. Hardcoded.
- `fraud_analyzer_node()` in `graph.py` — skips silently when intent is `dashboard_health`, `transaction_lookup`, or `unknown_or_unsupported`. No tool call fires for those intents.
- Graph version: `BREVIX_AGENT_GRAPH_VERSION` currently set to `phase-2-observability-v1`.

## Goals

1. Replace `settings.approval_required_tools_list` in `action_gate_node` with registry-derived approval metadata fetched from Laravel.
2. Add a `route_by_intent()` conditional edge function so each intent flows to a matching node.
3. Implement `transaction_lookup_node` — calls a new `LaravelToolClient.transaction_detail()` method.
4. Implement `dashboard_health_node` — calls `LaravelToolClient.company_context()` with a dashboard-focused parameter.
5. Implement `recommendation_review_node` — calls a new `LaravelToolClient.pending_recommendations()` method; runs for all intents; never mutates.
6. Surface `degraded_tools` in `BrevixAgentState` and `AgentRunResponse` so partial-data runs are visible to callers.
7. Set `X-Agent-Run-Id` header in `LaravelToolClient._get()` whenever `agent_run_id` is present in state.
8. Bump graph version to `phase-4-expansion-v1`.

## Non-Goals

- Do not add any autonomous writes from new nodes.
- Do not change `action_gate_node` execution behavior — the executor is Laravel's job after Phase 3.
- Do not add LLM calls to new nodes in this phase; all new nodes are deterministic.
- Do not expand `AgentActionExecutorService` action types.
- Do not break existing `fraud_pattern_search` or `reconciliation_review` paths.
- Do not add new prompt template files in this phase.
- Do not overhaul `investigation_synthesis` — it runs on whatever `tool_results` exist.
- Do not add Phase 5 CI parity gates.

## New Graph Shape

Current shape (linear, no conditional edges):

```text
START
  → router
  → context_loader
  → fraud_analyzer
  → investigation_synthesis
  → explanation
  → action_gate
  → final_response
  → END
```

Phase 4 shape (conditional by intent):

```text
START
  → router
  → context_loader
       ↓ route_by_intent()
       │ fraud_pattern_search / reconciliation_review
       │   → fraud_analyzer → investigation_synthesis → explanation
       │ transaction_lookup
       │   → transaction_lookup → explanation
       │ dashboard_health
       │   → dashboard_health → explanation
       │ unknown_or_unsupported
       │   → explanation (pass-through)
       ↓ (all paths merge)
  → recommendation_review
  → action_gate
  → final_response
  → END
```

The conditional edge function `route_by_intent()` lives in `app/graph.py` and maps `state["intent"]` to the appropriate next node name. `investigation_synthesis` runs only on the fraud/reconciliation path because it correlates multi-domain risk signals — it has no meaningful input for single-tool intent paths.

## New LaravelToolClient Methods

Three new methods added to `LaravelToolClient` in `app/tools/laravel.py`:

```python
async def transaction_detail(
    self,
    transaction_ids: list[str],
    agent_run_id: str | None = None,
) -> dict[str, Any]:
    ...

async def pending_recommendations(
    self,
    company_id: str,
    agent_run_id: str | None = None,
) -> dict[str, Any]:
    ...

async def process_registry(
    self,
    agent_run_id: str | None = None,
) -> dict[str, Any]:
    ...
```

All three call `_get()` with the `X-Agent-Run-Id` header set when `agent_run_id` is not `None`. Existing methods receive the same header update.

Laravel endpoint paths are assumed to follow the existing tool key authentication pattern. Exact paths are defined when the Laravel-side tool routes are specified.

## Trace ID Propagation

`LaravelToolClient._get()` currently has no trace header.

Phase 4 change: `_get()` accepts an optional `agent_run_id: str | None = None` parameter and sets `X-Agent-Run-Id: {agent_run_id}` in the request headers when present.

All public methods on `LaravelToolClient` accept and forward `agent_run_id`.

Nodes that call tools extract `state.get("agent_run_id")` and pass it explicitly. This keeps the HTTP client stateless and avoids thread-local state.

## Registry Integration

`process_registry()` calls the Laravel process registry endpoint (established in Phase 2) and returns a mapping of action types to their approval metadata, for example:

```json
{
  "create_alert": { "requires_approval": true, "display_name": "Create Alert" },
  "review_dashboard": { "requires_approval": false, "display_name": "Review Dashboard" }
}
```

`action_gate_node` replaces its call to `settings.approval_required_tools_list` with a registry-derived lookup. The node receives the registry as an injected argument from `build_graph()` or fetches it during graph initialization.

Fallback behavior: if `process_registry()` raises `LaravelToolError` or times out, `action_gate_node` falls back to `settings.approval_required_tools_list` and appends `"process_registry"` to `state["degraded_tools"]`. The run continues. The fallback is logged as a structured warning with `agent_run_id`.

Registry fetch timing: fetched once during `build_graph()` at service startup and cached in the compiled graph closure. A per-request refresh is not required for Phase 4 but can be added in Phase 5 if registry contents change frequently.

## State Schema Changes

Add to `BrevixAgentState` in `app/models.py`:

```python
degraded_tools: list[str]
```

This field accumulates the names of tools that raised `LaravelToolError` or timed out during a run. It is additive across nodes using the existing `Annotated[list, add]` pattern already used for `steps`.

Add to `AgentRunResponse` in `app/models.py`:

```python
degraded_tools: list[str]
```

Callers — including `BrevixAgentRunner` in Laravel — can inspect this field to display a partial-data warning or log the degraded state for alerting.

## Degraded-Tool Reporting

When any tool call in any node raises `LaravelToolError` or `asyncio.TimeoutError`:

1. Append the tool method name (e.g., `"transaction_detail"`, `"pending_recommendations"`) to `state["degraded_tools"]`.
2. Do not re-raise. Continue graph execution with whatever partial data is available.
3. Log a structured warning at `WARNING` level with keys: `agent_run_id`, `tool_name`, `error_type`, `error_message`.

`final_response_node` logs the full `degraded_tools` list at run completion. `AgentRunResponse` includes it so the API caller can surface the degraded state.

This replaces the current pattern of appending human-readable strings to `state["errors"]` for tool failures. Tool errors go to `degraded_tools`; `errors` is reserved for graph-level or validation failures.

## New Node Specifications

### `transaction_lookup_node`

- **When it runs:** Only when `route_by_intent()` returns `"transaction_lookup"`.
- **Tool call:** `LaravelToolClient.transaction_detail(transaction_ids, agent_run_id)`
- **Input extraction:** `transaction_ids` are pulled from `state["page_context"]` first (e.g., `page_context["transaction_ids"]`), then parsed from `state["user_message"]` as a fallback using a simple UUID regex. If no IDs are found, the node sets `tool_results["transaction_detail"] = {"error": "no_transaction_ids"}` and continues.
- **State output:** Sets `tool_results["transaction_detail"]`, appends a step record to `steps`.
- **On failure:** Appends `"transaction_detail"` to `degraded_tools`.
- **No findings extraction:** The node does not populate `findings`. `explanation_node` summarizes `tool_results["transaction_detail"]` directly.

### `dashboard_health_node`

- **When it runs:** Only when `route_by_intent()` returns `"dashboard_health"`.
- **Tool call:** Reuses `LaravelToolClient.company_context(agent_run_id=agent_run_id)` with a dashboard-focused query parameter or path variant agreed with the Laravel tool route owner.
- **State output:** Sets `tool_results["dashboard_health"]`, appends a step record to `steps`.
- **On failure:** Appends `"company_context"` to `degraded_tools`.
- **No findings extraction:** `explanation_node` summarizes `tool_results["dashboard_health"]` directly.

### `recommendation_review_node`

- **When it runs:** All intents — placed after the intent-specific analysis nodes merge, before `action_gate`.
- **Tool call:** `LaravelToolClient.pending_recommendations(company_id, agent_run_id)`
- **State output:** Sets `tool_results["pending_recommendations"]`, appends a step record to `steps`.
- **Read-only contract:** The node does not create, approve, reject, or mutate any recommendation. It only reads. No `recommended_actions` entry is generated from recommendation review data in Phase 4.
- **On failure:** Appends `"pending_recommendations"` to `degraded_tools`. Non-blocking — the rest of the run continues normally.

## Approval State Machine Impact

No change to the approval state machine. Phase 3 owns `pending → approved`, `pending → rejected`, `pending → failed`.

The only Phase 4 change to approval gating is the source of truth: registry-derived instead of env-var-derived. The transition rules, executor, and audit trail are unchanged.

## Safety Rules

- New nodes are read-only. No `INSERT`, `UPDATE`, or `DELETE` side effects.
- Registry fetch failure falls back to config and marks `process_registry` as degraded; it never aborts a run.
- `agent_run_id` is forwarded as a header, not in the request body, so it cannot alter payload semantics.
- `recommendation_review_node` may not call any approve, reject, or execute endpoint.
- Degraded tools are always reported in state. Callers are never left inferring completeness from absence.
- `route_by_intent()` must have an explicit mapping for every intent value the router can emit. An unrecognized intent falls to `"explanation"` (pass-through) and appends `"unknown_intent"` to `degraded_tools`.

## Data Flow

```text
POST /agent/run
  → validate AgentRunRequest
  → invoke compiled graph with BrevixAgentState
  → router_node: classify intent
  → context_loader_node: fetch company_context (X-Agent-Run-Id set)
  → route_by_intent(): select analysis node
      fraud_pattern_search / reconciliation_review
        → fraud_analyzer_node (tool calls: risk_summary, vendor_risk, etc.)
        → investigation_synthesis_node
        → explanation_node (LLM or deterministic)
      transaction_lookup
        → transaction_lookup_node (tool call: transaction_detail)
        → explanation_node
      dashboard_health
        → dashboard_health_node (tool call: company_context dashboard variant)
        → explanation_node
      unknown_or_unsupported
        → explanation_node (pass-through)
  → recommendation_review_node (tool call: pending_recommendations, all paths)
  → action_gate_node (registry-derived approval gating)
  → final_response_node (logs degraded_tools)
  → AgentRunResponse (includes degraded_tools)
```

## Test Plan

### Unit Tests

- `route_by_intent()` maps each intent value to the correct node name.
- `route_by_intent()` falls to `"explanation"` and logs degraded for unrecognized intent.
- `transaction_lookup_node` skips tool call when intent is not `transaction_lookup`.
- `dashboard_health_node` skips tool call when intent is not `dashboard_health`.
- `recommendation_review_node` fires for all intents.
- Registry fetch failure in `action_gate_node` falls back to `approval_required_tools_list` and populates `degraded_tools`.
- `LaravelToolClient._get()` sets `X-Agent-Run-Id` header when `agent_run_id` is not `None`.
- `LaravelToolClient._get()` omits the header when `agent_run_id` is `None`.
- `transaction_lookup_node` sets `degraded_tools` on `LaravelToolError`.
- `recommendation_review_node` is non-blocking on `LaravelToolError`.
- `AgentRunResponse.degraded_tools` matches the accumulated list from state.

### Integration Tests

- Full `fraud_pattern_search` run: findings populated, synthesis runs, `transaction_lookup_node` does not fire.
- Full `reconciliation_review` run: reconciliation risk tool fires, synthesis runs.
- Full `transaction_lookup` run: `transaction_detail` tool fires, `fraud_analyzer_node` does not fire.
- Full `dashboard_health` run: dashboard tool fires, `fraud_analyzer_node` does not fire.
- All runs: `recommendation_review_node` fires and `tool_results["pending_recommendations"]` is set.
- Registry-derived gate matches Phase 3 executor `supportedActionTypes()`.

### Contract Tests

- Every intent in `router_node` intent list has an explicit branch in `route_by_intent()`.
- `degraded_tools` field names in state match method names in `LaravelToolClient`.
- `AgentRunResponse.degraded_tools` is present and typed in OpenAPI schema.
- `process_registry()` response shape matches what `action_gate_node` consumes.

## Release Gate

Phase 4 is complete when:

1. `route_by_intent()` conditional edge is implemented in `app/graph.py` and unit tested for all five intents.
2. `transaction_lookup_node` and `dashboard_health_node` each call a real `LaravelToolClient` method and have integration test coverage.
3. `recommendation_review_node` fires for all intents, is read-only, and is non-blocking on failure.
4. Registry-derived approval gating is the primary source in `action_gate_node`; env-var fallback is present and tested.
5. `X-Agent-Run-Id` header appears in structured logs for all tool calls on runs with a non-null `agent_run_id`.
6. `degraded_tools` is present in `BrevixAgentState`, accumulated correctly, and surfaced in `AgentRunResponse`.
7. All existing Phase 2/3 agent tests pass without modification.
8. `BREVIX_AGENT_GRAPH_VERSION` is updated to `phase-4-expansion-v1`.

## Follow-On Phases

Phase 5 should add CI parity gates:

- Every intent value in the router has a passing integration test.
- Every action type persisted by `BrevixAgentRunner` has a matching entry in the process registry.
- Every registry action type is either supported by the Phase 3 executor or explicitly blocked.
- `AgentRunResponse` schema is contract-tested against Laravel's `BrevixAgentRunner` consumer.

Future work beyond Phase 5:

- `recommendation_review_node` mutation path if a user approves a recommendation through an agent flow.
- LLM-backed router replacing keyword matching in `router_node`.
- `investigation_synthesis` expansion to incorporate `transaction_detail` signals.
- Per-request registry refresh if registry contents change at a cadence that makes startup caching stale.
