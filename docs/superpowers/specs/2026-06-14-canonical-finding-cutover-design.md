# Canonical Finding Cutover Design

Date: 2026-06-14
Status: Approved for implementation

## Purpose

Make canonical findings the source of truth for the redesign path across `brevixai`, `brevixai-api`, and `brevixai-agents`.

The target workflow is:

Agent or source analyzer finding -> canonical API finding -> evidence and suggested records -> frontend Findings queue -> reviewer opens investigation -> evidence and suggested records attach to the investigation.

Legacy alerts remain available for compatibility, but the redesigned path should not depend on alerts.

## Decision

Use a canonical-first cutover.

- `brevixai-api` persists agent/source findings into canonical `findings`, `evidence_items`, and `suggested_records`.
- `brevixai-agents` continues posting findings through a stable internal tool client method, but its payload is normalized for canonical persistence.
- `brevixai` reads the canonical `/api/findings` endpoint for the Findings page and opens investigations through `/api/findings/{id}/create-investigation`.
- Legacy `/api/alerts` and case routes remain compatibility surfaces during migration.

## API Scope

### Internal Agent Findings

`POST /api/internal/agent-tools/company/{companyId}/findings` should materialize canonical findings instead of creating legacy alert rows.

The endpoint should:

- authorize the user and business profile context using the existing agent-tool context flow;
- accept existing agent finding payloads where possible;
- derive stable source keys:
  - `sourceModule`: `rex_agent`
  - `sourceRecordType`: agent finding type, risk type, or `agent_finding`
  - `sourceRecordId`: explicit source id when present, otherwise a stable hash from agent run and finding title;
- map severity and confidence into the canonical finding contract;
- normalize evidence into evidence item projections;
- normalize recommended next records into suggested record projections;
- call `SourceFindingMaterializationService::materializePayload()`;
- return the materialized canonical finding ids and counts.

It should not dual-write alerts in this slice.

### Public Findings

The existing canonical public finding endpoints remain the frontend target:

- `GET /api/findings`
- `POST /api/findings/materialize`
- `POST /api/findings/{id}/create-investigation`

Tests should prove that an agent-created canonical finding can open an investigation and carry previously standalone evidence and suggested records into that investigation.

## Agents Scope

`LaravelToolClient.store_findings()` remains the agent-side integration point.

The agent service should:

- keep posting to `/api/internal/agent-tools/company/{companyId}/findings`;
- include `agent_run_id`;
- preserve finding title, summary, severity, confidence, and evidence;
- include canonical-friendly fields when available, such as `sourceModule`, `sourceRecordType`, `sourceRecordId`, `reasonCode`, `recommendedAction`, `suggestedRecords`, and `limitations`.

Add a focused test that verifies the client posts the expected canonical-friendly payload shape.

## Frontend Scope

The Findings page should switch its primary data source from `/api/alerts` to `/api/findings`.

The page should:

- render canonical `InvestigationFinding` records directly;
- preserve the current visual layout and filters as much as possible;
- open investigations through `/api/findings/{id}/create-investigation`;
- navigate to the canonical investigation workspace after creation;
- keep alert fallback only for migration safety if canonical findings fail to load.

No visual redesign is included in this slice.

## Error Handling

- Missing workspace or business profile context should keep returning the existing safe JSON authorization response.
- Canonical table absence should return a safe service error rather than writing alerts.
- Invalid or cross-tenant investigation/finding ids must return `404` or `422`.
- Raw source rows, raw notices, and sensitive analyzer payloads must not be embedded in normalized responses.
- Agent persistence failures should remain non-fatal to the chat response, but should be logged with the agent run id.

## Testing

API:

- agent findings endpoint materializes canonical rows;
- materialization is idempotent for repeated agent findings;
- evidence and suggested records are created without an investigation;
- opening an investigation attaches those evidence and suggested records;
- endpoint does not create legacy alert rows for the canonical path.

Agents:

- `store_findings()` posts canonical-friendly fields and `agent_run_id`;
- empty findings short-circuit without an HTTP call.

Frontend:

- Findings page reads `/api/findings`;
- canonical findings render in the queue and detail drawer;
- open investigation calls `/api/findings/{id}/create-investigation`;
- alert fallback remains covered for migration.

## Acceptance Criteria

- A Rex-generated finding appears as a canonical finding in the frontend Findings queue.
- The reviewer can open an investigation from that finding.
- Evidence and suggested records attached to the finding become part of the investigation.
- The old alerts surface is not required for this happy path.
- Targeted API, agents, and frontend tests pass.
