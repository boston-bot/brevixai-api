# Agent Approval Execution Readiness Design

Date: 2026-05-24
Status: Approved for implementation planning

## Purpose

Phase 3 turns persisted `agent_action_approvals` into a real human approval execution flow.

The system already forces sensitive agent recommendations into pending approval records. Phase 3 closes the next gap: authenticated users must be able to approve or reject those records, Laravel must execute only supported action types, and every approval, rejection, execution, and failure must be auditable.

The principle stays unchanged:

```text
Agent recommends.
User approves or rejects.
Laravel executes deterministic side effects.
```

## Scope Decision

Phase 3 should implement a narrow approval execution pipeline, not a broad autonomous action framework.

Initial scope:

1. Approve/reject endpoints for `agent_action_approvals`.
2. A small executor module for explicitly supported action types.
3. End-to-end execution for `create_alert`.
4. Frontend Rex action UI uses persisted `approval_id` values instead of review-only local actions.
5. Tests cover authorization, conflict handling, unsupported action types, execution success, and execution failure.

`create_case` may be added after `create_alert` if the case payload contract is explicit and tested, but it should not block the first Phase 3 release.

## Current Context

Current relevant implementation:

- `AgentActionApproval` stores `agent_run_id`, `company_id`, `user_id`, `action_type`, `action_payload`, `status`, approval/rejection timestamps, execution timestamps, and failure metadata.
- `BrevixAgentRunner` persists pending approval records and forces recommended actions to `requires_approval=true`.
- Session chat can stream recommended actions back to the frontend with `approval_id`.
- `ChatService` still has legacy `RexPendingAction` confirm/reject handling for session-local actions.
- Standalone Rex has historically rendered persisted agent actions as review-only, so no real alert/case was created from an `approval_id`.

Phase 3 separates persisted agent approvals from legacy local pending actions.

## Goals

1. Add an authenticated approve/reject API for `agent_action_approvals`.
2. Execute only supported action types through Laravel services.
3. Keep cross-company approvals impossible.
4. Make pending-only state transitions explicit and conflict-safe.
5. Persist audit metadata for approval, rejection, execution, and failure.
6. Update Rex UI flows to call approval endpoints when an action has an `approval_id`.
7. Preserve all existing guardrails against autonomous agent mutations.

## Non-Goals

- Do not let the agent approve, reject, or execute its own actions.
- Do not add autonomous writes.
- Do not create a generic arbitrary action runner.
- Do not execute unsupported `action_type` values.
- Do not replace alert recommendation or case recommendation review services.
- Do not expand LangGraph nodes in this phase.
- Do not migrate every legacy `RexPendingAction` flow unless it directly interacts with persisted agent approvals.

## API Contract

Add or formalize:

```text
POST /api/agent-approvals/{id}/approve
POST /api/agent-approvals/{id}/reject
```

Both endpoints require `auth:sanctum`.

### Approve Response

```json
{
  "approval_id": "uuid",
  "status": "approved",
  "executed_at": "2026-05-24T00:00:00Z",
  "result": {
    "resource_type": "alert",
    "resource_id": "uuid"
  }
}
```

### Reject Response

```json
{
  "approval_id": "uuid",
  "status": "rejected"
}
```

### Error Responses

| Case | Status | Behavior |
| --- | --- | --- |
| Unauthenticated | `401` | No lookup, no mutation. |
| User has no company | `403` | Safe error. |
| Approval belongs to another company | `404` | Hide existence. |
| Approval is not pending | `409` | No mutation. |
| Unsupported action type | `422` | No side effect. |
| Supported action execution fails | `422` | Store `failed_at` and `error_message`; return safe error. |

## Execution Module

Use a small executor module, for example:

```text
App\Services\Agents\AgentActionExecutorService
```

Recommended public interface:

```php
execute(AgentActionApproval $approval, User $approver): AgentActionExecutionResult
supportedActionTypes(): array
```

The executor dispatches by `action_type`.

Initial supported action:

| Action type | Side effect | Result |
| --- | --- | --- |
| `create_alert` | Creates an alert for the approval company from validated payload fields. | `resource_type=alert`, `resource_id=<alert id>` |

Potential follow-up action:

| Action type | Side effect | Requirement before enabling |
| --- | --- | --- |
| `create_case` | Creates an audit case from alert/transaction IDs. | Must validate payload shape and reuse `CaseService::create`. |

Unsupported actions must fail closed before any side effect.

## Approval State Machine

Allowed transitions:

```text
pending -> approved
pending -> rejected
pending -> failed_execution
failed_execution -> pending_retry (future only, not Phase 3)
```

Phase 3 does not add retries. If execution fails, the approval record remains resolved for operational purposes unless a future retry flow is explicitly designed.

Recommended status handling:

- `pending`: user can approve or reject.
- `approved`: approved and executed successfully.
- `rejected`: user rejected; no side effect.
- `failed`: approval was attempted but execution failed.

If the existing schema keeps `status` as free text, Phase 3 should standardize these values in code and tests. A database check constraint can be added later if useful.

## Transaction And Audit Behavior

Approval execution should be wrapped in a database transaction:

1. Lock or re-read the approval as pending.
2. Validate company scope.
3. Validate action type and payload.
4. Execute deterministic side effect.
5. Update approval status and metadata.
6. Write audit event.

