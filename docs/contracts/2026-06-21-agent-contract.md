# Brevix Agent Execution Contract
**Date:** 2026-06-21
**Status:** Approved Phase 3 Baseline

This document specifies the exact JSON structures required for communication between the Laravel backend (`brevixai-api`) and the Python agent orchestration service (`brevixai-agents`).

## 1. Request Payload (Laravel -> Agent)
The agent service accepts requests with the following schema:

```json
{
  "agent_run_id": "uuid",
  "company_id": "uuid",
  "business_profile_id": "uuid | null",
  "user_id": "uuid",
  "conversation_id": "uuid | null",
  "message": "string",
  "conversation_history": [
    {
      "role": "user | assistant | system",
      "content": "string"
    }
  ],
  "requested_action": "string (e.g. 'risk_review')",
  "max_response_size": 4000,
  "page_context": {},
  "optional_deterministic_tools": {
    "tool_key": {
      "method": "GET",
      "path": "/api/internal/agent-tools/...",
      "purpose": "string",
      "optional": "boolean"
    }
  },
  "tool_policy": {
    "database_access": "forbidden",
    "autonomous_actions": "forbidden",
    "alert_creation": "recommendation_only",
    "mutating_tools": "forbidden"
  }
}
```

## 2. Response Payload (Agent -> Laravel)
The agent service streams or returns a final aggregated payload shaped as follows:

```json
{
  "message": "string",
  "intent": "string",
  "findings": [
    {
      "id": "string",
      "title": "string",
      "severity": "info|low|medium|high|critical|warning",
      "summary": "string"
    }
  ],
  "recommended_actions": [
    {
      "type": "create_investigation | flag_transaction | escalate_review",
      "requires_approval": true,
      "payload": {}
    }
  ],
  "degraded_tools": [
    {
      "tool": "string",
      "error_class": "string",
      "message": "string",
      "affected_confidence": true
    }
  ],
  "steps": [
    {
      "step_name": "string",
      "step_type": "string",
      "status": "completed | failed | degraded",
      "input_payload": {},
      "output_payload": {}
    }
  ]
}
```

## 3. Semantics and Observability

### Advertised Action Types
All `recommended_actions` emitted by the agent must correspond to a supported executable action in Laravel. Currently these are explicitly constrained to:
- `create_investigation`
- `flag_transaction`
- `escalate_review`

Informational discovery workflows (e.g., `review_dashboard`, `review_findings`) must not be emitted as executable actions requiring human approval. They are either handled by frontend navigation or basic tool reading.

### Tool Degradation
If the agent fails to reach a requested tool, or the tool responds with an error, the agent must NOT fail the entire response unless the tool was explicitly marked `optional: false` and there is no viable fallback path.

When a tool is unavailable, the agent will:
1. Complete the investigative response using remaining available context.
2. Embed the error in `degraded_tools`.

Laravel's `BrevixAgentRunner` will parse the `degraded_tools` array and persist it into the database trace. For the MVP, this degradation is logged and attached to the trace API, avoiding silent failures without actively blocking the user from seeing their findings.
