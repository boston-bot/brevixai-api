# Agent Process Readiness

Date: 2026-06-05

This document tracks whether each Brevix platform surface can be handled through deterministic backend processes or the controlled agent service, instead of asking the LLM to do business logic.

## Current Control Plane

Production Rex flow is:

```text
brevixai frontend
  -> brevixai-api Laravel chat gateway
  -> deterministic Rex router when route is obvious
  -> either RexOrchestratorService for read-only product lookups
  -> or brevixai-agents FastAPI/LangGraph for risk-review workflows
  -> Laravel internal agent-tool endpoints
  -> persisted agent_action_approvals for sensitive writes
  -> frontend user approval via /api/agent-approvals/{approval}/approve|reject
```

The older Python orchestrator inside the frontend repo is not the active production path.

## Process Coverage

| Platform surface | User API ready | Deterministic Rex orchestrator | LangGraph agent tool/process | Current status |
| --- | --- | --- | --- | --- |
| Dashboard / overview | Yes | Yes: `dashboard` | Partial: `dashboard_health` context path | Ready for read-only Rex use |
| Analytics / cash flow / vendors | Yes | Yes: `analytics`, `vendors` | Partial: vendor risk only | Read-only ready; deep analytics agent coverage is partial |
| Alerts | Yes | Yes: `alerts` | Partial: risk-review findings and alert recommendations | Read/list ready; persisted user-approved alert execution is wired through approval IDs |
| Alert recommendations | Yes | Yes: `alert_recommendations` | Exposed as deterministic internal tool | Ready as deterministic recommendation process |
| Case recommendations | Yes | Yes: `case_recommendations` | Exposed as deterministic internal tool | Ready as deterministic recommendation process |
| Cases | Yes | Yes: `cases` | Indirect through case recommendations | Read-only ready; case mutations remain user-driven |
| Investigations | Yes | No | No direct agent write access by design | User workflow ready; agent can only recommend upstream actions |
| Transactions | Yes | Yes: `transactions` | Partial: bounded transaction lookup through company context | Read-only ready; agent analysis limited to bounded summaries |
| Reconciliation | Yes | Yes: `reconciliation` | Yes: `reconciliation_risk` | Read/risk ready; run workflow is not exposed as an agent process |
| AR aging | Yes | Yes: `ar` | No dedicated agent process | Read-only ready through Laravel orchestrator |
| Controls | Yes | Yes: `controls` | No dedicated agent process | Read-only ready through Laravel orchestrator |
| Uploads | Yes | No | No | User workflow only; should not be LLM-driven |
| QuickBooks / GnuCash integrations | Yes | No | No | User workflow only; should not be LLM-driven |
| Subscriptions / auth / onboarding | Yes | No | No | User workflow only |
| Personal finance | Yes, gated local/admin | No | No | Not in Rex/agent production scope |

## Internal Agent Tool Surface

Laravel advertises these deterministic tools to the agent service:

- `company_context`
- `risk_summary`
- `vendor_risk`
- `reconciliation_risk`
- `entity_relationship_risk`
- `aggregate_risk_summary`
- `alert_recommendations`
- `case_recommendations`

Tool policy remains:

- No direct database access from the agent service.
- No autonomous mutations.
- Alert creation is recommendation-only.
- Human approval is required for sensitive action execution.
- Sensitive recommendations produce persisted `agent_action_approvals`; the standalone Rex UI now executes approval-backed actions only through the persisted approve/reject endpoints.

## Latest Verification

Frontend Phase 3 approval integration passed on 2026-06-05:

- `npm run typecheck` in `brevixai`: passed.
- `npm test -- --runInBand --watchman=false` in `brevixai`: passed, 14 suites / 156 tests.
- `npm run test:e2e -- e2e/rex-layout.test.ts` in `brevixai`: passed, 3 Playwright tests covering Rex layout plus persisted approval success/failure flows.

## Remaining Gaps

1. The LangGraph service still has one primary workflow: risk review. It can call deterministic risk tools, but it is not a complete per-feature agent process registry for every site feature.
2. The agent service request model currently treats Laravel-advertised tool metadata as compatibility payload. The active graph still uses its hard-coded Laravel tool client.
3. Rex session chat and standalone Rex workspace use different paths. Session chat streams through `/api/chat/sessions/{sessionId}/messages`; standalone Rex posts to `/api/chat/agent/messages`.
4. Several frontend pages still reference API routes that are not in the Laravel route list, including legacy reports, legal escalation, entity graph, clients, alert create-case/dismiss-pattern, transaction create-case, reconciliation run, and upload row errors.

## Readiness Bar

A feature should be considered agent-process ready only when all of these are true:

1. The deterministic Laravel service owns the facts and mutations.
2. The Rex router can select the process without a router LLM call for obvious requests.
3. The LangGraph service can call only approved Laravel tools for that process.
4. Any write action is represented as a pending human approval and cannot execute autonomously.
5. Frontend routes, backend routes, tests, and audit records use the same contract.