Audit metadata should include:

- `approval_id`
- `agent_run_id`
- `company_id`
- `approver_user_id`
- `action_type`
- `resource_type`
- `resource_id`
- `execution_status`
- safe payload summary

If a dedicated audit log table is not consistently available, use the closest existing audit/event pattern and structured Laravel logs as a bridge. The release gate should still require durable audit evidence for successful approval and rejection.

## Payload Contract

### `create_alert`

Accepted payload fields:

- `rule_key`
- `severity`
- `title`
- `detail`
- `evidence`

Validation rules:

- `severity` must be one of the alert severity values already supported by the product.
- `title` must be non-empty after fallback.
- `evidence` must be an array if present.
- `company_id` is never accepted from payload; it comes from the approval record.
- `status` is always set by Laravel, normally `open`.

Payload fields outside the supported contract are ignored or stored only inside safe evidence metadata. They must not alter tenant, user, approval, or execution behavior.

## Frontend Rex Integration

Rex action cards should choose the endpoint by action identity:

- If action has `approval_id`, call `/api/agent-approvals/{approval_id}/approve` or `/reject`.
- If action is a legacy `RexPendingAction`, continue using `/api/chat/sessions/{sessionId}/actions/{actionId}/confirm` or `/reject`.

Standalone Rex should stop treating approval-backed actions as review-only. It should display:

- pending approval state
- approve/reject buttons
- successful created-resource state
- rejection state
- safe failure message

The frontend should not construct side-effect payloads for persisted approvals. The payload already lives in `agent_action_approvals`.

## Relationship To Recommendation Review

Alert recommendations and case recommendations already have user-only approve/dismiss services.

Phase 3 does not bypass those services. If an agent recommends approving an existing recommendation later, the executor must call the relevant review service with actor type `user`, not create records directly.

For Phase 3, direct `create_alert` is allowed only because the `agent_action_approvals` record is already the approval object and the action type is explicitly supported.

## Safety Rules

- Agents cannot approve or reject.
- Cross-company approval lookup returns `404`.
- User identity comes from Sanctum auth, not payload.
- Company identity comes from authenticated user and approval record, not payload.
- Only `pending` approvals can transition.
- Unsupported action types return `422` before side effects.
- Execution errors write failure metadata and return a safe error.
- The executor owns side effects; controllers only coordinate request/response.

## Data Flow

```text
Agent service returns recommended_actions
  -> BrevixAgentRunner persists AgentActionApproval(status=pending)
  -> response includes approval_id
  -> Rex renders action card
  -> user clicks Approve or Reject
  -> POST /api/agent-approvals/{id}/approve|reject
  -> AgentApprovalController checks auth, company scope, pending state
  -> AgentActionExecutorService executes supported action
  -> approval status/timestamps and audit event are persisted
  -> frontend updates action card state
```

## Test Plan

### Backend Feature Tests

- Approve requires authentication.
- Reject requires authentication.
- Approve returns `404` for cross-company approval.
- Reject returns `404` for cross-company approval.
- Approve creates alert and sets `approved_by`, `approved_at`, `executed_at`, and `status=approved`.
- Reject sets `rejected_by`, `rejected_at`, and `status=rejected`; no alert is created.
- Approve after approval returns `409`.
- Reject after rejection returns `409`.
- Approve after rejection returns `409`.
- Unsupported action type returns `422` and creates no side effect.
- Execution failure writes `failed_at` and `error_message`.
- Approval response includes created resource metadata.

### Backend Unit Tests

- Executor supports only declared action types.
- `create_alert` payload validation ignores tenant/user fields from payload.
- Executor returns a typed result for created resources.
- Audit event payload includes approval and resource identifiers.

### Frontend Tests

- Approval-backed Rex action calls `/api/agent-approvals/{approval_id}/approve`.
- Approval-backed Rex rejection calls `/api/agent-approvals/{approval_id}/reject`.
- Legacy local Rex action still calls session action endpoints.
- Successful approval shows executed state and resource metadata.
- Failed approval shows safe error and keeps the UI consistent.

### Contract Tests

- Every action type persisted by `BrevixAgentRunner` is supported by the executor or explicitly blocked.
- Every executor-supported action type appears in process registry approval metadata after Phase 2.
- No user-only workflow is executable through agent approval.

## Release Gate

Phase 3 is complete when:

1. Approval and rejection endpoints are authenticated and company scoped.
2. `create_alert` executes end-to-end from pending approval to created alert.
3. Unsupported action types fail closed.
4. Double approval/rejection returns conflict.
5. Execution failure is persisted safely.
6. Rex UI uses `approval_id` for persisted actions.
7. Audit evidence exists for approval, rejection, execution, and failure.
8. Existing agent chat and Rex chat tests still pass.

## Follow-On Phases

Phase 4 should expand the agent service carefully:

- Teach LangGraph to consume registry-derived tool metadata.
- Add only high-value graph nodes such as `transaction_lookup`, `dashboard_health`, `recommendation_review`, and possibly `investigation_synthesis`.
- Add degraded-tool reporting and trace IDs across Laravel and the agent service.

Phase 5 should add release gates for route parity, process registry parity, agent tool parity, Rex artifact contracts, and approval contracts in CI.
